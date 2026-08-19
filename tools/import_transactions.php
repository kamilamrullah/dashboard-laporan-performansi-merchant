<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../backend/Import/TransactionWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TransactionImporter.php';

use App\Import\TransactionImporter;
use App\Import\TransactionWorkbookReader;

/** Menampilkan kegagalan CLI tanpa membocorkan detail koneksi atau stack trace. */
function fail_import(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$options = getopt('', ['file:', 'merchant-code:', 'merchant-name:', 'mapping::']);
$file = $options['file'] ?? null;
$merchantCode = $options['merchant-code'] ?? null;
$merchantName = $options['merchant-name'] ?? null;
$mapping = $options['mapping'] ?? null;

if (!is_string($file) || !is_string($merchantCode) || !is_string($merchantName)) {
    fail_import('Gunakan --file, --merchant-code, dan --merchant-name. Parameter --mapping bersifat opsional.');
}

try {
    $importer = new TransactionImporter(database_connection(), new TransactionWorkbookReader());
    $result = $importer->import($file, $merchantCode, $merchantName, is_string($mapping) ? $mapping : null);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fail_import('Import gagal: ' . $error->getMessage());
}

