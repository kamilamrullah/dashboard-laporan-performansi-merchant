<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Report/DocxPackage.php';
require_once __DIR__ . '/../backend/Report/SummaryChartRenderer.php';
require_once __DIR__ . '/../backend/Report/WordReportGenerator.php';

use App\Report\WordReportGenerator;

/** Membuat template teknis DOCX awal untuk proses koreksi layout bersama pengguna. */
function create_report_layout_template(): void
{
    $output = dirname(__DIR__) . '/generated-reports/TEMPLATE TEKNIS LAPORAN PERFORMANSI.docx';
    $logo = dirname(__DIR__) . '/samples/Logo Perusahaan.png';
    $result = (new WordReportGenerator())->generateEditableLayoutTemplate($output, $logo);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

try {
    create_report_layout_template();
} catch (Throwable $error) {
    fwrite(STDERR, 'Template layout gagal dibuat: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
