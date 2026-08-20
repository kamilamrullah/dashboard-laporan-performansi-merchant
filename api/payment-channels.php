<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../backend/MasterData/PaymentChannelService.php';

use App\MasterData\PaymentChannelService;

/** Membaca body JSON untuk aksi mutasi master payment channel. */
function payment_channel_request_payload(): array
{
    $contents = file_get_contents('php://input');
    if ($contents === false || $contents === '') throw new RuntimeException('Body JSON wajib diisi.');
    try { $payload = json_decode($contents, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new RuntimeException('Body JSON tidak valid.'); }
    if (!is_array($payload)) throw new RuntimeException('Body JSON harus berupa object.');
    return $payload;
}

/** Membaca parameter integer GET dalam rentang yang aman untuk pagination. */
function payment_channel_integer_parameter(string $name, int $default, int $maximum): int
{
    $value = filter_var($_GET[$name] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
    if ($value === false) throw new RuntimeException("Parameter {$name} tidak valid.");
    return (int) $value;
}

try {
    $service = new PaymentChannelService(database_connection());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $historySic = trim((string) ($_GET['history_sic_code'] ?? ''));
        if ($historySic !== '') json_response($service->history($historySic));
        json_response($service->list(trim((string) ($_GET['search'] ?? '')), (string) ($_GET['status'] ?? 'all'), payment_channel_integer_parameter('page', 1, 1000000), payment_channel_integer_parameter('per_page', 20, 100)));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: GET, POST'); json_response(['error' => 'Method tidak diizinkan.'], 405); }
    if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) json_response(['error' => 'Content-Type harus application/json.'], 415);
    $payload = payment_channel_request_payload();
    $action = (string) ($payload['action'] ?? '');
    json_response($service->mutate($action, $payload), $action === 'create' ? 201 : 200);
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Master payment channel gagal diproses.'], 500);
}
