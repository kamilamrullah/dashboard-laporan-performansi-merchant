<?php
declare(strict_types=1);

namespace App\Report;

use DateTimeImmutable;
use RuntimeException;

/** Menyusun data ringkasan laporan dari hasil agregasi repository. */
final class ReportSummaryService
{
    private const MONTHS = [1 => 'JANUARI', 'FEBRUARI', 'MARET', 'APRIL', 'MEI', 'JUNI', 'JULI', 'AGUSTUS', 'SEPTEMBER', 'OKTOBER', 'NOVEMBER', 'DESEMBER'];

    /** Menerima repository sumber data agar aturan ringkasan tidak berada di controller. */
    public function __construct(private readonly ReportDataRepository $repository) {}

    /** Membentuk baris channel dan grand total untuk satu merchant pada satu bulan. */
    public function summarize(int $merchantId, string $reportPeriod): array
    {
        if ($merchantId < 1) throw new RuntimeException('Merchant laporan tidak valid.');
        $period = DateTimeImmutable::createFromFormat('!Y-m-d', $reportPeriod);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$period || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $period->format('d') !== '01') throw new RuntimeException('Periode ringkasan harus berupa tanggal pertama bulan.');
        $rows = array_map(static fn (array $row): array => [
            'partner_channel' => trim((string) $row['partner_channel']),
            'inquiry_success' => (int) $row['inquiry_success'],
            'payment_success' => (int) $row['payment_success'],
            'payment_amount' => (float) $row['payment_amount'],
        ], $this->repository->successfulTransactionsByPartnerChannel($merchantId, $period->format('Y-m-d'), $period->modify('first day of next month')->format('Y-m-d')));
        $totals = ['inquiry_success' => 0, 'payment_success' => 0, 'payment_amount' => 0.0];
        foreach ($rows as $row) {
            $totals['inquiry_success'] += $row['inquiry_success'];
            $totals['payment_success'] += $row['payment_success'];
            $totals['payment_amount'] += $row['payment_amount'];
        }
        $previousPeriod = $period->modify('first day of previous month');
        $previousRows = $this->repository->successfulTransactionsByPartnerChannel($merchantId, $previousPeriod->format('Y-m-d'), $period->format('Y-m-d'));
        $previousPayment = array_sum(array_map(static fn (array $row): int => (int) $row['payment_success'], $previousRows));
        $difference = $totals['payment_success'] - $previousPayment;
        $performanceRows = array_map(static function (array $row): array {
            $success = (int) $row['rc_0'];
            $rc68 = (int) $row['rc_68'];
            $rc82 = (int) $row['rc_82'];
            $total = $success + $rc68 + $rc82;
            return [
                'partner_channel' => trim((string) $row['partner_channel']),
                'success' => $success,
                'rc_68' => $rc68,
                'rc_82' => $rc82,
                'total' => $total,
                'success_rate' => $total > 0 ? ($success / $total) * 100 : 0.0,
            ];
        }, $this->repository->paymentPerformanceByPartnerChannel($merchantId, $period->format('Y-m-d'), $period->modify('first day of next month')->format('Y-m-d')));
        $performanceTotals = ['success' => 0, 'rc_68' => 0, 'rc_82' => 0, 'total' => 0];
        foreach ($performanceRows as $row) {
            foreach (array_keys($performanceTotals) as $key) $performanceTotals[$key] += $row[$key];
        }
        $performanceTotals['success_rate'] = $performanceTotals['total'] > 0 ? ($performanceTotals['success'] / $performanceTotals['total']) * 100 : 0.0;
        $paymentChannelRows = array_map(static function (array $row): array {
            $success = (int) $row['rc_0'];
            $rc68 = (int) $row['rc_68'];
            $rc82 = (int) $row['rc_82'];
            $total = $success + $rc68 + $rc82;
            return [
                'payment_channel' => trim((string) $row['payment_channel']),
                'success' => $success,
                'rc_68' => $rc68,
                'rc_82' => $rc82,
                'total' => $total,
                'success_rate' => $total > 0 ? ($success / $total) * 100 : 0.0,
            ];
        }, $this->repository->paymentPerformanceByPaymentChannel($merchantId, $period->format('Y-m-d'), $period->modify('first day of next month')->format('Y-m-d')));
        $paymentChannelTotals = ['success' => 0, 'rc_68' => 0, 'rc_82' => 0, 'total' => 0];
        foreach ($paymentChannelRows as $row) {
            foreach (array_keys($paymentChannelTotals) as $key) $paymentChannelTotals[$key] += $row[$key];
        }
        $paymentChannelTotals['success_rate'] = $paymentChannelTotals['total'] > 0 ? ($paymentChannelTotals['success'] / $paymentChannelTotals['total']) * 100 : 0.0;
        $topPaymentChannels = array_map(static function (array $row) use ($paymentChannelTotals): array {
            return [
                'payment_channel' => $row['payment_channel'],
                'total' => $row['success'],
                'percentage' => $paymentChannelTotals['success'] > 0 ? ($row['success'] / $paymentChannelTotals['success']) * 100 : 0.0,
            ];
        }, array_slice(array_values(array_filter($paymentChannelRows, static fn (array $row): bool => $row['success'] > 0)), 0, 5));
        $dailySource = [];
        foreach ($this->repository->dailyPaymentTrend($merchantId, $period->format('Y-m-d'), $period->modify('first day of next month')->format('Y-m-d')) as $row) $dailySource[(string) $row['transaction_date']] = $row;
        $dailyRows = [];
        for ($date = $period; $date < $period->modify('first day of next month'); $date = $date->modify('+1 day')) {
            $source = $dailySource[$date->format('Y-m-d')] ?? ['rc_0' => 0, 'rc_68' => 0, 'rc_82' => 0];
            $success = (int) $source['rc_0'];
            $rc68 = (int) $source['rc_68'];
            $rc82 = (int) $source['rc_82'];
            $total = $success + $rc68 + $rc82;
            $dailyRows[] = ['date' => $date->format('Y-m-d'), 'success' => $success, 'rc_68' => $rc68, 'rc_82' => $rc82, 'success_rate' => $total > 0 ? ($success / $total) * 100 : 0.0];
        }
        $highest = $this->dailyExtreme($dailyRows, true);
        $lowest = $this->dailyExtreme($dailyRows, false);
        $largestIncrease = ['from_date' => null, 'to_date' => null, 'difference' => 0];
        for ($index = 1; $index < count($dailyRows); $index++) {
            $increase = $dailyRows[$index]['success'] - $dailyRows[$index - 1]['success'];
            if ($increase > $largestIncrease['difference']) $largestIncrease = ['from_date' => $dailyRows[$index - 1]['date'], 'to_date' => $dailyRows[$index]['date'], 'difference' => $increase];
        }
        $dailyTotal = array_sum(array_column($dailyRows, 'success'));
        $dailyTrend = ['rows' => $dailyRows, 'metrics' => ['highest' => $highest, 'lowest' => $lowest, 'largest_increase' => $largestIncrease, 'average_success' => count($dailyRows) > 0 ? (int) round($dailyTotal / count($dailyRows)) : 0, 'total_success' => $dailyTotal]];
        $ticketSource = $this->repository->complaintTicketSummary($merchantId, $period->format('Y-m-d'), $period->modify('first day of next month')->format('Y-m-d'));
        $ticketSegments = array_map(static fn (array $row): array => ['complaint_segment' => (string) $row['complaint_segment'], 'total' => (int) $row['total']], $ticketSource['segments']);
        $ticketStatuses = array_map(static fn (array $row): array => ['status' => (string) $row['status'], 'total' => (int) $row['total']], $ticketSource['statuses']);
        $ticketSummary = ['segments' => $ticketSegments, 'statuses' => $ticketStatuses, 'total' => array_sum(array_column($ticketSegments, 'total'))];
        $ticketDetails = array_map(static fn (array $row): array => [
            'complaint_segment' => trim((string) $row['complaint_segment']),
            'opened_at' => (string) $row['opened_at'],
            'closed_at' => $row['closed_at'] === null ? null : (string) $row['closed_at'],
            'duration_raw' => $row['duration_raw'] === null ? null : trim((string) $row['duration_raw']),
            'duration_minutes' => $row['duration_minutes'] === null ? null : (int) $row['duration_minutes'],
            'response_time_minutes' => $row['response_time_minutes'] === null ? null : (int) $row['response_time_minutes'],
            'total' => 1,
        ], $this->repository->complaintTicketDetails($merchantId, $period->format('Y-m-d'), $period->modify('first day of next month')->format('Y-m-d')));
        return ['rows' => $rows, 'totals' => $totals, 'metrics' => [
            'top_inquiry' => $this->topChannel($rows, 'inquiry_success'),
            'top_payment' => $this->topChannel($rows, 'payment_success'),
            'top_payment_amount' => $this->topChannel($rows, 'payment_amount'),
            'payment_to_inquiry_percentage' => $totals['inquiry_success'] > 0 ? ($totals['payment_success'] / $totals['inquiry_success']) * 100 : 0.0,
            'payment_comparison' => [
                'current_label' => $this->periodLabel($period),
                'current_total' => $totals['payment_success'],
                'previous_label' => $this->periodLabel($previousPeriod),
                'previous_total' => $previousPayment,
                'difference' => $difference,
                'percentage_change' => $previousPayment > 0 ? ($difference / $previousPayment) * 100 : null,
                'direction' => $difference > 0 ? 'increase' : ($difference < 0 ? 'decrease' : 'stable'),
            ],
        ], 'performance' => ['rows' => $performanceRows, 'totals' => $performanceTotals], 'payment_channel_performance' => ['rows' => $paymentChannelRows, 'totals' => $paymentChannelTotals], 'top_payment_channels' => $topPaymentChannels, 'daily_trend' => $dailyTrend, 'ticket_summary' => $ticketSummary, 'ticket_details' => $ticketDetails];
    }

    /** Memilih channel dengan nilai tertinggi dan memakai nama alfabetis untuk memecahkan nilai seri. */
    private function topChannel(array $rows, string $metric): ?array
    {
        $eligible = array_values(array_filter($rows, static fn (array $row): bool => $row[$metric] > 0));
        if ($eligible === []) return null;
        usort($eligible, static function (array $left, array $right) use ($metric): int {
            $valueComparison = $right[$metric] <=> $left[$metric];
            return $valueComparison !== 0 ? $valueComparison : strcmp($left['partner_channel'], $right['partner_channel']);
        });
        return ['partner_channel' => $eligible[0]['partner_channel'], 'total' => $eligible[0][$metric]];
    }

    /** Memilih tanggal dengan transaksi sukses tertinggi atau terendah dan memakai tanggal paling awal saat seri. */
    private function dailyExtreme(array $rows, bool $highest): ?array
    {
        if ($rows === []) return null;
        $selected = $rows[0];
        foreach ($rows as $row) {
            if (($highest && $row['success'] > $selected['success']) || (!$highest && $row['success'] < $selected['success'])) $selected = $row;
        }
        return ['date' => $selected['date'], 'total' => $selected['success']];
    }

    /** Membentuk label periode dengan nama bulan Indonesia untuk narasi dan grafik. */
    private function periodLabel(DateTimeImmutable $period): string
    {
        return self::MONTHS[(int) $period->format('n')] . ' ' . $period->format('Y');
    }
}
