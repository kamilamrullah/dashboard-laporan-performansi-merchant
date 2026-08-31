<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../backend/Dashboard/DashboardTrendService.php';

use App\Dashboard\DashboardTrendService;

/** Menghentikan pengujian tren dashboard ketika hasil aktual tidak sesuai fixture. */
function assert_dashboard_trend(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = database_connection();
$merchantStatement = $database->prepare('SELECT id FROM merchants WHERE merchant_code = :code LIMIT 1');
$merchantStatement->execute(['code' => 'MERCHANT_A']);
$merchantId = $merchantStatement->fetchColumn();
if ($merchantId === false) throw new RuntimeException('Fixture MERCHANT_A tidak tersedia di database development.');

$service = new DashboardTrendService($database);
$monthly = $service->trend('2026-01', 'monthly', ['merchant_id' => $merchantId]);
assert_dashboard_trend($monthly['range'] === ['date_from' => '2026-01-01', 'date_to' => '2026-12-31'], 'Rentang tren bulanan harus mengikuti tahun kalender periode terpilih.');
assert_dashboard_trend(count($monthly['rows']) === 12, 'Tren bulanan harus selalu berisi dua belas titik.');
$january = array_values(array_filter($monthly['rows'], static fn (array $row): bool => $row['period'] === '2026-01'))[0] ?? null;
assert_dashboard_trend($january !== null, 'Titik Januari tidak ditemukan pada tren bulanan.');
assert_dashboard_trend($january['has_data'] === true, 'Januari harus ditandai memiliki data.');
assert_dashboard_trend((int) $january['inquiry'] === 603, 'Inquiry Januari harus memakai SUM(TOTAL_TRX).');
assert_dashboard_trend((int) $january['payment'] === 539, 'Payment Januari harus memakai SUM(TOTAL_TRX).');
assert_dashboard_trend(abs((float) $january['payment_amount'] - 269553361.0) < 0.01, 'Nominal payment Januari tidak sesuai fixture.');
$december = array_values(array_filter($monthly['rows'], static fn (array $row): bool => $row['period'] === '2026-12'))[0] ?? null;
assert_dashboard_trend($december !== null && $december['has_data'] === false, 'Desember tanpa data harus tetap tersedia dan ditandai kosong.');
assert_dashboard_trend($december['inquiry'] === null && $december['payment'] === null && $december['payment_amount'] === null, 'Bulan tanpa data tidak boleh dikonversi menjadi nol.');

$daily = $service->trend('2026-01', 'daily', ['merchant_id' => $merchantId]);
assert_dashboard_trend(count($daily['rows']) === 31, 'Drill-down Januari harus memuat seluruh tanggal kalender.');
assert_dashboard_trend(array_sum(array_map(static fn (array $row): int => (int) $row['inquiry'], $daily['rows'])) === 603, 'Total inquiry drill-down harus sama dengan tren bulanan.');
assert_dashboard_trend(array_sum(array_map(static fn (array $row): int => (int) $row['payment'], $daily['rows'])) === 539, 'Total payment drill-down harus sama dengan tren bulanan.');

foreach ([['2026-13', 'monthly'], ['2026-01', 'weekly']] as [$period, $granularity]) {
    try {
        $service->trend($period, $granularity, ['merchant_id' => $merchantId]);
        throw new RuntimeException('Parameter tren tidak valid seharusnya ditolak.');
    } catch (InvalidArgumentException) {
        // Parameter tidak valid memang harus menghasilkan error validasi.
    }
}

echo "DashboardTrendServiceIntegrationTest: OK\n";
