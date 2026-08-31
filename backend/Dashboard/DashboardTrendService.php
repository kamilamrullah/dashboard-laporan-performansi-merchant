<?php
declare(strict_types=1);

namespace App\Dashboard;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

/** Menyediakan agregasi tren bulanan dan drill-down harian dengan aturan klasifikasi yang sama. */
final class DashboardTrendService
{
    public function __construct(private readonly PDO $database) {}

    /** Menghasilkan tren satu tahun kalender atau detail harian untuk satu periode yang dipilih. */
    public function trend(string $periodValue, string $granularity, array $filters = []): array
    {
        $period = $this->period($periodValue);
        if (!in_array($granularity, ['monthly', 'daily'], true)) throw new InvalidArgumentException('Granularitas tren harus monthly atau daily.');
        $start = $granularity === 'monthly' ? $period->setDate((int) $period->format('Y'), 1, 1) : $period;
        $end = $granularity === 'monthly' ? $start->modify('+1 year') : $period->modify('first day of next month');
        $this->createSnapshot($start, $end, $filters);
        return [
            'granularity' => $granularity,
            'selected_period' => $period->format('Y-m'),
            'range' => ['date_from' => $start->format('Y-m-d'), 'date_to' => $end->modify('-1 day')->format('Y-m-d')],
            'rows' => $granularity === 'monthly'
                ? $this->monthlyRows($start, $end)
                : $this->dailyRows($start, $end),
        ];
    }

    /** Memvalidasi periode kalender bulanan dan menormalisasikannya ke tanggal pertama. */
    private function period(string $value): DateTimeImmutable
    {
        $period = DateTimeImmutable::createFromFormat('!Y-m', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$period || $period->format('Y-m') !== $value || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException('Periode tren harus berformat YYYY-MM.');
        }
        return $period;
    }

    /** Membuat snapshot transaksi untuk rentang tren dan filter domain yang sudah divalidasi. */
    private function createSnapshot(DateTimeImmutable $start, DateTimeImmutable $end, array $filters): void
    {
        [$where, $parameters] = $this->where($start, $end, $filters);
        $this->database->exec('DROP TEMPORARY TABLE IF EXISTS dashboard_trend_transactions');
        $statement = $this->database->prepare(
            "CREATE TEMPORARY TABLE dashboard_trend_transactions ENGINE=InnoDB AS
             SELECT t.transaction_date, t.transaction_type, t.total_trx, t.total_amount,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM response_code_rules rules
                        WHERE rules.response_code = t.response_code
                          AND rules.status_group = 'SUCCESS' AND rules.is_active = 1
                          AND (rules.transaction_type = '' OR rules.transaction_type = t.transaction_type)
                          AND rules.effective_from <= t.transaction_date
                          AND (rules.effective_until IS NULL OR rules.effective_until >= t.transaction_date)
                    ) THEN 1 ELSE 0 END is_success
             FROM transaction_aggregates t
             LEFT JOIN payment_channels pc ON pc.sic_code = t.sic_code
             WHERE {$where}"
        );
        $statement->execute($parameters);
    }

    /** Menyusun kondisi tren menggunakan prepared parameter untuk seluruh filter pengguna. */
    private function where(DateTimeImmutable $start, DateTimeImmutable $end, array $filters): array
    {
        $conditions = ['t.transaction_date >= :date_from', 't.transaction_date < :date_to'];
        $parameters = ['date_from' => $start->format('Y-m-d'), 'date_to' => $end->format('Y-m-d')];
        if (isset($filters['merchant_id'])) {
            $merchantId = filter_var($filters['merchant_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($merchantId === false) throw new InvalidArgumentException('Merchant tren tidak valid.');
            $conditions[] = 't.merchant_id = :merchant_id'; $parameters['merchant_id'] = (int) $merchantId;
        }
        $columns = [
            'partner_channel' => 't.partner_channel',
            'payment_channel' => "COALESCE(pc.channel_name, CONCAT('SIC ', t.sic_code))",
            'transaction_type' => 't.transaction_type',
            'response_code' => 't.response_code',
        ];
        foreach ($columns as $key => $column) {
            if (!isset($filters[$key]) || trim((string) $filters[$key]) === '') continue;
            $value = trim((string) $filters[$key]);
            if (mb_strlen($value) > 160) throw new InvalidArgumentException("Filter {$key} terlalu panjang.");
            $conditions[] = "{$column} = :{$key}"; $parameters[$key] = $value;
        }
        return [implode(' AND ', $conditions), $parameters];
    }

    /** Mengagregasikan Januari-Desember dan menandai bulan yang memang belum memiliki data. */
    private function monthlyRows(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->database->query(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m') period,
                    COALESCE(SUM(CASE WHEN transaction_type = 'INQUIRY' AND is_success = 1 THEN total_trx ELSE 0 END), 0) inquiry,
                    COALESCE(SUM(CASE WHEN transaction_type = 'PAYMENT' AND is_success = 1 THEN total_trx ELSE 0 END), 0) payment,
                    COALESCE(SUM(CASE WHEN transaction_type = 'PAYMENT' AND is_success = 1 THEN total_amount ELSE 0 END), 0) payment_amount
             FROM dashboard_trend_transactions GROUP BY DATE_FORMAT(transaction_date, '%Y-%m') ORDER BY period"
        );
        $indexedRows = [];
        foreach ($statement->fetchAll() as $row) {
            $row['has_data'] = true;
            $indexedRows[$row['period']] = $row;
        }
        $rows = [];
        for ($month = $start; $month < $end; $month = $month->modify('+1 month')) {
            $key = $month->format('Y-m');
            $rows[] = $indexedRows[$key] ?? ['period' => $key, 'inquiry' => null, 'payment' => null, 'payment_amount' => null, 'has_data' => false];
        }
        return $rows;
    }

    /** Mengagregasikan snapshot menjadi titik harian untuk bulan yang dipilih. */
    private function dailyRows(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->database->query(
            "SELECT DATE_FORMAT(transaction_date, '%Y-%m-%d') transaction_date,
                    COALESCE(SUM(CASE WHEN transaction_type = 'INQUIRY' AND is_success = 1 THEN total_trx ELSE 0 END), 0) inquiry,
                    COALESCE(SUM(CASE WHEN transaction_type = 'PAYMENT' AND is_success = 1 THEN total_trx ELSE 0 END), 0) payment,
                    COALESCE(SUM(CASE WHEN transaction_type = 'PAYMENT' AND is_success = 1 THEN total_amount ELSE 0 END), 0) payment_amount
             FROM dashboard_trend_transactions GROUP BY transaction_date ORDER BY transaction_date"
        );
        $indexedRows = [];
        foreach ($statement->fetchAll() as $row) $indexedRows[$row['transaction_date']] = $row;
        $rows = [];
        for ($day = $start; $day < $end; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $rows[] = $indexedRows[$key] ?? ['transaction_date' => $key, 'inquiry' => '0', 'payment' => '0', 'payment_amount' => '0.00'];
        }
        return $rows;
    }
}
