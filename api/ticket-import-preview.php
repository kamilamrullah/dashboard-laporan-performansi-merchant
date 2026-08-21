<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';
require_once __DIR__ . '/../backend/Import/TicketWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TicketImporter.php';

use App\Import\TicketImporter;
use App\Import\TicketWorkbookReader;

const MAX_TICKET_UPLOAD_BYTES = 20 * 1024 * 1024;

/** Memvalidasi upload multipart XLSX tiket termasuk ukuran dan sumber file sementara. */
function ticket_upload(): array
{
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !isset($upload['error'], $upload['tmp_name'], $upload['name'])) throw new RuntimeException('File tiket aduan wajib di-upload pada field file.');
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload file tiket aduan gagal.');
    $path = (string) $upload['tmp_name'];
    $size = filesize($path);
    if ($size === false || $size <= 0 || $size > MAX_TICKET_UPLOAD_BYTES) throw new RuntimeException('Ukuran file tiket aduan harus lebih dari 0 dan maksimal 20 MB.');
    if (!is_uploaded_file($path)) throw new RuntimeException('Sumber upload file tidak valid.');
    $name = (string) $upload['name'];
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('File tiket aduan harus berformat XLSX.');
    return ['path' => $path, 'name' => $name];
}

/** Memvalidasi pilihan merchant existing atau nama merchant baru tanpa melakukan perubahan database. */
function ticket_merchant_selection(PDO $database): array
{
    $merchantIdInput = trim((string) ($_POST['merchant_id'] ?? ''));
    $newMerchantName = preg_replace('/\s+/u', ' ', trim((string) ($_POST['new_merchant_name'] ?? ''))) ?? '';
    if (($merchantIdInput === '') === ($newMerchantName === '')) throw new RuntimeException('Pilih merchant existing atau isi satu nama merchant baru.');
    if ($merchantIdInput !== '') {
        $merchantId = filter_var($merchantIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($merchantId === false) throw new RuntimeException('Merchant yang dipilih tidak valid.');
        $statement = $database->prepare('SELECT id FROM merchants WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $merchantId]);
        if ($statement->fetch() === false) throw new RuntimeException('Merchant tidak ditemukan atau sudah tidak aktif.');
        return [(int) $merchantId, null];
    }
    if ($newMerchantName === '' || mb_strlen($newMerchantName) > 160) throw new RuntimeException('Nama merchant baru wajib diisi dan maksimal 160 karakter.');
    $statement = $database->prepare('SELECT id FROM merchants WHERE TRIM(merchant_name) = :name LIMIT 1');
    $statement->execute(['name' => $newMerchantName]);
    if ($statement->fetch() !== false) throw new RuntimeException('Nama merchant sudah tersedia. Pilih merchant tersebut dari daftar.');
    return [null, $newMerchantName];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method tidak diizinkan.'], 405);
}

try {
    [$database] = authorize_api_request(['super_admin', 'admin'], true);
    $upload = ticket_upload();
    [$merchantId, $newMerchantName] = ticket_merchant_selection($database);
    $importer = new TicketImporter($database, new TicketWorkbookReader());
    try {
        $importer->cleanupExpiredPreviews(7, 100);
    } catch (Throwable $cleanupError) {
        error_log('Cleanup preview tiket tertunda: ' . $cleanupError->getMessage());
    }
    $result = $importer->preview($upload['path'], $upload['name'], $merchantId, $newMerchantName);
    json_response($result, $result['status'] === 'IDENTICAL_FILE' ? 409 : 200);
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Preview tiket aduan gagal diproses.'], 500);
}
