<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Import/TicketWorkbookReader.php';
require_once __DIR__ . '/../backend/Import/TicketImporter.php';
require_once __DIR__ . '/fixtures/TicketWorkbookFactory.php';

use App\Import\TicketImporter;
use App\Import\TicketWorkbookReader;

const TICKET_TEST_DATABASE = 'merchant_performance_ticket_test';

/** Menghentikan integration test tiket dengan alasan yang spesifik. */
function assert_ticket_import(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Membuat koneksi server untuk setup database disposable khusus test tiket. */
function ticket_server_connection(): PDO
{
    $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('TEST_DB_PORT') ?: '3306';
    $user = getenv('TEST_DB_USER') ?: 'root';
    $password = getenv('TEST_DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
}

/** Membuat koneksi aplikasi ke database disposable dengan prepared statement native. */
function ticket_test_connection(): PDO
{
    $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
    $port = getenv('TEST_DB_PORT') ?: '3306';
    $user = getenv('TEST_DB_USER') ?: 'root';
    $password = getenv('TEST_DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};port={$port};dbname=" . TICKET_TEST_DATABASE . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** Membuat satu baris tiket sintetis tanpa memakai data pelanggan dari workbook sampel. */
function import_ticket_row(array $overrides = []): array
{
    return array_replace(['1', '000123', 'Close', '', '', '', 'Payment Gateway', 'Hosted', 'Permohonan Refund', 'Permintaan', 'Jan', '2026-01-14 12:11:51', '2026-01-15 16:27:50', '2026-01-20 09:45:00', '5.89261574074074', '5, 21', '', '', '1.1777662037037', '255', '5:33', 'Email', 'Not Incident', '', '', ''], $overrides);
}

/** Mengambil satu nilai scalar untuk assertion database yang ringkas. */
function ticket_scalar(PDO $database, string $sql, array $parameters = []): mixed
{
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchColumn();
}

$server = ticket_server_connection();
$fixtures = [];
try {
    if (!str_ends_with(TICKET_TEST_DATABASE, '_test')) throw new RuntimeException('Database test tiket wajib berakhiran _test.');
    $server->exec('DROP DATABASE IF EXISTS `' . TICKET_TEST_DATABASE . '`');
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) throw new RuntimeException('Schema database tidak dapat dibaca.');
    $server->exec(str_replace('merchant_performance_report', TICKET_TEST_DATABASE, $schema));
    $database = ticket_test_connection();
    $database->exec("INSERT INTO merchants (merchant_code, merchant_name) VALUES ('TICKET-MERCHANT', 'Ticket Merchant')");
    $merchantId = (int) $database->lastInsertId();
    $importer = new TicketImporter($database, new TicketWorkbookReader());

    $base = import_ticket_row();
    $invalid = import_ticket_row([1 => '000124', 11 => 'tanggal-rusak']);
    $fixtures[] = $first = TicketWorkbookFactory::create([$base, $base, $invalid]);
    $preview = $importer->preview($first, 'tickets.xlsx', $merchantId);
    assert_ticket_import($preview['summary']['ready'] === 1, 'Tiket READY tidak terdeteksi.');
    assert_ticket_import($preview['summary']['duplicate_in_file'] === 1, 'Duplikat Ticket No identik dalam file tidak terdeteksi.');
    assert_ticket_import($preview['summary']['invalid'] === 1, 'Baris tiket invalid tidak terdeteksi.');
    assert_ticket_import(count($preview['segment_summary']) === 1 && $preview['segment_summary'][0]['complaint_segment'] === 'Permohonan Refund' && $preview['segment_summary'][0]['total'] === 1, 'Ringkasan segmentasi harus menghitung Ticket No unik tanpa duplikat atau invalid.');
    assert_ticket_import($preview['period_start'] === '2026-01-14', 'Periode tidak dihitung dari Open Time.');
    $result = $importer->confirm((int) $preview['batch_id'], (string) $preview['confirmation_token'], false, null);
    assert_ticket_import($result['inserted'] === 1 && $result['duplicate'] === 1 && $result['rejected'] === 1, 'Statistik konfirmasi tiket tidak sesuai.');
    assert_ticket_import((string) ticket_scalar($database, 'SELECT ticket_number FROM complaint_tickets LIMIT 1') === '000123', 'Leading zero tiket berubah di database.');
    assert_ticket_import((string) ticket_scalar($database, 'SELECT duration_raw FROM complaint_tickets LIMIT 1') === '5:33', 'Duration mentah tidak tersimpan sesuai workbook.');
    assert_ticket_import((int) ticket_scalar($database, 'SELECT response_time_minutes FROM complaint_tickets LIMIT 1') === 1695, 'Total response time minutes tidak dihitung lengkap dengan bagian hari.');

    $identical = $importer->preview($first, 'renamed.xlsx', $merchantId);
    assert_ticket_import($identical['status'] === 'IDENTICAL_FILE', 'File tiket completed identik tidak ditolak.');

    $changedRow = import_ticket_row([2 => 'Resolved']);
    $fixtures[] = $changed = TicketWorkbookFactory::create([$changedRow]);
    $changedPreview = $importer->preview($changed, 'changed.xlsx', $merchantId);
    assert_ticket_import($changedPreview['summary']['changed'] === 1 && in_array('status', $changedPreview['rows'][0]['changed_fields'], true), 'Perubahan status tiket tidak terdeteksi.');
    $changedResult = $importer->confirm((int) $changedPreview['batch_id'], (string) $changedPreview['confirmation_token'], true, null);
    assert_ticket_import($changedResult['updated'] === 1, 'Koreksi tiket tidak diterapkan.');
    assert_ticket_import((int) ticket_scalar($database, 'SELECT COUNT(*) FROM ticket_change_history') === 1, 'Riwayat perubahan tiket tidak tersimpan.');

    $fixtures[] = $duplicateDatabase = TicketWorkbookFactory::create([$changedRow, import_ticket_row([1 => '000125', 2 => 'Open'])]);
    $databasePreview = $importer->preview($duplicateDatabase, 'duplicate-database.xlsx', $merchantId);
    assert_ticket_import($databasePreview['summary']['duplicate_database'] === 1 && $databasePreview['summary']['ready'] === 1, 'Duplikat database tiket tidak diklasifikasikan dengan benar.');
    $deleted = $importer->deletePreview((int) $databasePreview['batch_id'], (string) $databasePreview['confirmation_token']);
    assert_ticket_import($deleted['status'] === 'DELETED', 'Preview tiket tidak dapat dihapus.');

    $database->exec("CREATE TRIGGER fail_ticket_insert BEFORE INSERT ON complaint_tickets FOR EACH ROW BEGIN IF NEW.ticket_number = 'ROLLBACK-2' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Forced ticket rollback'; END IF; END");
    $fixtures[] = $rollback = TicketWorkbookFactory::create([import_ticket_row([1 => 'ROLLBACK-1']), import_ticket_row([1 => 'ROLLBACK-2'])]);
    $rollbackPreview = $importer->preview($rollback, 'rollback.xlsx', $merchantId);
    try {
        $importer->confirm((int) $rollbackPreview['batch_id'], (string) $rollbackPreview['confirmation_token'], false, null);
        throw new RuntimeException('Trigger rollback tiket seharusnya menggagalkan konfirmasi.');
    } catch (PDOException $error) {
        assert_ticket_import(str_contains($error->getMessage(), 'Forced ticket rollback'), 'Kegagalan bukan berasal dari trigger rollback tiket.');
    }
    assert_ticket_import((int) ticket_scalar($database, 'SELECT COUNT(*) FROM complaint_tickets WHERE source_batch_id = :id', ['id' => $rollbackPreview['batch_id']]) === 0, 'Insert tiket parsial tidak di-rollback.');
    assert_ticket_import((string) ticket_scalar($database, 'SELECT status FROM import_batches WHERE id = :id', ['id' => $rollbackPreview['batch_id']]) === 'PREVIEWED', 'Status preview tiket tidak pulih setelah rollback.');
    echo "TicketImporterIntegrationTest: OK\n";
} finally {
    foreach ($fixtures as $fixture) @unlink($fixture);
    $server->exec('DROP DATABASE IF EXISTS `' . TICKET_TEST_DATABASE . '`');
}
