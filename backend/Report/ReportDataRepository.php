<?php
declare(strict_types=1);

namespace App\Report;

use PDO;

/** Menyediakan query terparameterisasi untuk seluruh sumber data laporan performansi. */
final class ReportDataRepository
{
    /** Menerima koneksi database yang digunakan untuk membaca snapshot data laporan. */
    public function __construct(private readonly PDO $database) {}

    /** Mengambil agregasi transaksi sukses per partner channel pada satu periode bulanan. */
    public function successfulTransactionsByPartnerChannel(int $merchantId, string $periodStart, string $periodEnd): array
    {
        $successRule = "EXISTS (
            SELECT 1 FROM response_code_rules rules
            WHERE rules.response_code = t.response_code
              AND rules.status_group = 'SUCCESS'
              AND rules.is_active = 1
              AND (rules.transaction_type = '' OR rules.transaction_type = t.transaction_type)
              AND rules.effective_from <= t.transaction_date
              AND (rules.effective_until IS NULL OR rules.effective_until >= t.transaction_date)
        )";
        $statement = $this->database->prepare(
            "SELECT t.partner_channel,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'INQUIRY' AND {$successRule} THEN t.total_trx ELSE 0 END), 0) inquiry_success,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successRule} THEN t.total_trx ELSE 0 END), 0) payment_success,
                    COALESCE(SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successRule} THEN t.total_amount ELSE 0 END), 0) payment_amount
             FROM transaction_aggregates t
             WHERE t.merchant_id = :merchant_id
               AND t.transaction_date >= :period_start
               AND t.transaction_date < :period_end
             GROUP BY t.partner_channel
             HAVING inquiry_success > 0 OR payment_success > 0 OR payment_amount > 0
             ORDER BY payment_success DESC, inquiry_success DESC, t.partner_channel ASC"
        );
        $statement->execute(['merchant_id' => $merchantId, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
        return $statement->fetchAll();
    }

    /** Mengambil jumlah payment untuk RC 0, 68, dan 82 per partner channel sesuai struktur tabel template laporan. */
    public function paymentPerformanceByPartnerChannel(int $merchantId, string $periodStart, string $periodEnd): array
    {
        $statement = $this->database->prepare(
            "SELECT t.partner_channel,
                    COALESCE(SUM(CASE WHEN t.response_code = '0' THEN t.total_trx ELSE 0 END), 0) rc_0,
                    COALESCE(SUM(CASE WHEN t.response_code = '68' THEN t.total_trx ELSE 0 END), 0) rc_68,
                    COALESCE(SUM(CASE WHEN t.response_code = '82' THEN t.total_trx ELSE 0 END), 0) rc_82
             FROM transaction_aggregates t
             WHERE t.merchant_id = :merchant_id
               AND t.transaction_type = 'PAYMENT'
               AND t.transaction_date >= :period_start
               AND t.transaction_date < :period_end
               AND t.response_code IN ('0', '68', '82')
             GROUP BY t.partner_channel
             HAVING rc_0 > 0 OR rc_68 > 0 OR rc_82 > 0
             ORDER BY rc_0 DESC, t.partner_channel ASC"
        );
        $statement->execute(['merchant_id' => $merchantId, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
        return $statement->fetchAll();
    }

    /** Mengambil jumlah payment RC 0, 68, dan 82 per payment channel hasil mapping SIC_CODE sesuai tabel template. */
    public function paymentPerformanceByPaymentChannel(int $merchantId, string $periodStart, string $periodEnd): array
    {
        $statement = $this->database->prepare(
            "SELECT COALESCE(pc.channel_name, CONCAT('SIC ', t.sic_code)) payment_channel,
                    COALESCE(SUM(CASE WHEN t.response_code = '0' THEN t.total_trx ELSE 0 END), 0) rc_0,
                    COALESCE(SUM(CASE WHEN t.response_code = '68' THEN t.total_trx ELSE 0 END), 0) rc_68,
                    COALESCE(SUM(CASE WHEN t.response_code = '82' THEN t.total_trx ELSE 0 END), 0) rc_82
             FROM transaction_aggregates t
             LEFT JOIN payment_channels pc ON pc.sic_code = t.sic_code
             WHERE t.merchant_id = :merchant_id
               AND t.transaction_type = 'PAYMENT'
               AND t.transaction_date >= :period_start
               AND t.transaction_date < :period_end
               AND t.response_code IN ('0', '68', '82')
             GROUP BY t.sic_code, pc.channel_name
             HAVING rc_0 > 0 OR rc_68 > 0 OR rc_82 > 0
             ORDER BY rc_0 DESC, payment_channel ASC"
        );
        $statement->execute(['merchant_id' => $merchantId, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
        return $statement->fetchAll();
    }

    /** Mengambil transaksi payment harian untuk RC 0, 68, dan 82 sebagai sumber grafik serta tabel tren. */
    public function dailyPaymentTrend(int $merchantId, string $periodStart, string $periodEnd): array
    {
        $statement = $this->database->prepare(
            "SELECT t.transaction_date,
                    COALESCE(SUM(CASE WHEN t.response_code = '0' THEN t.total_trx ELSE 0 END), 0) rc_0,
                    COALESCE(SUM(CASE WHEN t.response_code = '68' THEN t.total_trx ELSE 0 END), 0) rc_68,
                    COALESCE(SUM(CASE WHEN t.response_code = '82' THEN t.total_trx ELSE 0 END), 0) rc_82
             FROM transaction_aggregates t
             WHERE t.merchant_id = :merchant_id
               AND t.transaction_type = 'PAYMENT'
               AND t.transaction_date >= :period_start
               AND t.transaction_date < :period_end
               AND t.response_code IN ('0', '68', '82')
             GROUP BY t.transaction_date
             ORDER BY t.transaction_date ASC"
        );
        $statement->execute(['merchant_id' => $merchantId, 'period_start' => $periodStart, 'period_end' => $periodEnd]);
        return $statement->fetchAll();
    }

    /** Mengambil jumlah Ticket No unik per segmentasi dan status berdasarkan Open Time periode laporan. */
    public function complaintTicketSummary(int $merchantId, string $periodStart, string $periodEnd): array
    {
        $segments = $this->database->prepare(
            "SELECT COALESCE(NULLIF(TRIM(complaint_segment), ''), 'Tanpa Segmentasi') complaint_segment, COUNT(DISTINCT ticket_number) total
             FROM complaint_tickets WHERE merchant_id = :merchant_id AND opened_at >= :period_start AND opened_at < :period_end
             GROUP BY complaint_segment ORDER BY complaint_segment ASC"
        );
        $statuses = $this->database->prepare(
            "SELECT COALESCE(NULLIF(TRIM(status), ''), 'Tanpa Status') status, COUNT(DISTINCT ticket_number) total
             FROM complaint_tickets WHERE merchant_id = :merchant_id AND opened_at >= :period_start AND opened_at < :period_end
             GROUP BY status ORDER BY status ASC"
        );
        $parameters = ['merchant_id' => $merchantId, 'period_start' => $periodStart, 'period_end' => $periodEnd];
        $segments->execute($parameters);
        $statuses->execute($parameters);
        return ['segments' => $segments->fetchAll(), 'statuses' => $statuses->fetchAll()];
    }
}
