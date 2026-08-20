<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';

try {
    $config = auth_config(); start_auth_session($config); $database = database_connection();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); $action = trim((string) ($_GET['action'] ?? 'session'));
    if ($method === 'GET' && $action === 'session') {
        $user = current_auth_user($database); json_response(['authenticated' => $user !== null, 'user' => public_auth_user($user), 'csrf_token' => (string) $_SESSION[AUTH_CSRF_KEY]]);
    }
    if ($method === 'POST' && $action === 'login') {
        require_csrf(); $payload = auth_json_payload(); $login = trim((string) ($payload['login'] ?? '')); $password = (string) ($payload['password'] ?? '');
        if ($login === '' || strlen($login) > 190 || $password === '' || strlen($password) > 128) json_response(['error' => 'Username/email atau password salah.'], 401);
        $key = login_attempt_key($login, $config['secret']);
        $attempts = $database->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempt_key = :attempt_key AND attempted_at >= DATE_SUB(NOW(), INTERVAL :window SECOND)');
        $attempts->bindValue('attempt_key', $key); $attempts->bindValue('window', $config['attempt_window'], PDO::PARAM_INT); $attempts->execute();
        if ((int) $attempts->fetchColumn() >= $config['max_attempts']) json_response(['error' => 'Terlalu banyak percobaan login. Coba kembali beberapa saat lagi.'], 429);
        $statement = $database->prepare('SELECT u.*, r.code role FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.username = :username OR u.email = :email LIMIT 1');
        $statement->execute(['username' => $login, 'email' => $login]); $user = $statement->fetch();
        $dummy = '$2y$12$19KSi9DeiyYNA87FRKdpPeqH.Z/vJKfhQNMdYGP83DdtzC0exKr2m';
        if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, $user ? (string) $user['password_hash'] : $dummy)) {
            $database->prepare('INSERT INTO login_attempts (attempt_key) VALUES (:attempt_key)')->execute(['attempt_key' => $key]); usleep(random_int(150000, 300000)); json_response(['error' => 'Username/email atau password salah.'], 401);
        }
        $database->prepare('DELETE FROM login_attempts WHERE attempt_key = :attempt_key')->execute(['attempt_key' => $key]);
        $database->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $user['id']]); establish_auth_session($user);
        json_response(['authenticated' => true, 'user' => public_auth_user($user), 'csrf_token' => (string) $_SESSION[AUTH_CSRF_KEY]]);
    }
    if ($method === 'POST' && $action === 'logout') {
        require_auth_user($database); require_csrf(); clear_auth_session(); json_response(['authenticated' => false, 'user' => null, 'csrf_token' => (string) $_SESSION[AUTH_CSRF_KEY]]);
    }
    if ($method === 'POST' && $action === 'change-password') {
        $sessionUser = require_auth_user($database); require_csrf(); $payload = auth_json_payload();
        $current = (string) ($payload['current_password'] ?? ''); $new = (string) ($payload['new_password'] ?? ''); $confirmation = (string) ($payload['new_password_confirmation'] ?? '');
        if ($current === '' || strlen($current) > 128) throw new RuntimeException('Password saat ini tidak benar.');
        if (strlen($new) < 8 || strlen($new) > 128 || trim($new) === '') throw new RuntimeException('Password baru harus terdiri dari 8 sampai 128 karakter dan tidak boleh hanya spasi.');
        if (!hash_equals($new, $confirmation)) throw new RuntimeException('Konfirmasi password baru tidak sama.');
        $database->beginTransaction();
        try {
            $lock = $database->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = 1 FOR UPDATE'); $lock->execute(['id' => $sessionUser['id']]); $stored = $lock->fetch();
            if (!$stored || !password_verify($current, (string) $stored['password_hash'])) throw new RuntimeException('Password saat ini tidak benar.');
            if (password_verify($new, (string) $stored['password_hash'])) throw new RuntimeException('Password baru harus berbeda dari password saat ini.');
            $hash = password_hash($new, PASSWORD_DEFAULT); if ($hash === false) throw new RuntimeException('Password baru tidak dapat diamankan.');
            $update = $database->prepare('UPDATE users SET password_hash = :hash, must_change_password = 0, session_version = session_version + 1 WHERE id = :id'); $update->execute(['hash' => $hash, 'id' => $sessionUser['id']]);
            $database->commit();
        } catch (Throwable $error) { if ($database->inTransaction()) $database->rollBack(); throw $error; }
        clear_auth_session(); json_response(['authenticated' => false, 'user' => null, 'csrf_token' => (string) $_SESSION[AUTH_CSRF_KEY], 'message' => 'Password berhasil diubah. Silakan login kembali.']);
    }
    json_response(['error' => 'Method atau aksi autentikasi tidak didukung.'], 405);
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage()); json_response(['error' => 'Autentikasi gagal diproses.'], 500);
}
