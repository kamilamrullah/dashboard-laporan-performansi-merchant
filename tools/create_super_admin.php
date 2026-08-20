<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

/** Menghentikan CLI dengan pesan aman dan exit code kegagalan. */
function fail_admin_creation(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL); exit(1);
}

/** Membuat UUID v4 untuk identifier publik user. */
function create_user_public_id(): string
{
    $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

if (PHP_SAPI !== 'cli') fail_admin_creation('Tool ini hanya boleh dijalankan melalui CLI.');
$options = getopt('', ['username:', 'name:', 'email::']); $username = trim((string) ($options['username'] ?? '')); $name = trim((string) ($options['name'] ?? '')); $email = trim((string) ($options['email'] ?? ''));
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username) || $name === '' || mb_strlen($name) > 100 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) fail_admin_creation('Gunakan --username, --name, dan --email opsional yang valid.');
fwrite(STDOUT, 'Password awal (minimal 8 karakter): '); $password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 8 || strlen($password) > 128) fail_admin_creation('Password harus terdiri dari 8 sampai 128 karakter.');
$hash = password_hash($password, PASSWORD_DEFAULT); if ($hash === false) fail_admin_creation('Password tidak dapat diamankan.');
try {
    $database = database_connection(); $role = $database->query("SELECT id FROM roles WHERE code = 'super_admin' LIMIT 1")->fetchColumn(); if ($role === false) fail_admin_creation('Role super_admin belum tersedia.');
    $statement = $database->prepare('INSERT INTO users (public_id, username, email, full_name, password_hash, role_id, must_change_password) VALUES (:public_id, :username, :email, :full_name, :password_hash, :role_id, 1)');
    $statement->execute(['public_id' => create_user_public_id(), 'username' => $username, 'email' => $email === '' ? null : strtolower($email), 'full_name' => $name, 'password_hash' => $hash, 'role_id' => (int) $role]);
    fwrite(STDOUT, "Super admin {$username} berhasil dibuat dan wajib mengganti password saat login pertama." . PHP_EOL);
} catch (Throwable $error) { fail_admin_creation($error->getCode() === '23000' ? 'Username atau email sudah digunakan.' : 'Super admin gagal dibuat.'); }
