<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

const DUMMY_SEED_START = '2026-01-01';
const MERCHANT_A_CONTINUATION = '2026-04-01';

/** Menghentikan seed dengan pesan yang aman untuk terminal. */
function fail_dummy_seed(string $message): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

/** Membaca tanggal akhir dan mewajibkan flag apply agar seed tidak berjalan tanpa sengaja. */
function dummy_seed_options(): DateTimeImmutable
{
    $options = getopt('', ['until:', 'apply']);
    if (!isset($options['apply'])) fail_dummy_seed('Tambahkan --apply untuk mengisi database development.');
    $untilValue = is_string($options['until'] ?? null) ? $options['until'] : date('Y-m-d');
    $until = DateTimeImmutable::createFromFormat('!Y-m-d', $untilValue);
    if (!$until || $until->format('Y-m-d') !== $untilValue) fail_dummy_seed('Tanggal --until harus berformat YYYY-MM-DD.');
    if ($until < new DateTimeImmutable(DUMMY_SEED_START) || $until > new DateTimeImmutable('today')) fail_dummy_seed('Tanggal akhir harus antara ' . DUMMY_SEED_START . ' dan hari ini.');
    return $until;
}

/** Membuat atau memperbarui batch seed dan mengembalikan identifier database-nya. */
function upsert_dummy_batch(PDO $database, ?int $merchantId, string $key, string $type, string $start, string $end): int
{
    $hash = hash('sha256', 'merchant-performance-dummy-seed-v1|' . $key);
    $statement = $database->prepare("INSERT INTO import_batches (merchant_id, data_type, original_filename, file_sha256, detected_period_start, detected_period_end, status, imported_by, confirmed_at, completed_at) VALUES (:merchant_id, :data_type, :filename, :hash, :period_start, :period_end, 'COMPLETED', 'Dummy Seed', NOW(), NOW()) ON DUPLICATE KEY UPDATE merchant_id = VALUES(merchant_id), detected_period_start = VALUES(detected_period_start), detected_period_end = VALUES(detected_period_end), status = 'COMPLETED', completed_at = NOW()");
    $statement->execute(['merchant_id' => $merchantId, 'data_type' => $type, 'filename' => 'dummy-' . $key . '.xlsx', 'hash' => $hash, 'period_start' => $start, 'period_end' => $end]);
    $lookup = $database->prepare('SELECT id FROM import_batches WHERE file_sha256 = :hash LIMIT 1');
    $lookup->execute(['hash' => $hash]);
    return (int) $lookup->fetchColumn();
}

/** Membuat merchant dummy secara idempotent tanpa menonaktifkan merchant yang sudah ada. */
function upsert_dummy_merchant(PDO $database, string $code, string $name): int
{
    $statement = $database->prepare('INSERT INTO merchants (merchant_code, merchant_name, is_active) VALUES (:code, :name, 1) ON DUPLICATE KEY UPDATE is_active = 1');
    $statement->execute(['code' => $code, 'name' => $name]);
    $lookup = $database->prepare('SELECT id FROM merchants WHERE merchant_code = :code LIMIT 1');
    $lookup->execute(['code' => $code]);
    $id = (int) $lookup->fetchColumn();
    if ($id <= 0) throw new RuntimeException('Merchant dummy gagal disiapkan: ' . $code);
    return $id;
}

/** Menyiapkan mapping payment channel dummy dengan SIC khusus agar tidak menimpa mapping sumber. */
function seed_dummy_payment_channels(PDO $database, int $batchId): array
{
    $channels = ['9001' => 'ATM', '9002' => 'Mobile Banking', '9003' => 'Internet Banking', '9004' => 'Teller', '9005' => 'Mini ATM'];
    $statement = $database->prepare('INSERT INTO payment_channels (sic_code, channel_name, is_active, source_batch_id) VALUES (:sic, :name, 1, :batch_id) ON DUPLICATE KEY UPDATE channel_name = VALUES(channel_name), is_active = 1, source_batch_id = VALUES(source_batch_id)');
    foreach ($channels as $sic => $name) $statement->execute(['sic' => $sic, 'name' => $name, 'batch_id' => $batchId]);
    return $channels;
}

