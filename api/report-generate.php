<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';
require_once __DIR__ . '/../backend/Report/DocxPackage.php';
require_once __DIR__ . '/../backend/Report/SummaryChartRenderer.php';
require_once __DIR__ . '/../backend/Report/WordReportGenerator.php';
require_once __DIR__ . '/../backend/Report/ReportDataRepository.php';
require_once __DIR__ . '/../backend/Report/ReportSummaryService.php';

use App\Report\ReportDataRepository;
use App\Report\ReportSummaryService;
use App\Report\WordReportGenerator;

/** Membaca dan memvalidasi payload awal pembuatan laporan Word. */
function report_generation_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') throw new RuntimeException('Body JSON wajib diisi.');
    try { $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new RuntimeException('Body JSON tidak valid.'); }
    if (!is_array($payload)) throw new RuntimeException('Body JSON harus berupa object.');
    $merchantId = filter_var($payload['merchant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($merchantId === false) throw new RuntimeException('Merchant tidak valid.');
    return [(int) $merchantId, (string) ($payload['report_period'] ?? ''), [
        'company_address' => 'Telkom Landmark Tower Lt. 28 Jl. Gatot Subroto Kav 52 Jakarta Selatan 12710',
        'company_phone' => '+62 [21] 829 9999',
        'company_fax' => '+62 [21] 828 1999',
        'company_logo_path' => dirname(__DIR__) . '/samples/Logo Perusahaan.png',
        'issued_at' => date('Y-m-d'),
    ]];
}

/** Mengubah nama merchant menjadi bagian nama file yang aman dan mudah dibaca. */
function report_filename_slug(string $merchant): string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $merchant), '-'));
    return $slug === '' ? 'merchant' : substr($slug, 0, 80);
}

/** Membentuk nama file unduhan yang mengikuti judul laporan dan periode berbahasa Indonesia. */
function report_download_filename(string $merchant, DateTimeImmutable $period): string
{
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $merchantPart = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $merchant)));
    if ($merchantPart === '') $merchantPart = 'Merchant';
    return sprintf('Laporan Performansi - %s - %s %s.docx', substr($merchantPart, 0, 80), $months[(int) $period->format('n')], $period->format('Y'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); json_response(['error' => 'Method tidak diizinkan.'], 405); }
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_response(['error' => 'Content-Type harus application/json.'], 415);

try {
    [$database, $user] = authorize_api_request(['super_admin', 'admin'], true);
    [$merchantId, $period, $company] = report_generation_payload();
    $merchantStatement = $database->prepare('SELECT id, merchant_name FROM merchants WHERE id = :id AND is_active = 1 LIMIT 1');
    $merchantStatement->execute(['id' => $merchantId]);
    $merchant = $merchantStatement->fetch();
    if (!$merchant) throw new RuntimeException('Merchant aktif tidak ditemukan.');

    $periodDate = DateTimeImmutable::createFromFormat('!Y-m-d', $period);
    if (!$periodDate || $periodDate->format('d') !== '01') throw new RuntimeException('Periode laporan harus berupa tanggal pertama bulan dalam format YYYY-MM-01.');
    $storageFilename = sprintf('laporan-performansi-%s-%s-%s-%s.docx', report_filename_slug((string) $merchant['merchant_name']), $periodDate->format('Y-m'), date('Ymd-His'), bin2hex(random_bytes(4)));
    $downloadFilename = report_download_filename((string) $merchant['merchant_name'], $periodDate);
    $outputPath = dirname(__DIR__) . '/generated-reports/' . $storageFilename;

    $database->beginTransaction();
    $insert = $database->prepare("INSERT INTO report_runs (merchant_id, report_period, status, output_filename, generated_by, generated_by_user_id, options_json) VALUES (:merchant_id, :report_period, 'PENDING', :filename, :generated_by, :user_id, :options_json)");
    $sections = ['cover', 'title_page', 'table_of_contents', 'monthly_summary', 'payment_performance', 'payment_channel_performance', 'top_payment_channels', 'daily_transaction_trend', 'complaint_tickets', 'manual_incidents', 'conclusion', 'certificates', 'licenses', 'contact'];
    $insert->execute(['merchant_id' => $merchantId, 'report_period' => $periodDate->format('Y-m-01'), 'filename' => $storageFilename, 'generated_by' => (string) $user['full_name'], 'user_id' => (int) $user['id'], 'options_json' => json_encode(['sections' => $sections], JSON_THROW_ON_ERROR)]);
    $runId = (int) $database->lastInsertId();
    $summary = (new ReportSummaryService(new ReportDataRepository($database)))->summarize($merchantId, $period);
    $result = (new WordReportGenerator())->generateInitialPages($outputPath, array_merge($company, ['merchant_name' => $merchant['merchant_name'], 'report_period' => $period, 'summary' => $summary]));
    $update = $database->prepare("UPDATE report_runs SET status = 'COMPLETED', output_sha256 = :sha256, generated_at = NOW() WHERE id = :id");
    $update->execute(['sha256' => $result['sha256'], 'id' => $runId]);
    $database->commit();
    json_response(['report_run_id' => $runId, 'filename' => $downloadFilename, 'sha256' => $result['sha256'], 'sections' => $sections, 'download_url' => 'api/report-download.php?id=' . $runId], 201);
} catch (RuntimeException $error) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    if (isset($database) && $database->inTransaction()) $database->rollBack();
    error_log($error->getMessage());
    json_response(['error' => 'Laporan Word gagal dibuat.'], 500);
}
