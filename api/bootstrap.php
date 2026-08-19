<?php
declare(strict_types=1);

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

