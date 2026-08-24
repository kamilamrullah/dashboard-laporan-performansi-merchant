<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';

/** Mengelompokkan periode transaksi yang dapat dibuat laporan berdasarkan merchant aktif. */
function report_option_merchants(PDO $database): array
{
    $statement = $database->query(
        "SELECT m.id, m.merchant_code, m.merchant_name, DATE_FORMAT(t.transaction_date, '%Y-%m-01') report_period
         FROM merchants m
         JOIN transaction_aggregates t ON t.merchant_id = m.id
         WHERE m.is_active = 1
         GROUP BY m.id, m.merchant_code, m.merchant_name, report_period
         HAVING SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND t.response_code = '0' THEN t.total_trx ELSE 0 END) > 0
         ORDER BY m.merchant_name ASC, report_period DESC"
    );
    $merchants = [];
    foreach ($statement->fetchAll() as $row) {
        $id = (int) $row['id'];
        if (!isset($merchants[$id])) $merchants[$id] = ['id' => $id, 'merchant_code' => (string) $row['merchant_code'], 'merchant_name' => (string) $row['merchant_name'], 'periods' => []];
        $merchants[$id]['periods'][] = (string) $row['report_period'];
    }
    return array_values($merchants);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); json_response(['error' => 'Method tidak diizinkan.'], 405); }

try {
    [$database] = authorize_api_request(['super_admin', 'admin']);
    json_response(['merchants' => report_option_merchants($database)]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Pilihan laporan gagal dimuat.'], 500);
}
