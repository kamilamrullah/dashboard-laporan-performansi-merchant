<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';
require_once __DIR__ . '/../backend/Import/TicketWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TicketImporter.php';

use App\Import\TicketImporter;
use App\Import\TicketWorkbookReader;

/** Membaca batch dan token JSON untuk penghapusan preview tiket. */
function ticket_delete_preview_payload(): array
{
    $contents = file_get_contents('php://input');
    if ($contents === false || $contents === '') throw new RuntimeException('Body JSON wajib diisi.');
    try {
        $payload = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Body JSON tidak valid.');
    }
    if (!is_array($payload)) throw new RuntimeException('Body JSON harus berupa object.');
    $batchId = filter_var($payload['batch_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $token = trim((string) ($payload['confirmation_token'] ?? ''));
    if ($batchId === false || $token === '' || mb_strlen($token) > 128) throw new RuntimeException('Batch atau token preview tiket tidak valid.');
    return [(int) $batchId, $token];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method tidak diizinkan.'], 405);
}
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_response(['error' => 'Content-Type harus application/json.'], 415);

try {
    [$database] = authorize_api_request(['super_admin', 'admin'], true);
    [$batchId, $token] = ticket_delete_preview_payload();
    $importer = new TicketImporter($database, new TicketWorkbookReader());
    json_response($importer->deletePreview($batchId, $token));
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Preview tiket gagal dihapus.'], 500);
}
