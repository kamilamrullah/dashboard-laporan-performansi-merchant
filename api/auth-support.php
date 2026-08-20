<?php
declare(strict_types=1);

const AUTH_USER_KEY = 'merchant_auth_user';
const AUTH_CSRF_KEY = 'merchant_csrf_token';
const AUTH_STARTED_KEY = 'merchant_auth_started';
const AUTH_ACTIVITY_KEY = 'merchant_auth_activity';
const AUTH_ROTATED_KEY = 'merchant_auth_rotated';

/** Mengambil konfigurasi autentikasi dari environment dan menolak secret yang lemah. */
function auth_config(): array
{
    $secret = env_value('APP_SECRET', '');
    if ($secret === null || strlen($secret) < 32) throw new RuntimeException('APP_SECRET minimal 32 karakter wajib dikonfigurasi.');
    return ['secret' => $secret, 'idle' => 1800, 'absolute' => 28800, 'rotate' => 900, 'max_attempts' => 5, 'attempt_window' => 900];
}

/** Menentukan apakah cookie wajib Secure berdasarkan koneksi HTTPS saat ini. */
function auth_secure_cookie(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** Memulai session cookie yang diperketat dan menerapkan batas masa hidup. */
function start_auth_session(array $config): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); ini_set('session.use_trans_sid', '0');
        session_name('merchant_report_session');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'domain' => '', 'secure' => auth_secure_cookie(), 'httponly' => true, 'samesite' => 'Lax']);
        if (!session_start()) throw new RuntimeException('Sesi login tidak dapat dimulai.');
    }
    if (!isset($_SESSION[AUTH_CSRF_KEY])) $_SESSION[AUTH_CSRF_KEY] = bin2hex(random_bytes(32));
    if (isset($_SESSION[AUTH_USER_KEY])) {
        $now = time(); $started = (int) ($_SESSION[AUTH_STARTED_KEY] ?? $now); $activity = (int) ($_SESSION[AUTH_ACTIVITY_KEY] ?? $now);
        if ($now - $activity > $config['idle'] || $now - $started > $config['absolute']) { clear_auth_session(); return; }
        $_SESSION[AUTH_ACTIVITY_KEY] = $now;
        if ($now - (int) ($_SESSION[AUTH_ROTATED_KEY] ?? 0) >= $config['rotate']) { session_regenerate_id(true); $_SESSION[AUTH_ROTATED_KEY] = $now; }
    }
}

/** Menghapus identitas session dan menerbitkan CSRF token baru. */
function clear_auth_session(): void
{
    $_SESSION = []; session_regenerate_id(true); $_SESSION[AUTH_CSRF_KEY] = bin2hex(random_bytes(32));
}

/** Memvalidasi CSRF token menggunakan perbandingan tahan timing attack. */
function require_csrf(): void
{
    $sent = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''); $stored = (string) ($_SESSION[AUTH_CSRF_KEY] ?? '');
    if ($sent === '' || $stored === '' || !hash_equals($stored, $sent)) json_response(['error' => 'Token keamanan tidak valid. Muat ulang halaman lalu coba lagi.'], 403);
}

/** Membaca JSON object dengan batas kedalaman yang wajar. */
function auth_json_payload(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') throw new RuntimeException('Body JSON wajib diisi.');
    try { $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (JsonException) { throw new RuntimeException('Body JSON tidak valid.'); }
    if (!is_array($payload)) throw new RuntimeException('Body JSON harus berupa object.');
    return $payload;
}

/** Mengambil user session dan memastikan akun, role, serta versi session masih berlaku. */
function current_auth_user(PDO $database): ?array
{
    $session = $_SESSION[AUTH_USER_KEY] ?? null;
    if (!is_array($session)) return null;
    $statement = $database->prepare('SELECT u.id, u.public_id, u.username, u.email, u.full_name, u.must_change_password, u.session_version, u.is_active, r.code role FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.id = :id');
    $statement->execute(['id' => (int) ($session['id'] ?? 0)]); $user = $statement->fetch();
    if (!$user || (int) $user['is_active'] !== 1 || (int) $user['session_version'] !== (int) ($session['session_version'] ?? 0) || (string) $user['role'] !== (string) ($session['role'] ?? '')) { clear_auth_session(); return null; }
    return $user;
}

/** Mewajibkan session aktif dan mengembalikan user database yang tervalidasi. */
function require_auth_user(PDO $database): array
{
    $user = current_auth_user($database);
    if ($user === null) json_response(['error' => 'Sesi login tidak tersedia atau sudah berakhir.'], 401);
    return $user;
}

/** Mewajibkan salah satu role yang diizinkan pada endpoint. */
function require_auth_role(PDO $database, string ...$roles): array
{
    $user = require_auth_user($database);
    if (!in_array((string) $user['role'], $roles, true)) json_response(['error' => 'Anda tidak memiliki izin untuk melakukan tindakan ini.'], 403);
    return $user;
}

/** Membentuk payload user publik tanpa password hash atau identifier internal. */
function public_auth_user(?array $user): ?array
{
    if ($user === null) return null;
    return ['public_id' => $user['public_id'], 'username' => $user['username'], 'email' => $user['email'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'must_change_password' => (bool) $user['must_change_password']];
}

/** Menyimpan identitas minimum ke session setelah login berhasil. */
function establish_auth_session(array $user): void
{
    session_regenerate_id(true); $now = time();
    $_SESSION[AUTH_USER_KEY] = ['id' => (int) $user['id'], 'role' => (string) $user['role'], 'session_version' => (int) $user['session_version']];
    $_SESSION[AUTH_STARTED_KEY] = $now; $_SESSION[AUTH_ACTIVITY_KEY] = $now; $_SESSION[AUTH_ROTATED_KEY] = $now; $_SESSION[AUTH_CSRF_KEY] = bin2hex(random_bytes(32));
}

/** Menghasilkan kunci rate limit yang tidak menyimpan username atau alamat IP mentah. */
function login_attempt_key(string $login, string $secret): string
{
    return hash_hmac('sha256', strtolower(trim($login)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $secret);
}

/** Menyiapkan database/session, memeriksa role, dan opsional memvalidasi CSRF request. */
function authorize_api_request(array $roles, bool $csrf = false): array
{
    $config = auth_config(); start_auth_session($config); $database = database_connection();
    $user = require_auth_role($database, ...$roles);
    if ($csrf) require_csrf();
    return [$database, $user];
}
