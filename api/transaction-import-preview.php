<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/Import/TransactionWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TransactionImporter.php';

use App\Import\TransactionImporter;
use App\Import\TransactionWorkbookReader;

const MAX_TRANSACTION_UPLOAD_BYTES = 20 * 1024 * 1024;

/** Memvalidasi upload multipart dan mengembalikan metadata file sementara yang aman diproses. */
function transaction_upload(): array
{
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !isset($upload['error'], $upload['size'], $upload['tmp_name'], $upload['name'])) {
        throw new RuntimeException('File transaksi wajib di-upload pada field file.');
    }
    if ((int) $upload['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload file transaksi gagal.');
    }
    $actualSize = filesize((string) $upload['tmp_name']);
    if ($actualSize === false || $actualSize <= 0 || $actualSize > MAX_TRANSACTION_UPLOAD_BYTES) {
        throw new RuntimeException('Ukuran file transaksi harus lebih dari 0 dan maksimal 20 MB.');
    }
    $temporaryPath = (string) $upload['tmp_name'];
    if (!is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Sumber upload file tidak valid.');
    }
    $originalName = (string) $upload['name'];
    if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new RuntimeException('File transaksi harus berformat XLSX.');
    }
    return ['path' => $temporaryPath, 'name' => $originalName];
}

/** Menentukan merchant existing atau menyiapkan kode internal bagi nama merchant baru. */
function resolve_merchant_selection(PDO $database): array
{
    $merchantIdInput = trim((string) ($_POST['merchant_id'] ?? ''));
    $newMerchantName = trim((string) ($_POST['new_merchant_name'] ?? ''));
    if (($merchantIdInput === '') === ($newMerchantName === '')) {
        throw new RuntimeException('Pilih merchant existing atau isi satu nama merchant baru.');
    }
    if ($merchantIdInput !== '') {
        $merchantId = filter_var($merchantIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($merchantId === false) throw new RuntimeException('Merchant yang dipilih tidak valid.');
        $statement = $database->prepare('SELECT merchant_code, merchant_name FROM merchants WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => $merchantId]);
        $merchant = $statement->fetch();
        if ($merchant === false) throw new RuntimeException('Merchant tidak ditemukan atau sudah tidak aktif.');
        return [(string) $merchant['merchant_code'], (string) $merchant['merchant_name']];
    }
    if (mb_strlen($newMerchantName) > 160) throw new RuntimeException('Nama merchant baru maksimal 160 karakter.');
    $statement = $database->prepare('SELECT id FROM merchants WHERE TRIM(merchant_name) = :name LIMIT 1');
    $statement->execute(['name' => $newMerchantName]);
    if ($statement->fetch() !== false) throw new RuntimeException('Nama merchant sudah tersedia. Pilih merchant tersebut dari daftar.');
    return ['MRC-' . strtoupper(bin2hex(random_bytes(6))), $newMerchantName];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(['error' => 'Method tidak diizinkan.'], 405);
}

try {
    $upload = transaction_upload();
    $database = database_connection();
    [$merchantCode, $merchantName] = resolve_merchant_selection($database);
    $importer = new TransactionImporter($database, new TransactionWorkbookReader());
    try {
        $importer->cleanupExpiredPreviews(7, 100);
    } catch (Throwable $cleanupError) {
        error_log('Cleanup preview tertunda: ' . $cleanupError->getMessage());
    }
    $result = $importer->preview($upload['path'], $upload['name'], $merchantCode, $merchantName);
    json_response($result, $result['status'] === 'IDENTICAL_FILE' ? 409 : 200);
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Preview transaksi gagal diproses.'], 500);
}