/** Menghasilkan angka deterministik agar seed ulang memberikan hasil laporan yang sama. */
function dummy_metric(int $merchantIndex, int $dayIndex, int $partnerIndex, int $channelIndex): array
{
    $seasonal = (int) round(32 * sin(($dayIndex + $merchantIndex * 5) / 9));
    $base = 620 + ($merchantIndex * 115) + ($partnerIndex * 73) + ($channelIndex * 41) + $seasonal + (($dayIndex % 7) * 13);
    $payment = max(1, $base);
    return [$payment + 45 + (($dayIndex + $channelIndex) % 37), $payment, 2 + (($dayIndex + $partnerIndex + $channelIndex) % 9), 1 + (($dayIndex + $merchantIndex + $channelIndex) % 5)];
}

/** Mengisi transaksi agregat harian untuk kombinasi partner dan payment channel. */
function seed_dummy_transactions(PDO $database, int $merchantId, int $merchantIndex, int $batchId, DateTimeImmutable $start, DateTimeImmutable $until, array $channels): int
{
    $partners = ['Partner Utama', 'Partner Regional', 'Partner Digital'];
    $statement = $database->prepare("INSERT INTO transaction_aggregates (merchant_id, transaction_date, datasource, transaction_type, ca_id, partner_channel, biller, sic_code, response_code, total_trx, total_amount, source_batch_id, source_row_number) VALUES (:merchant_id, :transaction_date, 'DUMMY_SEED_V1', :transaction_type, :ca_id, :partner_channel, :biller, :sic_code, :response_code, :total_trx, :total_amount, :batch_id, :source_row) ON DUPLICATE KEY UPDATE total_trx = VALUES(total_trx), total_amount = VALUES(total_amount), source_batch_id = VALUES(source_batch_id), source_row_number = VALUES(source_row_number)");
    $row = 1;
    $epoch = new DateTimeImmutable(DUMMY_SEED_START);
    for ($date = $start; $date <= $until; $date = $date->modify('+1 day')) {
        $dayIndex = (int) $epoch->diff($date)->format('%a');
        foreach ($partners as $partnerIndex => $partner) foreach (array_keys($channels) as $channelIndex => $sic) {
            [$inquiry, $payment, $failed, $timeout] = dummy_metric($merchantIndex, $dayIndex, $partnerIndex, $channelIndex);
            $common = ['merchant_id' => $merchantId, 'transaction_date' => $date->format('Y-m-d'), 'ca_id' => sprintf('CA-DEMO-%02d', $merchantIndex + 1), 'partner_channel' => $partner, 'biller' => sprintf('BILLER-DEMO-%02d', $merchantIndex + 1), 'sic_code' => $sic, 'batch_id' => $batchId];
            $rows = [['INQUIRY', '0', $inquiry, '0.00'], ['PAYMENT', '0', $payment, (string) ($payment * (75000 + $channelIndex * 12500))], ['PAYMENT', '68', $failed, '0.00'], ['PAYMENT', '82', $timeout, '0.00']];
            foreach ($rows as [$type, $responseCode, $total, $amount]) $statement->execute($common + ['transaction_type' => $type, 'response_code' => $responseCode, 'total_trx' => $total, 'total_amount' => $amount, 'source_row' => $row++]);
        }
    }
    return $row - 1;
}

