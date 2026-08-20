<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';
require_once __DIR__ . '/../backend/Auth/UserService.php';

use App\Auth\UserService;

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    [$database, $actor] = authorize_api_request(['super_admin'], $method !== 'GET');
    $service = new UserService($database);
    if ($method === 'GET' && ($_GET['resource'] ?? 'users') === 'roles') json_response(['items' => $service->roles()]);
    if ($method === 'GET') json_response(['items' => $service->list()]);
    if ($method !== 'POST') { header('Allow: GET, POST'); json_response(['error' => 'Method tidak diizinkan.'], 405); }
    $payload = auth_json_payload(); $action = (string) ($payload['action'] ?? '');
    json_response($service->mutate($action, $payload, $actor), $action === 'create' ? 201 : 200);
} catch (PDOException $error) {
    if ($error->getCode() === '23000') json_response(['error' => 'Username atau email sudah digunakan.'], 422);
    error_log($error->getMessage()); json_response(['error' => 'Manajemen user gagal diproses.'], 500);
} catch (RuntimeException $error) {
    json_response(['error' => $error->getMessage()], 422);
} catch (Throwable $error) {
    error_log($error->getMessage()); json_response(['error' => 'Manajemen user gagal diproses.'], 500);
}
