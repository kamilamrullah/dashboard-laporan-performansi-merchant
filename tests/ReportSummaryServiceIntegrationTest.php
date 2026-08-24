<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../backend/Report/ReportDataRepository.php';
require_once __DIR__ . '/../backend/Report/ReportSummaryService.php';

use App\Report\ReportDataRepository;
use App\Report\ReportSummaryService;

/** Menghentikan test ringkasan dengan pesan yang menjelaskan ketidaksesuaian data. */
function assert_report_summary(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = database_connection();
$merchantStatement = $database->prepare('SELECT id FROM merchants WHERE merchant_code = :code LIMIT 1');
$merchantStatement->execute(['code' => 'MERCHANT_A']);
$merchantId = $merchantStatement->fetchColumn();
if ($merchantId === false) throw new RuntimeException('Fixture MERCHANT_A tidak tersedia di database development.');

$summary = (new ReportSummaryService(new ReportDataRepository($database)))->summarize((int) $merchantId, '2026-01-01');
assert_report_summary(count($summary['rows']) === 3, 'Ringkasan Januari harus memiliki tiga partner channel sukses.');
assert_report_summary($summary['totals']['inquiry_success'] === 603, 'Total inquiry sukses harus memakai SUM(TOTAL_TRX).');
assert_report_summary($summary['totals']['payment_success'] === 539, 'Total payment sukses harus memakai SUM(TOTAL_TRX).');
assert_report_summary(abs($summary['totals']['payment_amount'] - 269553361.0) < 0.01, 'Total nominal payment sukses tidak sesuai fixture.');
assert_report_summary(array_column($summary['rows'], 'partner_channel') === ['INDOMART', 'ALFAMART', 'POSINDO'], 'Urutan partner channel harus berdasarkan payment sukses terbesar.');
assert_report_summary($summary['metrics']['top_inquiry'] === ['partner_channel' => 'INDOMART', 'total' => 313], 'Channel inquiry tertinggi tidak sesuai fixture.');
assert_report_summary($summary['metrics']['top_payment'] === ['partner_channel' => 'INDOMART', 'total' => 285], 'Channel payment tertinggi tidak sesuai fixture.');
assert_report_summary(abs($summary['metrics']['payment_to_inquiry_percentage'] - 89.38640132669983) < 0.000001, 'Rasio payment terhadap inquiry tidak sesuai.');
assert_report_summary($summary['metrics']['payment_comparison']['previous_total'] === 658, 'Payment sukses bulan sebelumnya tidak sesuai fixture.');
assert_report_summary($summary['metrics']['payment_comparison']['difference'] === -119, 'Selisih payment sukses antarbulan tidak sesuai fixture.');
assert_report_summary($summary['metrics']['payment_comparison']['direction'] === 'decrease', 'Arah perubahan payment sukses harus turun.');
assert_report_summary(count($summary['performance']['rows']) === 3, 'Performance Januari harus memiliki tiga partner channel payment.');
assert_report_summary($summary['performance']['totals']['success'] === 539, 'Total payment sukses performance tidak sesuai fixture.');
assert_report_summary($summary['performance']['totals']['rc_68'] === 0, 'Total RC 68 Januari harus mengikuti fixture.');
assert_report_summary($summary['performance']['totals']['rc_82'] === 0, 'Total RC 82 Januari harus mengikuti fixture.');
assert_report_summary(abs($summary['performance']['totals']['success_rate'] - 100.0) < 0.000001, 'Success rate payment Januari harus 100%.');
assert_report_summary(count($summary['payment_channel_performance']['rows']) === 1, 'Performance payment channel Januari harus memiliki satu channel.');
assert_report_summary($summary['payment_channel_performance']['rows'][0]['payment_channel'] === 'Auto Deposit Mobile', 'Nama payment channel harus berasal dari mapping SIC_CODE.');
assert_report_summary($summary['payment_channel_performance']['totals']['success'] === 539, 'Total sukses berdasarkan payment channel harus 539.');
assert_report_summary($summary['payment_channel_performance']['totals']['rc_68'] === 0 && $summary['payment_channel_performance']['totals']['rc_82'] === 0, 'RC timeout payment channel Januari harus nihil.');
assert_report_summary(abs($summary['payment_channel_performance']['totals']['success_rate'] - 100.0) < 0.000001, 'Success rate payment channel Januari harus 100%.');
assert_report_summary(count($summary['top_payment_channels']) === 1, 'Top channel Januari harus mengikuti jumlah channel yang tersedia.');
assert_report_summary($summary['top_payment_channels'][0]['payment_channel'] === 'Auto Deposit Mobile', 'Top payment channel Januari tidak sesuai fixture.');
assert_report_summary($summary['top_payment_channels'][0]['total'] === 539, 'Jumlah transaksi top payment channel harus 539.');
assert_report_summary(abs($summary['top_payment_channels'][0]['percentage'] - 100.0) < 0.000001, 'Porsi top payment channel Januari harus 100%.');
assert_report_summary(count($summary['daily_trend']['rows']) === 31, 'Tren Januari harus mencakup seluruh tanggal dalam bulan.');
assert_report_summary($summary['daily_trend']['metrics']['total_success'] === 539, 'Total sukses pada tren harian harus sama dengan ringkasan payment.');
assert_report_summary($summary['daily_trend']['metrics']['highest'] === ['date' => '2026-01-19', 'total' => 44], 'Tanggal transaksi tertinggi tidak sesuai fixture.');
assert_report_summary($summary['daily_trend']['metrics']['lowest'] === ['date' => '2026-01-18', 'total' => 4], 'Tanggal transaksi terendah tidak sesuai fixture.');
assert_report_summary($summary['daily_trend']['metrics']['largest_increase'] === ['from_date' => '2026-01-18', 'to_date' => '2026-01-19', 'difference' => 40], 'Kenaikan harian terbesar tidak sesuai fixture.');
assert_report_summary($summary['daily_trend']['metrics']['average_success'] === 17, 'Rata-rata sukses harian harus dibulatkan menjadi 17.');
assert_report_summary($summary['ticket_summary']['total'] === 15, 'Total tiket Januari harus menghitung Ticket No unik.');
assert_report_summary($summary['ticket_summary']['segments'] === [['complaint_segment' => 'Pengecekan Dana', 'total' => 1], ['complaint_segment' => 'Pengecekan Transaksi', 'total' => 2], ['complaint_segment' => 'Permohonan Refund', 'total' => 12]], 'Ringkasan segmentasi tiket tidak sesuai fixture.');
assert_report_summary($summary['ticket_summary']['statuses'] === [['status' => 'Close', 'total' => 15]], 'Ringkasan status tiket tidak sesuai fixture.');
echo "ReportSummaryServiceIntegrationTest: OK\n";