/** Mengisi tiket dummy tanpa data pribadi menggunakan periode dari waktu pembukaan. */
function seed_dummy_tickets(PDO $database, int $merchantId, string $merchantCode, int $merchantIndex, int $batchId, DateTimeImmutable $start, DateTimeImmutable $until): int
{
    $segments = ['Transaksi', 'Settlement', 'Akses Aplikasi'];
    $categories = ['Payment gagal', 'Selisih nominal', 'Gangguan akses'];
    $statement = $database->prepare("INSERT INTO complaint_tickets (merchant_id, ticket_number, status, product, service, complaint_segment, category, opened_at, closed_at, last_updated_at, duration_raw, duration_minutes, response_time_minutes, type_description, classification_flag, responsible_unit, source_batch_id, source_row_number) VALUES (:merchant_id, :ticket_number, :status, 'Payment Gateway', :service, :segment, :category, :opened_at, :closed_at, :last_updated_at, :duration_raw, :duration_minutes, :response_minutes, 'Dummy complaint', :flag, 'Service Operation', :batch_id, :source_row) ON DUPLICATE KEY UPDATE status = VALUES(status), closed_at = VALUES(closed_at), last_updated_at = VALUES(last_updated_at), duration_raw = VALUES(duration_raw), duration_minutes = VALUES(duration_minutes), response_time_minutes = VALUES(response_time_minutes), source_batch_id = VALUES(source_batch_id), source_row_number = VALUES(source_row_number)");
    $row = 1;
    for ($date = $start; $date <= $until; $date = $date->modify('+7 days')) for ($sequence = 1; $sequence <= 2; $sequence++) {
        $age = (int) $date->diff($until)->format('%a');
        $status = $age <= 2 && $sequence === 2 ? 'Open' : ($age <= 5 ? 'In Progress' : 'Closed');
        $duration = 45 + (($row + $merchantIndex) % 9) * 35;
        $opened = $date->setTime(8 + $sequence * 2, 15);
        $closed = $status === 'Closed' ? $opened->modify('+' . $duration . ' minutes') : null;
        $statement->execute(['merchant_id' => $merchantId, 'ticket_number' => sprintf('DUMMY-%s-%s-%02d', $merchantCode, $date->format('Ymd'), $sequence), 'status' => $status, 'service' => $sequence === 1 ? 'Inquiry' : 'Payment', 'segment' => $segments[($row + $merchantIndex) % 3], 'category' => $categories[($row + $sequence) % 3], 'opened_at' => $opened->format('Y-m-d H:i:s'), 'closed_at' => $closed?->format('Y-m-d H:i:s'), 'last_updated_at' => ($closed ?? $until->setTime(17, 0))->format('Y-m-d H:i:s'), 'duration_raw' => $status === 'Closed' ? $duration . ' menit' : null, 'duration_minutes' => $status === 'Closed' ? $duration : null, 'response_minutes' => 5 + ($row % 24), 'flag' => $row % 11 === 0 ? 'INCIDENT_CANDIDATE' : 'COMPLAINT', 'batch_id' => $batchId, 'source_row' => $row++]);
    }
    return $row - 1;
}

/** Menambahkan insiden dummy bulanan terpisah dari tiket aduan. */
function seed_dummy_incidents(PDO $database, int $merchantId, string $merchantCode, DateTimeImmutable $start, DateTimeImmutable $until): int
{
    $statement = $database->prepare("INSERT INTO incidents (merchant_id, report_period, incident_date, title, summary, business_impact, root_cause, follow_up, source_type, created_by) SELECT :merchant_id, :report_period, :incident_date, :title, :summary, :impact, :root_cause, :follow_up, 'MANUAL', 'Dummy Seed' WHERE NOT EXISTS (SELECT 1 FROM incidents WHERE merchant_id = :merchant_id_check AND report_period = :report_period_check AND title = :title_check)");
    $count = 0;
    for ($period = $start->modify('first day of this month'); $period <= $until; $period = $period->modify('first day of next month')) {
        if ((int) $period->format('n') % 2 !== 0) continue;
        $title = 'Simulasi gangguan koneksi ' . $merchantCode . ' ' . $period->format('Y-m');
        $incidentDate = $period->modify('+9 days')->setTime(10, 30);
        if ($incidentDate > $until->setTime(23, 59)) continue;
        $statement->execute(['merchant_id' => $merchantId, 'report_period' => $period->format('Y-m-01'), 'incident_date' => $incidentDate->format('Y-m-d H:i:s'), 'title' => $title, 'summary' => 'Simulasi penurunan konektivitas pada lingkungan dummy.', 'impact' => 'Sebagian transaksi dummy mengalami keterlambatan.', 'root_cause' => 'Simulasi gangguan jalur komunikasi.', 'follow_up' => 'Monitoring dan validasi konektivitas dummy.', 'merchant_id_check' => $merchantId, 'report_period_check' => $period->format('Y-m-01'), 'title_check' => $title]);
        $count += $statement->rowCount();
    }
    return $count;
}

