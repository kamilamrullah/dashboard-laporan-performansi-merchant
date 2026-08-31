<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';
require_once __DIR__ . '/../backend/Dashboard/DashboardTrendService.php';

use App\Dashboard\DashboardTrendService;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); json_response(['error' => 'Method tidak diizinkan.'], 405); }

try {
    $startedAt = microtime(true);
    [$database] = authorize_api_request(['super_admin', 'admin', 'viewer']);
    $filters = [];
    foreach (['merchant_id', 'partner_channel', 'payment_channel', 'transaction_type', 'response_code'] as $key) {
        if (isset($_GET[$key]) && trim((string) $_GET[$key]) !== '') $filters[$key] = $_GET[$key];
    }
    $result = (new DashboardTrendService($database))->trend(
        trim((string) ($_GET['period'] ?? '')),
        trim((string) ($_GET['granularity'] ?? 'monthly')),
        $filters,
    );
    header('Server-Timing: dashboard-trend;dur=' . number_format((microtime(true) - $startedAt) * 1000, 1, '.', ''));
    json_response($result);
} catch (InvalidArgumentException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Data tren dashboard gagal dimuat.'], 500);
}
