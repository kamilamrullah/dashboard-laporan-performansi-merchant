<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth-support.php';

/** Memvalidasi parameter tanggal ISO dan mengembalikan null ketika tidak diberikan. */
function date_parameter(string $name): ?string
{
    $value = $_GET[$name] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        json_response(['error' => "Parameter {$name} tidak valid."], 422);
    }
    return $date->format('Y-m-d');
}

/** Memvalidasi periode bulanan YYYY-MM dan mengembalikan null ketika belum diberikan. */
function month_parameter(string $name): ?DateTimeImmutable
{
    $value = trim((string) ($_GET[$name] ?? ''));
    if ($value === '') return null;
    $period = DateTimeImmutable::createFromFormat('!Y-m', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$period || $period->format('Y-m') !== $value || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        json_response(['error' => "Parameter {$name} harus berformat YYYY-MM."], 422);
    }
    return $period;
}

/** Memvalidasi parameter filter teks agar panjang dan bentuk input tetap terkendali. */
function text_parameter(string $name, int $maxLength = 160): ?string
{
    $value = trim((string) ($_GET[$name] ?? ''));
    if ($value === '') {
        return null;
    }
    if (mb_strlen($value) > $maxLength) {
        json_response(['error' => "Parameter {$name} terlalu panjang."], 422);
    }
    return $value;
}

/** Menyusun kondisi SQL dashboard dan memakai bulan terbaru ketika request awal belum memiliki periode. */
function dashboard_filters(array $availablePeriod): array
{
    $conditions = [];
    $parameters = [];
    $period = month_parameter('period');
    $dateFrom = date_parameter('date_from');
    $dateTo = date_parameter('date_to');
    if ($period !== null && ($dateFrom !== null || $dateTo !== null)) json_response(['error' => 'Gunakan period atau filter tanggal lama, bukan keduanya.'], 422);
    if ($period !== null) {
        $dateFrom = $period->format('Y-m-01');
        $dateTo = $period->modify('last day of this month')->format('Y-m-d');
    } elseif ($dateFrom === null && $dateTo === null && !empty($availablePeriod['date_to'])) {
        $latestDate = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $availablePeriod['date_to']);
        if (!$latestDate) throw new RuntimeException('Periode terbaru dashboard tidak valid.');
        $dateFrom = $latestDate->modify('first day of this month')->format('Y-m-d');
        if (!empty($availablePeriod['date_from']) && $dateFrom < (string) $availablePeriod['date_from']) $dateFrom = (string) $availablePeriod['date_from'];
        $dateTo = $latestDate->format('Y-m-d');
    }
    if ($dateFrom !== null) { $conditions[] = 't.transaction_date >= :date_from'; $parameters['date_from'] = $dateFrom; }
    if ($dateTo !== null) { $conditions[] = 't.transaction_date <= :date_to'; $parameters['date_to'] = $dateTo; }
    if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
        json_response(['error' => 'Tanggal awal tidak boleh melebihi tanggal akhir.'], 422);
    }

    $merchant = filter_input(INPUT_GET, 'merchant_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (isset($_GET['merchant_id']) && $_GET['merchant_id'] !== '' && $merchant === false) {
        json_response(['error' => 'Merchant tidak valid.'], 422);
    }
    if ($merchant !== null && $merchant !== false) { $conditions[] = 't.merchant_id = :merchant_id'; $parameters['merchant_id'] = $merchant; }

    foreach (['partner_channel', 'payment_channel', 'transaction_type', 'response_code'] as $field) {
        $value = text_parameter($field);
        if ($value === null) continue;
        $column = match ($field) {
            'partner_channel' => 't.partner_channel', 'payment_channel' => "COALESCE(pc.channel_name, CONCAT('SIC ', t.sic_code))",
            'transaction_type' => 't.transaction_type', 'response_code' => 't.response_code',
        };
        $conditions[] = "{$column} = :{$field}";
        $parameters[$field] = $value;
    }
    return [$conditions === [] ? '1=1' : implode(' AND ', $conditions), $parameters];
}