/** Memperbarui statistik batch agar riwayat import mencerminkan jumlah baris seed. */
function complete_dummy_batch(PDO $database, int $batchId, int $rows): void
{
    $statement = $database->prepare("UPDATE import_batches SET total_rows = :total_rows, valid_rows = :valid_rows, inserted_rows = :inserted_rows, updated_rows = 0, duplicate_rows = 0, rejected_rows = 0, status = 'COMPLETED', completed_at = NOW() WHERE id = :id");
    $statement->execute(['total_rows' => $rows, 'valid_rows' => $rows, 'inserted_rows' => $rows, 'id' => $batchId]);
}

$until = dummy_seed_options();
if (env_value('APP_ENV', 'development') !== 'development') fail_dummy_seed('Seed dummy hanya boleh dijalankan saat APP_ENV=development.');
$database = database_connection();
$database->beginTransaction();
try {
    $mappingBatch = upsert_dummy_batch($database, null, 'payment-channel-mapping', 'PAYMENT_CHANNEL', DUMMY_SEED_START, $until->format('Y-m-d'));
    $channels = seed_dummy_payment_channels($database, $mappingBatch);
    complete_dummy_batch($database, $mappingBatch, count($channels));
    $merchantDefinitions = [['MERCHANT_A', 'Merchant_A', MERCHANT_A_CONTINUATION], ['MERCHANT_B', 'Merchant B', DUMMY_SEED_START], ['MERCHANT_C', 'Merchant C', DUMMY_SEED_START]];
    $summary = [];
    foreach ($merchantDefinitions as $index => [$code, $name, $startValue]) {
        $start = new DateTimeImmutable($startValue);
        if ($start > $until) continue;
        $merchantId = upsert_dummy_merchant($database, $code, $name);
        $transactionBatch = upsert_dummy_batch($database, $merchantId, strtolower($code) . '-transactions', 'TRANSACTION', $start->format('Y-m-d'), $until->format('Y-m-d'));
        $ticketBatch = upsert_dummy_batch($database, $merchantId, strtolower($code) . '-tickets', 'TICKET', $start->format('Y-m-d'), $until->format('Y-m-d'));
        $transactionRows = seed_dummy_transactions($database, $merchantId, $index, $transactionBatch, $start, $until, $channels);
        $ticketRows = seed_dummy_tickets($database, $merchantId, $code, $index, $ticketBatch, $start, $until);
        $incidents = seed_dummy_incidents($database, $merchantId, $code, $start, $until);
        complete_dummy_batch($database, $transactionBatch, $transactionRows);
        complete_dummy_batch($database, $ticketBatch, $ticketRows);
        $summary[] = compact('code', 'transactionRows', 'ticketRows', 'incidents');
    }
    $database->commit();
    echo json_encode(['until' => $until->format('Y-m-d'), 'merchants' => $summary], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    if ($database->inTransaction()) $database->rollBack();
    fail_dummy_seed('Seed dummy gagal dan seluruh perubahan dibatalkan: ' . $error->getMessage());
}
