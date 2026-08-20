<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Import/TransactionWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TransactionImporter.php';
require_once __DIR__ . '/fixtures/TransactionWorkbookFactory.php';

use App\Import\TransactionImporter;
use App\Import\TransactionWorkbookReader;

const TEST_DATABASE = 'merchant_performance_report_test';

/** Menghentikan test dengan pesan spesifik ketika hasil aktual tidak sesuai harapan. */
function assert_integration(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Membuat koneksi server khusus setup dengan izin multi-statement untuk menjalankan schema lengkap. */
function server_connection(): PDO
{
    $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('TEST_DB_PORT') ?: '3306';
    $user = getenv('TEST_DB_USER') ?: 'root';
    $password = getenv('TEST_DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
}

/** Membuat koneksi database test dengan prepared statement native seperti aplikasi. */
function test_connection(): PDO
{
    $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('TEST_DB_PORT') ?: '3306';
    $user = getenv('TEST_DB_USER') ?: 'root';
    $password = getenv('TEST_DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};port={$port};dbname=" . TEST_DATABASE . ';charset=utf8mb4', $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Memastikan nama database test tidak dapat diarahkan ke database aplikasi atau database sistem. */
function validate_test_database_name(): void
{
    if (!preg_match('/^[a-z0-9_]+_test$/', TEST_DATABASE)) {
        throw new RuntimeException('Nama database integration test wajib berakhiran _test.');
    }
}

/** Membuat ulang database test menggunakan snapshot schema repository terbaru. */
function prepare_test_database(PDO $server): void
{
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Schema database tidak dapat dibaca.');
    $server->exec('DROP DATABASE IF EXISTS `' . TEST_DATABASE . '`');
    $server->exec(str_replace('merchant_performance_report', TEST_DATABASE, $schema));
}

/** Menghapus database khusus test setelah seluruh skenario selesai atau mengalami kegagalan. */
function drop_test_database(PDO $server): void
{
    $server->exec('DROP DATABASE IF EXISTS `' . TEST_DATABASE . '`');
}

/** Menghasilkan satu baris transaksi sintetis dengan override untuk skenario tertentu. */
function transaction_row(array $overrides = []): array
{
    return array_replace([
        '20260801', 'TEST_SOURCE', 'PAYMENT', '001', 'PARTNER TEST',
        '0001', '0042', '00', '10.0', '123456789012345678.12',
    ], $overrides);
}

/** Mengambil satu nilai scalar dari database untuk assertion yang ringkas. */
function scalar(PDO $database, string $sql, array $parameters = []): mixed
{
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

/** Menjalankan skenario import, duplikasi, perubahan, retry preview, dan rollback atomik. */
function run_importer_scenarios(PDO $database, array &$fixtures): void
{
    $importer = new TransactionImporter($database, new TransactionWorkbookReader());
    $base = transaction_row();
    $invalid = transaction_row([0 => '20260231']);
    $fixtures[] = $firstFile = TransactionWorkbookFactory::create([$base, $base, $invalid], 'first-import');
    $preview = $importer->preview($firstFile, 'first.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    assert_integration($preview['original_filename'] === 'first.xlsx', 'Nama file asli tidak tersedia pada preview.');
    assert_integration($preview['summary']['ready'] === 1, 'Baris READY pertama tidak terdeteksi.');
    assert_integration($preview['summary']['duplicate_in_file'] === 1, 'Duplikat dalam file tidak terdeteksi.');
    assert_integration($preview['summary']['invalid'] === 1, 'Baris invalid tidak terdeteksi.');
    $secondPage = $importer->previewRows((int) $preview['batch_id'], (string) $preview['confirmation_token'], 2, 1, null);
    assert_integration($secondPage['pagination']['total'] === 3 && count($secondPage['items']) === 1, 'Pagination preview tidak mengembalikan halaman yang benar.');
    $invalidPage = $importer->previewRows((int) $preview['batch_id'], (string) $preview['confirmation_token'], 1, 50, 'INVALID');
    assert_integration($invalidPage['pagination']['total'] === 1 && $invalidPage['items'][0]['outcome'] === 'INVALID', 'Filter outcome preview tidak bekerja.');
    $result = $importer->confirm((int) $preview['batch_id'], (string) $preview['confirmation_token'], false, 'Integration Test');
    assert_integration($result['inserted'] === 1 && $result['duplicate'] === 1 && $result['rejected'] === 1, 'Statistik konfirmasi pertama tidak sesuai.');
    assert_integration((string) scalar($database, 'SELECT total_amount FROM transaction_aggregates LIMIT 1') === '123456789012345678.12', 'Nominal besar berubah saat disimpan ke database.');

    $duplicateFileResult = $importer->preview($firstFile, 'renamed.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    assert_integration($duplicateFileResult['status'] === 'IDENTICAL_FILE', 'File completed identik harus ditolak meskipun nama berubah.');
    try {
        $importer->confirm((int) $preview['batch_id'], (string) $preview['confirmation_token'], false, 'Integration Test');
        throw new RuntimeException('Konfirmasi kedua seharusnya ditolak.');
    } catch (RuntimeException $error) {
        assert_integration($error->getMessage() === 'Batch tidak lagi dapat dikonfirmasi.', 'Alasan penolakan konfirmasi kedua tidak sesuai.');
    }

    $changed = transaction_row([8 => '12.0', 9 => '123456789012345678.13']);
    $fixtures[] = $changedFile = TransactionWorkbookFactory::create([$changed], 'changed-import');
    $changedPreview = $importer->preview($changedFile, 'changed.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    assert_integration($changedPreview['summary']['changed'] === 1, 'Perubahan total transaksi tidak terdeteksi.');
    $changedResult = $importer->confirm((int) $changedPreview['batch_id'], (string) $changedPreview['confirmation_token'], true, 'Integration Test');
    assert_integration($changedResult['updated'] === 1, 'Baris berubah tidak diperbarui.');
    assert_integration((int) scalar($database, 'SELECT COUNT(*) FROM transaction_change_history') === 1, 'Riwayat perubahan tidak tersimpan.');

    $fixtures[] = $databaseDuplicateFile = TransactionWorkbookFactory::create([$changed], 'database-duplicate');
    $databaseDuplicate = $importer->preview($databaseDuplicateFile, 'database-duplicate.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    assert_integration($databaseDuplicate['summary']['duplicate_database'] === 1, 'Duplikat database tidak terdeteksi.');

    $pending = transaction_row([0 => '20260802']);
    $fixtures[] = $pendingFile = TransactionWorkbookFactory::create([$pending], 'replace-preview');
    $oldPreview = $importer->preview($pendingFile, 'pending.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    $newPreview = $importer->preview($pendingFile, 'pending.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    assert_integration($oldPreview['batch_id'] !== $newPreview['batch_id'], 'Upload ulang harus mengganti batch preview lama.');
    assert_integration((int) scalar($database, 'SELECT COUNT(*) FROM import_batches WHERE id = :id', ['id' => $oldPreview['batch_id']]) === 0, 'Batch preview lama belum dihapus.');
    $deleted = $importer->deletePreview((int) $newPreview['batch_id'], (string) $newPreview['confirmation_token']);
    assert_integration($deleted['status'] === 'DELETED', 'Preview terautentikasi tidak dapat dihapus.');
    assert_integration((int) scalar($database, 'SELECT COUNT(*) FROM import_batches WHERE id = :id', ['id' => $newPreview['batch_id']]) === 0, 'Metadata preview belum terhapus.');
    assert_integration((int) scalar($database, 'SELECT COUNT(*) FROM transaction_import_rows WHERE batch_id = :id', ['id' => $newPreview['batch_id']]) === 0, 'Staging preview belum terhapus melalui cascade.');

    $database->exec("CREATE TRIGGER fail_test_transaction BEFORE INSERT ON transaction_aggregates FOR EACH ROW BEGIN IF NEW.total_trx = 999 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced integration rollback'; END IF; END");
    $rollbackRows = [transaction_row([0 => '20260803', 8 => '20']), transaction_row([0 => '20260804', 8 => '999'])];
    $fixtures[] = $rollbackFile = TransactionWorkbookFactory::create($rollbackRows, 'rollback-import');
    $rollbackPreview = $importer->preview($rollbackFile, 'rollback.xlsx', 'TEST-MERCHANT', 'Test Merchant');
    try {
        $importer->confirm((int) $rollbackPreview['batch_id'], (string) $rollbackPreview['confirmation_token'], false, 'Integration Test');
        throw new RuntimeException('Trigger test seharusnya menggagalkan konfirmasi.');
    } catch (PDOException $error) {
        assert_integration(str_contains($error->getMessage(), 'Forced integration rollback'), 'Kegagalan rollback tidak berasal dari trigger test.');
    }
    assert_integration((int) scalar($database, 'SELECT COUNT(*) FROM transaction_aggregates WHERE source_batch_id = :id', ['id' => $rollbackPreview['batch_id']]) === 0, 'Insert parsial tidak di-rollback.');
    assert_integration((string) scalar($database, 'SELECT status FROM import_batches WHERE id = :id', ['id' => $rollbackPreview['batch_id']]) === 'PREVIEWED', 'Status batch tidak kembali ke PREVIEWED setelah rollback.');
}

validate_test_database_name();
$server = server_connection();
$fixtures = [];
try {
    prepare_test_database($server);
    run_importer_scenarios(test_connection(), $fixtures);
    echo "TransactionImporterIntegrationTest: OK\n";
} finally {
    foreach ($fixtures as $fixture) @unlink($fixture);
    drop_test_database($server);
}
