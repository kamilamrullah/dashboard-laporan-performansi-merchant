<?php
declare(strict_types=1);

/** Memuat pasangan KEY=VALUE dari .env lokal tanpa menimpa environment server yang sudah tersedia. */
function load_local_environment(string $path): void
{
    if (!is_file($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) throw new RuntimeException('File environment tidak dapat dibaca.');
    foreach ($lines as $index => $line) {
        $line = trim($index === 0 ? ltrim($line, "\xEF\xBB\xBF") : $line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) throw new RuntimeException('Format file environment tidak valid.');
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z_][A-Z0-9_]*$/', $key)) throw new RuntimeException('Nama environment variable tidak valid.');
        if (getenv($key) !== false) continue;
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) $value = substr($value, 1, -1);
        putenv($key . '=' . $value); $_ENV[$key] = $value;
    }
}

load_local_environment(dirname(__DIR__) . '/.env');

/** Mengambil environment variable dan menggunakan default khusus development bila tidak tersedia. */
function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false ? $default : $value;
}

/** Membuat koneksi PDO dengan error exception dan prepared statement native. */
function database_connection(): PDO
{
    $environment = env_value('APP_ENV', 'development');
    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', '3306');
    $database = env_value('DB_NAME', 'merchant_performance_report');
    $username = env_value('DB_USER', $environment === 'development' ? 'root' : null);
    $password = env_value('DB_PASSWORD', $environment === 'development' ? '' : null);

    if (!$host || !$port || !$database || $username === null || $password === null) {
        throw new RuntimeException('Konfigurasi database belum lengkap. Periksa environment variable aplikasi.');
    }
    if (!ctype_digit($port)) {
        throw new RuntimeException('DB_PORT harus berupa angka.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
    return new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/** Mengirim response JSON konsisten dan menghentikan proses request. */
function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
