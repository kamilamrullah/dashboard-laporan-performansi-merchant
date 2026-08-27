<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../backend/Report/DocxPackage.php';
require_once __DIR__ . '/../backend/Report/SummaryChartRenderer.php';
require_once __DIR__ . '/../backend/Report/ReportDataRepository.php';
require_once __DIR__ . '/../backend/Report/ReportSummaryService.php';
require_once __DIR__ . '/../backend/Report/WordReportGenerator.php';

use App\Report\ReportDataRepository;
use App\Report\ReportSummaryService;
use App\Report\WordReportGenerator;

/** Membuat laporan pratinjau lokal untuk memeriksa hasil template tanpa melalui endpoint HTTP. */
function generateReportPreview(string $merchantCode, string $period, string $outputPath): array
{
    $database = database_connection();
    $statement = $database->prepare('SELECT id, merchant_name FROM merchants WHERE merchant_code = :code LIMIT 1');
    $statement->execute(['code' => $merchantCode]);
    $merchant = $statement->fetch();
    if (!$merchant) throw new RuntimeException('Merchant tidak ditemukan.');

    $summary = (new ReportSummaryService(new ReportDataRepository($database)))->summarize((int) $merchant['id'], $period);
    return (new WordReportGenerator())->generateInitialPages($outputPath, [
        'merchant_name' => $merchant['merchant_name'],
        'report_period' => $period,
        'issued_at' => date('Y-m-d'),
        'company_address' => 'Telkom Landmark Tower Lt. 28 Jl. Gatot Subroto Kav 52 Jakarta Selatan 12710',
        'company_phone' => '+62 [21] 829 9999',
        'company_fax' => '+62 [21] 828 1999',
        'company_logo_path' => dirname(__DIR__) . '/samples/Logo Perusahaan.png',
        'summary' => $summary,
    ]);
}

if (PHP_SAPI !== 'cli') throw new RuntimeException('Skrip pratinjau hanya boleh dijalankan melalui CLI.');
if ($argc !== 4) throw new RuntimeException('Gunakan: php tools/generate_report_preview.php MERCHANT_CODE YYYY-MM-01 OUTPUT.docx');

$result = generateReportPreview($argv[1], $argv[2], $argv[3]);
echo json_encode(['output' => $argv[3], 'sha256' => $result['sha256']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
