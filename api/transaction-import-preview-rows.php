<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/Import/TransactionWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TransactionImporter.php';

use App\Import\TransactionImporter;
use App\Import\TransactionWorkbookReader;

/** Membaca dan memvalidasi body JSON untuk permintaan halaman preview. */
function preview_rows_payload(): array
{
    $contents = file_get_contents('php://input');
    if ($contents === false || $contents === '') throw new RuntimeException('Body JSON wajib diisi.');
    try {
        $payload = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Body JSON tidak valid.');
    }
    if (!is_array($payload)) throw new RuntimeException('Body JSON harus berupa object.');
    $batchId = filter_var($payload['batch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $page = filter_var($payload['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);
    $perPage = filter_var($payload['per_page'] ?? 50, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
    $token = trim((string) ($payload['confirmation_token'] ?? ''));
    $outcome = trim((string) ($payload['outcome'] ?? ''));
    if ($batchId === false || $page === false || $perPage === false || $token === '' || mb_strlen($token) > 128) {
        throw new RuntimeException('Parameter halaman preview tidak valid.');
    }
    return [(int) $batchId, $token, (int) $page, (int) $perPage, $outcome === '' ? null : $outcome];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method tidak diizinkan.'], 405);
}
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    json_response(['error' => 'Content-Type harus application/json.'], 415);
}

try {
    [$batchId, $token, $page, $perPage, $outcome] = preview_rows_payload();
    $importer = new TransactionImporter(database_connection(), new TransactionWorkbookReader());
    json_response($importer->previewRows($batchId, $token, $page, $perPage, $outcome));
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Halaman preview transaksi gagal dimuat.'], 500);
}
