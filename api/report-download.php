<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';

/** Membaca identifier laporan dari query string dan menolak nilai di luar integer positif. */
function report_download_id(): int
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) throw new RuntimeException('ID laporan tidak valid.');
    return (int) $id;
}

/** Membentuk nama file download aman tanpa karakter kontrol atau path separator. */
function safe_report_download_name(string $filename): string
{
    $filename = basename(str_replace(["\r", "\n", "\\"], '', $filename));
    if ($filename === '' || !preg_match('/\A[a-zA-Z0-9._-]+\.docx\z/', $filename)) throw new RuntimeException('Nama file laporan tidak valid.');
    return $filename;
}

/** Membentuk nama unduhan laporan dari merchant dan periode tanpa mengekspos nama penyimpanan internal. */
function titled_report_download_name(string $merchant, string $period): string
{
    $periodDate = DateTimeImmutable::createFromFormat('!Y-m-d', $period);
    if (!$periodDate) throw new RuntimeException('Periode laporan tidak valid.');
    $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $merchantPart = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 ]+/', ' ', $merchant)));
    if ($merchantPart === '') $merchantPart = 'Merchant';
    return sprintf('Laporan Performansi - %s - %s %s.docx', substr($merchantPart, 0, 80), $months[(int) $periodDate->format('n')], $periodDate->format('Y'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); json_response(['error' => 'Method tidak diizinkan.'], 405); }

try {
    [$database] = authorize_api_request(['super_admin', 'admin', 'viewer']);
    $reportId = report_download_id();
    $statement = $database->prepare("SELECT r.output_filename, r.output_sha256, r.report_period, m.merchant_name FROM report_runs r JOIN merchants m ON m.id = r.merchant_id WHERE r.id = :id AND r.status = 'COMPLETED' LIMIT 1");
    $statement->execute(['id' => $reportId]);
    $report = $statement->fetch();
    if (!$report) json_response(['error' => 'Laporan selesai tidak ditemukan.'], 404);

    $filename = safe_report_download_name((string) $report['output_filename']);
    $downloadName = titled_report_download_name((string) $report['merchant_name'], (string) $report['report_period']);
    $reportsDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'generated-reports';
    $path = $reportsDirectory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path) || !is_readable($path)) json_response(['error' => 'File laporan tidak tersedia.'], 404);
    $expectedHash = (string) ($report['output_sha256'] ?? '');
    $actualHash = hash_file('sha256', $path);
    if ($expectedHash === '' || $actualHash === false || !hash_equals($expectedHash, $actualHash)) throw new RuntimeException('Integritas file laporan tidak valid.');

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    if (readfile($path) === false) throw new RuntimeException('File laporan gagal dikirim.');
    exit;
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'File laporan gagal diunduh.'], 500);
}
