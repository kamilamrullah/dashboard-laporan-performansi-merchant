<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/Auth/UserService.php';

use App\Auth\UserService;

const AUTH_TEST_DATABASE = 'merchant_performance_auth_test';

/** Menghentikan test autentikasi ketika kondisi yang diwajibkan tidak terpenuhi. */
function assert_authentication(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** Membuka koneksi server untuk membuat database uji disposable. */
function authentication_server_connection(): PDO
{
    $host = getenv('TEST_DB_HOST') ?: '127.0.0.1'; $port = getenv('TEST_DB_PORT') ?: '3306'; $user = getenv('TEST_DB_USER') ?: 'root'; $password = getenv('TEST_DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]);
}

/** Membuka koneksi native-prepared statement ke database autentikasi uji. */
function authentication_database_connection(): PDO
{
    $host = getenv('TEST_DB_HOST') ?: '127.0.0.1'; $port = getenv('TEST_DB_PORT') ?: '3306'; $user = getenv('TEST_DB_USER') ?: 'root'; $password = getenv('TEST_DB_PASSWORD') ?: '';
    return new PDO("mysql:host={$host};port={$port};dbname=" . AUTH_TEST_DATABASE . ';charset=utf8mb4', $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** Membuat database baru dari schema repository agar migration snapshot ikut diuji. */
function prepare_authentication_database(PDO $server): void
{
    if (!str_ends_with(AUTH_TEST_DATABASE, '_test')) throw new RuntimeException('Database autentikasi uji wajib berakhiran _test.');
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql'); if ($schema === false) throw new RuntimeException('Schema tidak dapat dibaca.');
    $server->exec('DROP DATABASE IF EXISTS `' . AUTH_TEST_DATABASE . '`'); $server->exec(str_replace('merchant_performance_report', AUTH_TEST_DATABASE, $schema));
}

/** Menjalankan skenario role, password, session version, uniqueness, rate-limit, dan audit FK. */
function run_authentication_scenarios(PDO $database): void
{
    $roles = $database->query('SELECT code FROM roles ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    assert_authentication($roles === ['super_admin', 'admin', 'viewer'], 'Seed role autentikasi tidak sesuai.');
    $hash = password_hash('Temporary-Password-123', PASSWORD_DEFAULT); assert_authentication(is_string($hash), 'Password test gagal di-hash.');
    $insert = $database->prepare("INSERT INTO users (public_id, username, email, full_name, password_hash, role_id) SELECT :public_id, :username, :email, :full_name, :password_hash, id FROM roles WHERE code = 'super_admin'");
    $insert->execute(['public_id' => '00000000-0000-4000-8000-000000000001', 'username' => 'auth_test_admin', 'email' => 'auth-test@example.invalid', 'full_name' => 'Auth Test Admin', 'password_hash' => $hash]);
    $userId = (int) $database->lastInsertId(); assert_authentication($userId > 0 && password_verify('Temporary-Password-123', $hash), 'User atau verifikasi password gagal.');
    $database->prepare('UPDATE users SET session_version = session_version + 1, must_change_password = 0 WHERE id = :id')->execute(['id' => $userId]);
    $user = $database->query("SELECT session_version, must_change_password FROM users WHERE username = 'auth_test_admin'")->fetch(); assert_authentication((int) $user['session_version'] === 2 && (int) $user['must_change_password'] === 0, 'Invalidasi session setelah perubahan password gagal.');
    try { $insert->execute(['public_id' => '00000000-0000-4000-8000-000000000002', 'username' => 'auth_test_admin', 'email' => 'other@example.invalid', 'full_name' => 'Duplicate', 'password_hash' => $hash]); throw new RuntimeException('Username duplikat tidak ditolak.'); }
    catch (PDOException $error) { assert_authentication($error->getCode() === '23000', 'Unique username menghasilkan error yang tidak sesuai.'); }
    $attempt = str_repeat('a', 64); $database->prepare('INSERT INTO login_attempts (attempt_key) VALUES (:attempt_key)')->execute(['attempt_key' => $attempt]); assert_authentication((int) $database->query('SELECT COUNT(*) FROM login_attempts')->fetchColumn() === 1, 'Login attempt tidak tersimpan.');
    $merchant = $database->query('SELECT id FROM merchants LIMIT 1')->fetchColumn();
    assert_authentication($merchant === false, 'Database auth test seharusnya tidak berisi merchant sampel.');
    $database->prepare("INSERT INTO import_batches (data_type, original_filename, file_sha256, status, imported_by_user_id) VALUES ('TRANSACTION', 'audit.xlsx', :hash, 'COMPLETED', :user_id)")->execute(['hash' => str_repeat('b', 64), 'user_id' => $userId]);
    assert_authentication((int) $database->query('SELECT imported_by_user_id FROM import_batches LIMIT 1')->fetchColumn() === $userId, 'Foreign key audit import tidak tersimpan.');
    $service = new UserService($database); $actor = ['public_id' => '00000000-0000-4000-8000-000000000001'];
    $created = $service->mutate('create', ['username' => 'viewer_test', 'email' => '', 'full_name' => 'Viewer Test', 'role' => 'viewer', 'password' => 'Viewer-Password-123', 'password_confirmation' => 'Viewer-Password-123'], $actor);
    assert_authentication($created['status'] === 'CREATED' && count($service->list()) === 2, 'UserService gagal membuat atau mendaftar user.');
    $service->mutate('reset_password', ['public_id' => $created['public_id'], 'password' => 'Viewer-New-Password-123', 'password_confirmation' => 'Viewer-New-Password-123'], $actor);
    $reset = $database->prepare('SELECT session_version, must_change_password FROM users WHERE public_id = :public_id'); $reset->execute(['public_id' => $created['public_id']]); $resetUser = $reset->fetch();
    assert_authentication((int) $resetUser['session_version'] === 2 && (int) $resetUser['must_change_password'] === 1, 'Reset password tidak memutus session atau mewajibkan perubahan password.');
    try { $service->mutate('update', ['public_id' => $actor['public_id'], 'full_name' => 'Auth Test Admin', 'email' => null, 'role' => 'viewer', 'is_active' => true], $actor); throw new RuntimeException('Self-demotion super admin seharusnya ditolak.'); }
    catch (RuntimeException $error) { assert_authentication(str_contains($error->getMessage(), 'tidak dapat menurunkan role'), 'Pesan self-protection super admin tidak sesuai.'); }
}

$server = authentication_server_connection();
try {
    prepare_authentication_database($server); run_authentication_scenarios(authentication_database_connection()); echo "AuthenticationIntegrationTest: OK\n";
} finally {
    if (str_ends_with(AUTH_TEST_DATABASE, '_test')) $server->exec('DROP DATABASE IF EXISTS `' . AUTH_TEST_DATABASE . '`');
}
