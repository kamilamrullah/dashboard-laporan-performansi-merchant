<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../backend/Import/TransactionWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TransactionImporter.php';

use App\Import\TransactionImporter;
use App\Import\TransactionWorkbookReader;

/** Menampilkan kegagalan cleanup CLI tanpa membocorkan konfigurasi atau stack trace. */
function fail_cleanup(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

$options = getopt('', ['retention-days::', 'limit::']);
$retentionDays = filter_var($options['retention-days'] ?? 7, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 90]]);
$limit = filter_var($options['limit'] ?? 1000, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5000]]);
if ($retentionDays === false || $limit === false) fail_cleanup('Gunakan retention-days 1-90 dan limit 1-5000.');

try {
    $importer = new TransactionImporter(database_connection(), new TransactionWorkbookReader());
    echo json_encode($importer->cleanupExpiredPreviews((int) $retentionDays, (int) $limit), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fail_cleanup('Cleanup gagal: ' . $error->getMessage());
}