/** Menjalankan query SELECT terparameterisasi dan mengembalikan seluruh hasil. */
function fetch_all(PDO $database, string $sql, array $parameters = []): array
{
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

/** Menjalankan query SELECT terparameterisasi dan mengembalikan satu baris. */
function fetch_one(PDO $database, string $sql, array $parameters = []): array
{
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    $result = $statement->fetch();
    return $result === false ? [] : $result;
}

/** Membuat snapshot terfilter per request agar klasifikasi sukses hanya dihitung satu kali untuk seluruh widget. */
function create_dashboard_snapshot(PDO $database, string $where, array $parameters, string $successCondition): void
{
    $database->exec('DROP TEMPORARY TABLE IF EXISTS dashboard_filtered_transactions');
    $statement = $database->prepare(
        "CREATE TEMPORARY TABLE dashboard_filtered_transactions ENGINE=InnoDB AS
         SELECT t.transaction_date, t.transaction_type, t.partner_channel, t.sic_code, t.response_code,
                t.total_trx, t.total_amount,
                COALESCE(pc.channel_name, CONCAT('SIC ', t.sic_code)) payment_channel_name,
                CASE WHEN {$successCondition} THEN 1 ELSE 0 END is_success
         FROM transaction_aggregates t
         LEFT JOIN payment_channels pc ON pc.sic_code = t.sic_code
         WHERE {$where}"
    );
    $statement->execute($parameters);
}

try {
    $startedAt = microtime(true);
    [$database] = authorize_api_request(['super_admin', 'admin', 'viewer']);
    $availablePeriod = fetch_one($database, 'SELECT MIN(transaction_date) date_from, MAX(transaction_date) date_to FROM transaction_aggregates');
    [$where, $parameters] = dashboard_filters($availablePeriod);
    $successCondition = "EXISTS (
        SELECT 1 FROM response_code_rules rules
        WHERE rules.response_code = t.response_code
          AND rules.status_group = 'SUCCESS'
          AND rules.is_active = 1
          AND (rules.transaction_type = '' OR rules.transaction_type = t.transaction_type)
          AND rules.effective_from <= t.transaction_date
          AND (rules.effective_until IS NULL OR rules.effective_until >= t.transaction_date)
    )";
    create_dashboard_snapshot($database, $where, $parameters, $successCondition);
    $join = ' FROM dashboard_filtered_transactions t ';
    $successCondition = 't.is_success = 1';

    $summary = fetch_one($database,
        "SELECT COALESCE(SUM(t.total_trx), 0) total_trx,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'INQUIRY' AND {$successCondition} THEN t.total_trx ELSE 0 END), 0) total_inquiry,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successCondition} THEN t.total_trx ELSE 0 END), 0) total_payment,
                COALESCE(SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successCondition} THEN t.total_amount ELSE 0 END), 0) payment_amount,
                COUNT(*) aggregate_rows, MIN(t.transaction_date) period_start, MAX(t.transaction_date) period_end
         {$join}");

    $daily = fetch_all($database,
        "SELECT DATE_FORMAT(t.transaction_date, '%Y-%m-%d') transaction_date,
                SUM(CASE WHEN t.transaction_type = 'INQUIRY' AND {$successCondition} THEN t.total_trx ELSE 0 END) inquiry,
                SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successCondition} THEN t.total_trx ELSE 0 END) payment,
                SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successCondition} THEN t.total_amount ELSE 0 END) payment_amount
         {$join} GROUP BY t.transaction_date ORDER BY t.transaction_date");

    $partners = fetch_all($database,
        "SELECT t.partner_channel name,
                SUM(CASE WHEN {$successCondition} THEN t.total_trx ELSE 0 END) total_trx,
                SUM(CASE WHEN t.transaction_type = 'INQUIRY' AND {$successCondition} THEN t.total_trx ELSE 0 END) inquiry,
                SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successCondition} THEN t.total_trx ELSE 0 END) payment,
                SUM(CASE WHEN t.transaction_type = 'PAYMENT' AND {$successCondition} THEN t.total_amount ELSE 0 END) payment_amount
         {$join} GROUP BY t.partner_channel ORDER BY total_trx DESC, name ASC LIMIT 15");

    $paymentChannels = fetch_all($database,
        "SELECT t.sic_code, t.payment_channel_name name,
                SUM(CASE WHEN {$successCondition} THEN t.total_trx ELSE 0 END) total_trx,
                SUM(CASE WHEN {$successCondition} THEN t.total_amount ELSE 0 END) total_amount
         {$join} GROUP BY t.sic_code, t.payment_channel_name ORDER BY total_trx DESC, name ASC LIMIT 15");

    $responseCodes = fetch_all($database,
        "SELECT t.response_code code, SUM(t.total_trx) total_trx,
                SUM(CASE WHEN t.transaction_type = 'INQUIRY' THEN t.total_trx ELSE 0 END) inquiry,
                SUM(CASE WHEN t.transaction_type = 'PAYMENT' THEN t.total_trx ELSE 0 END) payment
         {$join} GROUP BY t.response_code ORDER BY total_trx DESC, code ASC");

    $options = [
        'merchants' => fetch_all($database, 'SELECT id, merchant_code, merchant_name FROM merchants WHERE is_active = 1 ORDER BY merchant_name'),
        'partner_channels' => array_column(fetch_all($database, 'SELECT DISTINCT partner_channel value FROM transaction_aggregates ORDER BY partner_channel'), 'value'),
        'payment_channels' => array_column(fetch_all($database, "SELECT DISTINCT COALESCE(pc.channel_name, CONCAT('SIC ', t.sic_code)) value FROM transaction_aggregates t LEFT JOIN payment_channels pc ON pc.sic_code = t.sic_code ORDER BY value"), 'value'),
        'transaction_types' => array_column(fetch_all($database, 'SELECT DISTINCT transaction_type value FROM transaction_aggregates ORDER BY transaction_type'), 'value'),
        'response_codes' => array_column(fetch_all($database, 'SELECT DISTINCT response_code value FROM transaction_aggregates ORDER BY response_code'), 'value'),
        'periods' => fetch_all($database, "SELECT DATE_FORMAT(transaction_date, '%Y-%m') value, MIN(transaction_date) date_from, MAX(transaction_date) date_to FROM transaction_aggregates GROUP BY DATE_FORMAT(transaction_date, '%Y-%m') ORDER BY value DESC"),
        'available_period' => $availablePeriod,
    ];

    header('Server-Timing: dashboard;dur=' . number_format((microtime(true) - $startedAt) * 1000, 1, '.', ''));
    json_response(['summary' => $summary, 'daily' => $daily, 'partners' => $partners, 'payment_channels' => $paymentChannels, 'response_codes' => $responseCodes, 'options' => $options]);
} catch (Throwable $error) {
    error_log($error->getMessage());
    json_response(['error' => 'Data dashboard gagal dimuat.'], 500);
}
