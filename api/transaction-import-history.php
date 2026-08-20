<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';

/** Membaca parameter integer positif dengan batas minimum dan maksimum yang ditentukan. */
function history_integer_parameter(string $name, int $default, int $minimum, int $maximum): int
{
    $raw = $_GET[$name] ?? null;
    if ($raw === null || $raw === '') return $default;
    $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]);
    if ($value === false) json_response(['error' => "Parameter {$name} tidak valid."], 422);
    return (int) $value;
}

/** Mengubah kolom JSON database menjadi array tanpa membiarkan JSON rusak lolos ke response. */
function decode_history_json(?string $value): ?array
{
    if ($value === null) return null;
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Data audit import tidak valid.');
    }
    return is_array($decoded) ? $decoded : null;
}

/** Mengambil daftar batch import transaksi secara paginated dan terurut terbaru. */
function transaction_batch_list(PDO $database, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    $total = (int) $database->query("SELECT COUNT(*) FROM import_batches WHERE data_type = 'TRANSACTION'")->fetchColumn();
    $statement = $database->prepare(
        "SELECT b.id, b.original_filename, b.status, b.detected_period_start, b.detected_period_end,
                b.total_rows, b.valid_rows, b.inserted_rows, b.updated_rows, b.duplicate_rows, b.rejected_rows,
                COALESCE(u.full_name, b.imported_by) imported_by, b.confirmation_expires_at, b.confirmed_at, b.completed_at, b.created_at,
                m.id merchant_id, m.merchant_name
         FROM import_batches b
         LEFT JOIN merchants m ON m.id = b.merchant_id
         LEFT JOIN users u ON u.id = b.imported_by_user_id
         WHERE b.data_type = 'TRANSACTION'
         ORDER BY b.created_at DESC, b.id DESC LIMIT :limit OFFSET :offset"
    );
    $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    return ['items' => $statement->fetchAll(), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))]];
}

/** Mengambil metadata satu batch dan menolak ID yang bukan batch transaksi. */
function transaction_batch(PDO $database, int $batchId): array
{
    $statement = $database->prepare(
        "SELECT b.id, b.original_filename, b.file_sha256, b.status, b.detected_period_start, b.detected_period_end,
                b.total_rows, b.valid_rows, b.inserted_rows, b.updated_rows, b.duplicate_rows, b.rejected_rows,
                b.failure_message, COALESCE(u.full_name, b.imported_by) imported_by, b.confirmation_expires_at, b.confirmed_at, b.completed_at, b.created_at,
                m.id merchant_id, m.merchant_name
         FROM import_batches b LEFT JOIN merchants m ON m.id = b.merchant_id
         LEFT JOIN users u ON u.id = b.imported_by_user_id
         WHERE b.id = :id AND b.data_type = 'TRANSACTION' LIMIT 1"
    );
    $statement->execute(['id' => $batchId]);
    $batch = $statement->fetch();
    if ($batch === false) json_response(['error' => 'Batch transaksi tidak ditemukan.'], 404);
    return $batch;
}

/** Menghitung komposisi outcome staging untuk membangun kembali ringkasan preview. */
function transaction_batch_summary(PDO $database, int $batchId): array
{
    $summary = ['total' => 0, 'ready' => 0, 'changed' => 0, 'duplicate_in_file' => 0, 'duplicate_database' => 0, 'conflict_in_file' => 0, 'invalid' => 0];
    $statement = $database->prepare('SELECT outcome, COUNT(*) total FROM transaction_import_rows WHERE batch_id = :batch_id GROUP BY outcome');
    $statement->execute(['batch_id' => $batchId]);
    foreach ($statement->fetchAll() as $row) {
        $summary['total'] += (int) $row['total'];
        $key = strtolower((string) $row['outcome']);
        if (array_key_exists($key, $summary)) $summary[$key] = (int) $row['total'];
    }
    return $summary;
}

/** Mengambil detail audit baris satu batch dengan perubahan dan error validasi. */
function transaction_batch_rows(PDO $database, int $batchId, int $page, int $perPage): array
{
    $offset = ($page - 1) * $perPage;
    $count = $database->prepare('SELECT COUNT(*) FROM transaction_import_rows WHERE batch_id = :batch_id');
    $count->execute(['batch_id' => $batchId]);
    $total = (int) $count->fetchColumn();
    $statement = $database->prepare(
        "SELECT id, source_row_number, outcome, normalized_data, existing_data, validation_errors, created_at
         FROM transaction_import_rows WHERE batch_id = :batch_id
         ORDER BY FIELD(outcome, 'UPDATED', 'CHANGED', 'STALE_CONFLICT', 'INVALID', 'CONFLICT_IN_FILE',
                                'DUPLICATE_IN_FILE', 'DUPLICATE_DATABASE', 'SKIPPED_CHANGE', 'INSERTED'),
                  source_row_number
         LIMIT :limit OFFSET :offset"
    );
    $statement->bindValue('batch_id', $batchId, PDO::PARAM_INT);
    $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue('offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $rows = array_map(static function (array $row): array {
        $row['normalized_data'] = decode_history_json($row['normalized_data']);
        $row['existing_data'] = decode_history_json($row['existing_data']);
        $row['validation_errors'] = decode_history_json($row['validation_errors']);
        return $row;
    }, $statement->fetchAll());
    return ['items' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))]];
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_response(['error' => 'Method tidak diizinkan.'], 405);
}

try {
    [$database, $user] = authorize_api_request(['super_admin', 'admin', 'viewer']);
    $page = history_integer_parameter('page', 1, 1, 1000000);
    $perPage = history_integer_parameter('per_page', 20, 1, 100);
    $batchIdRaw = $_GET['batch_id'] ?? null;
    if ($batchIdRaw === null || $batchIdRaw === '') {
        json_response(transaction_batch_list($database, $page, $perPage));
    }
    if ((string) $user['role'] === 'viewer') json_response(['error' => 'Viewer hanya dapat melihat ringkasan riwayat import.'], 403);
    $batchId = filter_var($batchIdRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($batchId === false) json_response(['error' => 'Parameter batch_id tidak valid.'], 422);
    json_response(['batch' => transaction_batch($database, (int) $batchId), 'summary' => transaction_batch_summary($database, (int) $batchId), 'rows' => transaction_batch_rows($database, (int) $batchId, $page, $perPage)]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Riwayat import transaksi gagal dimuat.'], 500);
}
