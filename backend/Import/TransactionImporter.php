<?php
declare(strict_types=1);

namespace App\Import;

use PDO;
use RuntimeException;
use Throwable;

final class TransactionImporter
{
    private const NATURAL_KEY_FIELDS = ['transaction_date', 'datasource', 'transaction_type', 'ca_id', 'partner_channel', 'biller', 'sic_code', 'response_code'];

    /** Menyimpan koneksi database dan reader workbook yang digunakan sepanjang proses import. */
    public function __construct(private readonly PDO $database, private readonly TransactionWorkbookReader $reader)
    {
    }

    /** Memvalidasi workbook, membandingkannya dengan database, dan menyimpan hasil preview sebagai staging. */
    public function preview(string $filePath, string $originalFilename, string $merchantCode, string $merchantName): array
    {
        if (strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new RuntimeException('File transaksi harus berformat XLSX.');
        }
        $hash = hash_file('sha256', $filePath);
        if ($hash === false) throw new RuntimeException('Hash file tidak dapat dihitung.');
        $existingBatch = $this->findBatchByHash($hash);
        $replaceableBatchId = null;
        if ($existingBatch !== null && $existingBatch['status'] !== 'PREVIEWED') {
            return ['status' => 'IDENTICAL_FILE', 'batch_id' => (int) $existingBatch['id'], 'batch_status' => $existingBatch['status'], 'message' => 'File identik sudah pernah selesai di-import.'];
        }
        if ($existingBatch !== null) $replaceableBatchId = (int) $existingBatch['id'];

        $inspection = $this->reader->inspectTransactions($filePath);
        if ($inspection['total_rows'] === 0) throw new RuntimeException('Workbook tidak memiliki baris transaksi.');
        $token = bin2hex(random_bytes(32));
        $rows = $inspection['rows'];
        $periodStart = $rows === [] ? null : min(array_column($rows, 'transaction_date'));
        $periodEnd = $rows === [] ? null : max(array_column($rows, 'transaction_date'));

        $this->database->beginTransaction();
        try {
            if ($replaceableBatchId !== null) $this->deleteReplaceablePreview($replaceableBatchId, $hash);
            $merchantId = $this->upsertMerchant($merchantCode, $merchantName);
            $batchId = $this->createPreviewBatch($merchantId, $originalFilename, $hash, $periodStart, $periodEnd, (int) $inspection['total_rows'], hash('sha256', $token));
            $summary = $this->stageRows($batchId, $merchantId, $rows, $inspection['invalid_rows']);
            $this->updatePreviewBatch($batchId, count($rows), $summary);
            $this->database->commit();
            $previewPage = $this->previewRows($batchId, $token, 1, 50, null);
            return ['status' => 'PREVIEWED', 'batch_id' => $batchId, 'original_filename' => mb_substr(basename(str_replace('\\', '/', $originalFilename)), 0, 255), 'confirmation_token' => $token, 'confirmation_expires_at' => date('c', time() + 86400), 'period_start' => $periodStart, 'period_end' => $periodEnd, 'summary' => $summary, 'rows' => $previewPage['items'], 'pagination' => $previewPage['pagination']];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** Mengonfirmasi batch preview dan menerapkan insert serta perubahan yang secara eksplisit disetujui. */
    public function confirm(int $batchId, string $token, bool $updateChangedRows, ?int $confirmedByUserId = null): array
    {
        $this->database->beginTransaction();
        try {
            $batch = $this->lockPreviewBatch($batchId);
            $this->validateConfirmation($batch, $token);
            $this->markBatchProcessing($batchId, $confirmedByUserId);
            $rows = $this->loadStagedRows($batchId);
            $stats = ['inserted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected' => 0];
            foreach ($rows as $stagedRow) {
                $outcome = (string) $stagedRow['outcome'];
                if ($outcome === 'READY') {
                    $result = $this->insertReadyRow((int) $batch['merchant_id'], $batchId, $stagedRow);
                } elseif ($outcome === 'CHANGED' && $updateChangedRows) {
                    $result = $this->updateChangedRow((int) $batch['merchant_id'], $batchId, $stagedRow, $confirmedByUserId);
                } elseif ($outcome === 'CHANGED') {
                    $result = 'SKIPPED_CHANGE';
                    $this->updateStagedOutcome((int) $stagedRow['id'], $result, $stagedRow['transaction_id'] === null ? null : (int) $stagedRow['transaction_id']);
                } else {
                    $result = $outcome;
                }
                $this->incrementStats($stats, $result);
            }
            $this->completeBatch($batchId, $stats);
            $this->database->commit();
            return ['status' => 'COMPLETED', 'batch_id' => $batchId, ...$stats];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** Mengambil satu halaman staging preview setelah memvalidasi token dan filter outcome. */
    public function previewRows(int $batchId, string $token, int $page, int $perPage, ?string $outcome): array
    {
        $allowedOutcomes = ['READY', 'CHANGED', 'DUPLICATE_IN_FILE', 'DUPLICATE_DATABASE', 'CONFLICT_IN_FILE', 'INVALID'];
        if ($page < 1 || $perPage < 1 || $perPage > 100 || ($outcome !== null && !in_array($outcome, $allowedOutcomes, true))) {
            throw new RuntimeException('Parameter halaman atau filter preview tidak valid.');
        }
        $batch = $this->findPreviewBatch($batchId);
        $this->validateConfirmation($batch, $token);
        $filterSql = $outcome === null ? '' : ' AND outcome = :outcome';
        $parameters = ['batch_id' => $batchId];
        if ($outcome !== null) $parameters['outcome'] = $outcome;
        $count = $this->database->prepare('SELECT COUNT(*) FROM transaction_import_rows WHERE batch_id = :batch_id' . $filterSql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $statement = $this->database->prepare(
            "SELECT id, source_row_number, outcome, normalized_data, existing_data, validation_errors
             FROM transaction_import_rows WHERE batch_id = :batch_id{$filterSql}
             ORDER BY CASE outcome WHEN 'CHANGED' THEN 0 WHEN 'INVALID' THEN 1 WHEN 'CONFLICT_IN_FILE' THEN 1
                       WHEN 'DUPLICATE_IN_FILE' THEN 2 WHEN 'DUPLICATE_DATABASE' THEN 2 WHEN 'READY' THEN 3 ELSE 4 END,
                      source_row_number LIMIT :limit OFFSET :offset"
        );
        foreach ($parameters as $name => $value) $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $paymentChannels = $this->loadPaymentChannels();
        $items = [];
        foreach ($statement->fetchAll() as $staged) {
            $normalized = $staged['normalized_data'] === null ? null : $this->decodeJson((string) $staged['normalized_data']);
            $existing = $staged['existing_data'] === null ? null : $this->decodeJson((string) $staged['existing_data']);
            $errors = $staged['validation_errors'] === null ? null : $this->decodeJson((string) $staged['validation_errors']);
            $paymentChannel = $normalized === null ? null : ($paymentChannels[$normalized['sic_code']] ?? null);
            $items[] = $this->previewPayload((int) $staged['id'], (int) $staged['source_row_number'], (string) $staged['outcome'], $normalized, $existing, $errors, $paymentChannel);
        }
        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))]];
    }

    /** Menghapus batch preview terautentikasi beserta staging-nya jika belum memiliki transaksi aktif. */
    public function deletePreview(int $batchId, string $token): array
    {
        $this->database->beginTransaction();
        try {
            $batch = $this->lockPreviewBatch($batchId);
            $this->validateConfirmation($batch, $token);
            $statement = $this->database->prepare(
                "DELETE FROM import_batches WHERE id = :id AND status = 'PREVIEWED'
                 AND NOT EXISTS (SELECT 1 FROM transaction_aggregates WHERE source_batch_id = :transaction_batch_id)"
            );
            $statement->execute(['id' => $batchId, 'transaction_batch_id' => $batchId]);
            if ($statement->rowCount() !== 1) throw new RuntimeException('Preview tidak dapat dihapus karena sudah memiliki transaksi aktif.');
            $this->database->commit();
            return ['status' => 'DELETED', 'batch_id' => $batchId];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** Menghapus batch PREVIEWED melewati masa retensi dalam jumlah terbatas tanpa menyentuh data aktif. */
    public function cleanupExpiredPreviews(int $retentionDays = 7, int $limit = 1000): array
    {
        if ($retentionDays < 1 || $retentionDays > 90 || $limit < 1 || $limit > 5000) {
            throw new RuntimeException('Parameter cleanup preview tidak valid.');
        }
        $this->database->beginTransaction();
        try {
            $statement = $this->database->query(
                "SELECT b.id FROM import_batches b
                 WHERE b.data_type = 'TRANSACTION' AND b.status = 'PREVIEWED'
                   AND b.created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)
                   AND NOT EXISTS (SELECT 1 FROM transaction_aggregates t WHERE t.source_batch_id = b.id)
                 ORDER BY b.created_at, b.id LIMIT {$limit} FOR UPDATE"
            );
            $batchIds = array_map('intval', array_column($statement->fetchAll(), 'id'));
            if ($batchIds !== []) {
                $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
                $delete = $this->database->prepare("DELETE FROM import_batches WHERE status = 'PREVIEWED' AND id IN ({$placeholders})");
                $delete->execute($batchIds);
                $deleted = $delete->rowCount();
            } else {
                $deleted = 0;
            }
            $this->database->commit();
            return ['status' => 'COMPLETED', 'retention_days' => $retentionDays, 'deleted_batches' => $deleted];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** Menyediakan kompatibilitas CLI lama dengan preview lalu konfirmasi tanpa mengganti konflik. */
    public function import(string $filePath, string $merchantCode, string $merchantName, ?string $mappingFile = null): array
    {
        if ($mappingFile !== null) {
            $mapping = $this->reader->readPaymentChannels($mappingFile);
            $this->database->beginTransaction();
            try {
                $this->upsertPaymentChannels($mapping);
                $this->database->commit();
            } catch (Throwable $error) {
                if ($this->database->inTransaction()) $this->database->rollBack();
                throw $error;
            }
        }
        $preview = $this->preview($filePath, basename($filePath), $merchantCode, $merchantName);
        if ($preview['status'] !== 'PREVIEWED') return $preview;
        return $this->confirm((int) $preview['batch_id'], (string) $preview['confirmation_token'], false, null);
    }

    /** Menyimpan mapping SIC code secara idempotent untuk mempertahankan kompatibilitas import CLI. */
    private function upsertPaymentChannels(array $mapping): void
    {
        $statement = $this->database->prepare('INSERT INTO payment_channels (sic_code, channel_name) VALUES (:code, :name) ON DUPLICATE KEY UPDATE channel_name = VALUES(channel_name), is_active = 1');
        foreach ($mapping as $code => $name) {
            $statement->execute(['code' => (string) $code, 'name' => trim((string) $name)]);
        }
    }

    /** Mencari batch berdasarkan hash untuk mencegah pemrosesan file identik. */
    private function findBatchByHash(string $hash): ?array
    {
        $statement = $this->database->prepare('SELECT id, status FROM import_batches WHERE file_sha256 = :hash LIMIT 1');
        $statement->execute(['hash' => $hash]);
        $result = $statement->fetch();
        return $result === false ? null : $result;
    }

    /** Menghapus preview lama tanpa transaksi aktif agar file yang belum dikonfirmasi dapat diproses ulang. */
    private function deleteReplaceablePreview(int $batchId, string $hash): void
    {
        $statement = $this->database->prepare(
            "DELETE FROM import_batches
             WHERE id = :id AND file_sha256 = :hash AND status = 'PREVIEWED'
               AND NOT EXISTS (SELECT 1 FROM transaction_aggregates WHERE source_batch_id = :transaction_batch_id)"
        );
        $statement->execute(['id' => $batchId, 'hash' => $hash, 'transaction_batch_id' => $batchId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Preview lama sedang diproses atau sudah memiliki transaksi aktif. Muat ulang status import.');
        }
    }

    /** Membuat atau memperbarui master merchant dan mengembalikan ID-nya. */
    private function upsertMerchant(string $code, string $name): int
    {
        $code = trim($code); $name = trim($name);
        if ($code === '' || $name === '' || mb_strlen($code) > 64 || mb_strlen($name) > 160) throw new RuntimeException('Kode atau nama merchant tidak valid.');
        $statement = $this->database->prepare('INSERT INTO merchants (merchant_code, merchant_name) VALUES (:code, :name) ON DUPLICATE KEY UPDATE merchant_name = VALUES(merchant_name), id = LAST_INSERT_ID(id)');
        $statement->execute(['code' => $code, 'name' => $name]);
        return (int) $this->database->lastInsertId();
    }

    /** Membuat batch berstatus preview dengan token konfirmasi yang sudah di-hash. */
    private function createPreviewBatch(int $merchantId, string $filename, string $hash, ?string $start, ?string $end, int $totalRows, string $tokenHash): int
    {
        $safeFilename = mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255);
        $statement = $this->database->prepare("INSERT INTO import_batches (merchant_id, data_type, original_filename, file_sha256, detected_period_start, detected_period_end, total_rows, status, confirmation_token_hash, confirmation_expires_at) VALUES (:merchant_id, 'TRANSACTION', :filename, :hash, :period_start, :period_end, :total_rows, 'PREVIEWED', :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
        $statement->execute(['merchant_id' => $merchantId, 'filename' => $safeFilename, 'hash' => $hash, 'period_start' => $start, 'period_end' => $end, 'total_rows' => $totalRows, 'token_hash' => $tokenHash]);
        return (int) $this->database->lastInsertId();
    }

    /** Mengklasifikasikan dan menyimpan seluruh baris valid maupun invalid untuk preview. */
    private function stageRows(int $batchId, int $merchantId, array $rows, array $invalidRows): array
    {
        $summary = ['total' => count($rows) + count($invalidRows), 'ready' => 0, 'changed' => 0, 'duplicate_in_file' => 0, 'duplicate_database' => 0, 'conflict_in_file' => 0, 'invalid' => 0];
        $seenFingerprints = []; $seenNaturalKeys = [];
        foreach ($rows as $row) {
            $fingerprint = $this->reader->fingerprint($row); $naturalKey = $this->reader->naturalKey($row); $existing = null;
            if (isset($seenFingerprints[$fingerprint])) $outcome = 'DUPLICATE_IN_FILE';
            elseif (isset($seenNaturalKeys[$naturalKey])) $outcome = 'CONFLICT_IN_FILE';
            else {
                $existing = $this->findTransaction($merchantId, $row, false);
                $outcome = $existing === null ? 'READY' : ($this->hasSameTotals($existing, $row) ? 'DUPLICATE_DATABASE' : 'CHANGED');
            }
            $seenFingerprints[$fingerprint] = true; $seenNaturalKeys[$naturalKey] = true;
            $transactionId = $existing === null ? null : (int) $existing['id'];
            $this->insertStagedRow($batchId, $row['source_row_number'], $transactionId, $fingerprint, $outcome, null, $row, $existing);
            $summary[strtolower($outcome)]++;
        }
        foreach ($invalidRows as $invalid) {
            $errors = ['message' => $invalid['message']];
            $this->insertStagedRow($batchId, $invalid['source_row_number'], null, hash('sha256', $invalid['source_row_number'] . '|' . $invalid['message']), 'INVALID', $errors, null, null);
            $summary['invalid']++;
        }
        return $summary;
    }

    /** Memuat seluruh mapping SIC code ke nama payment channel untuk memperkaya hasil preview. */
    private function loadPaymentChannels(): array
    {
        $statement = $this->database->query('SELECT sic_code, channel_name FROM payment_channels WHERE is_active = 1');
        $mapping = [];
        foreach ($statement->fetchAll() as $channel) {
            $mapping[(string) $channel['sic_code']] = (string) $channel['channel_name'];
        }
        return $mapping;
    }

    /** Menyimpan satu hasil staging dan mengembalikan ID audit-nya. */
    private function insertStagedRow(int $batchId, int $sourceRow, ?int $transactionId, string $fingerprint, string $outcome, ?array $errors, ?array $normalized, ?array $existing): int
    {
        $statement = $this->database->prepare('INSERT INTO transaction_import_rows (batch_id, source_row_number, transaction_id, row_fingerprint, outcome, validation_errors, normalized_data, existing_data) VALUES (:batch_id, :source_row, :transaction_id, :fingerprint, :outcome, :errors, :normalized, :existing)');
        $statement->execute(['batch_id' => $batchId, 'source_row' => $sourceRow, 'transaction_id' => $transactionId, 'fingerprint' => $fingerprint, 'outcome' => $outcome, 'errors' => $errors === null ? null : $this->encodeJson($errors), 'normalized' => $normalized === null ? null : $this->encodeJson($normalized), 'existing' => $existing === null ? null : $this->encodeJson($this->transactionSnapshot($existing))]);
        return (int) $this->database->lastInsertId();
    }

    /** Mencari transaksi berdasarkan natural key, opsional dengan row lock saat konfirmasi. */
    private function findTransaction(int $merchantId, array $row, bool $forUpdate): ?array
    {
        $statement = $this->database->prepare('SELECT id, transaction_date, datasource, transaction_type, ca_id, partner_channel, biller, sic_code, response_code, total_trx, total_amount, source_batch_id, source_row_number FROM transaction_aggregates WHERE merchant_id = :merchant_id AND transaction_date = :transaction_date AND datasource = :datasource AND transaction_type = :transaction_type AND ca_id = :ca_id AND partner_channel = :partner_channel AND biller = :biller AND sic_code = :sic_code AND response_code = :response_code LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $parameters = ['merchant_id' => $merchantId];
        foreach (self::NATURAL_KEY_FIELDS as $field) $parameters[$field] = $row[$field];
        $statement->execute($parameters); $result = $statement->fetch();
        return $result === false ? null : $result;
    }

    /** Menentukan apakah nilai transaksi existing sama dengan nilai dari workbook. */
    private function hasSameTotals(array $existing, array $row): bool
    {
        return (int) $existing['total_trx'] === (int) $row['total_trx'] && (string) $existing['total_amount'] === (string) $row['total_amount'];
    }

    /** Memperbarui statistik batch setelah semua baris preview tersimpan. */
    private function updatePreviewBatch(int $batchId, int $validRows, array $summary): void
    {
        $statement = $this->database->prepare('UPDATE import_batches SET valid_rows = :valid_rows, duplicate_rows = :duplicates, rejected_rows = :rejected WHERE id = :id');
        $statement->execute(['valid_rows' => $validRows, 'duplicates' => $summary['duplicate_in_file'] + $summary['duplicate_database'], 'rejected' => $summary['invalid'] + $summary['conflict_in_file'], 'id' => $batchId]);
    }

    /** Mengunci batch agar konfirmasi ganda atau bersamaan tidak dapat diproses. */
    private function lockPreviewBatch(int $batchId): array
    {
        $statement = $this->database->prepare('SELECT id, merchant_id, status, confirmation_token_hash, confirmation_expires_at FROM import_batches WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $batchId]); $batch = $statement->fetch();
        if ($batch === false) throw new RuntimeException('Batch import tidak ditemukan.');
        return $batch;
    }

    /** Mengambil metadata preview tanpa row lock untuk permintaan halaman read-only. */
    private function findPreviewBatch(int $batchId): array
    {
        $statement = $this->database->prepare('SELECT id, merchant_id, status, confirmation_token_hash, confirmation_expires_at FROM import_batches WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $batchId]);
        $batch = $statement->fetch();
        if ($batch === false) throw new RuntimeException('Batch import tidak ditemukan.');
        return $batch;
    }

    /** Memvalidasi status, masa berlaku, dan token rahasia batch konfirmasi. */
    private function validateConfirmation(array $batch, string $token): void
    {
        if ($batch['status'] !== 'PREVIEWED') throw new RuntimeException('Batch tidak lagi dapat dikonfirmasi.');
        if ($batch['confirmation_expires_at'] === null || strtotime((string) $batch['confirmation_expires_at']) < time()) throw new RuntimeException('Masa berlaku preview sudah berakhir. Upload ulang file untuk membuat preview baru.');
        if ($token === '' || !hash_equals((string) $batch['confirmation_token_hash'], hash('sha256', $token))) throw new RuntimeException('Token konfirmasi tidak valid.');
    }

    /** Menandai batch sedang diproses dan menghapus token agar tidak dapat digunakan ulang. */
    private function markBatchProcessing(int $batchId, ?int $confirmedByUserId): void
    {
        $statement = $this->database->prepare("UPDATE import_batches SET status = 'PROCESSING', confirmed_at = NOW(), imported_by = NULL, imported_by_user_id = :user_id, confirmation_token_hash = NULL, confirmation_expires_at = NULL WHERE id = :id");
        $statement->execute(['user_id' => $confirmedByUserId, 'id' => $batchId]);
    }

    /** Memuat staging dalam urutan nomor baris untuk pemrosesan deterministik. */
    private function loadStagedRows(int $batchId): array
    {
        $statement = $this->database->prepare('SELECT id, source_row_number, transaction_id, outcome, normalized_data, existing_data FROM transaction_import_rows WHERE batch_id = :batch_id ORDER BY source_row_number FOR UPDATE');
        $statement->execute(['batch_id' => $batchId]); return $statement->fetchAll();
    }

    /** Memasukkan baris READY dan mengecek ulang database untuk menghadapi import bersamaan. */
    private function insertReadyRow(int $merchantId, int $batchId, array $stagedRow): string
    {
        $row = $this->decodeJson((string) $stagedRow['normalized_data']); $existing = $this->findTransaction($merchantId, $row, true);
        if ($existing !== null) {
            $outcome = $this->hasSameTotals($existing, $row) ? 'DUPLICATE_DATABASE' : 'STALE_CONFLICT';
            $this->updateStagedOutcome((int) $stagedRow['id'], $outcome, (int) $existing['id']); return $outcome;
        }
        $statement = $this->database->prepare('INSERT INTO transaction_aggregates (merchant_id, transaction_date, datasource, transaction_type, ca_id, partner_channel, biller, sic_code, response_code, total_trx, total_amount, source_batch_id, source_row_number) VALUES (:merchant_id, :transaction_date, :datasource, :transaction_type, :ca_id, :partner_channel, :biller, :sic_code, :response_code, :total_trx, :total_amount, :batch_id, :source_row_number)');
        $statement->execute([...$row, 'merchant_id' => $merchantId, 'batch_id' => $batchId]);
        $transactionId = (int) $this->database->lastInsertId(); $this->updateStagedOutcome((int) $stagedRow['id'], 'INSERTED', $transactionId);
        return 'INSERTED';
    }

    /** Memperbarui konflik yang disetujui setelah memastikan data tidak berubah sejak preview. */
    private function updateChangedRow(int $merchantId, int $batchId, array $stagedRow, ?int $confirmedByUserId): string
    {
        $row = $this->decodeJson((string) $stagedRow['normalized_data']); $previewExisting = $this->decodeJson((string) $stagedRow['existing_data']);
        $current = $this->findTransaction($merchantId, $row, true);
        if ($current === null || $this->transactionSnapshot($current) !== $previewExisting) {
            $this->updateStagedOutcome((int) $stagedRow['id'], 'STALE_CONFLICT', $current === null ? null : (int) $current['id']); return 'STALE_CONFLICT';
        }
        $statement = $this->database->prepare('UPDATE transaction_aggregates SET total_trx = :total_trx, total_amount = :total_amount, source_batch_id = :batch_id, source_row_number = :source_row_number WHERE id = :id');
        $statement->execute(['total_trx' => $row['total_trx'], 'total_amount' => $row['total_amount'], 'batch_id' => $batchId, 'source_row_number' => $row['source_row_number'], 'id' => $current['id']]);
        $this->recordChangeHistory((int) $current['id'], $batchId, (int) $row['source_row_number'], $previewExisting, $row, $confirmedByUserId);
        $this->updateStagedOutcome((int) $stagedRow['id'], 'UPDATED', (int) $current['id']); return 'UPDATED';
    }

    /** Menyimpan snapshot sebelum dan sesudah untuk audit perubahan transaksi. */
    private function recordChangeHistory(int $transactionId, int $batchId, int $sourceRow, array $oldData, array $newData, ?int $confirmedByUserId): void
    {
        $statement = $this->database->prepare('INSERT INTO transaction_change_history (transaction_id, batch_id, source_row_number, old_data, new_data, confirmed_by, confirmed_by_user_id) VALUES (:transaction_id, :batch_id, :source_row, :old_data, :new_data, NULL, :user_id)');
        $statement->execute(['transaction_id' => $transactionId, 'batch_id' => $batchId, 'source_row' => $sourceRow, 'old_data' => $this->encodeJson($oldData), 'new_data' => $this->encodeJson($newData), 'user_id' => $confirmedByUserId]);
    }

    /** Memperbarui hasil akhir satu baris staging dan target transaksinya. */
    private function updateStagedOutcome(int $stagedId, string $outcome, ?int $transactionId): void
    {
        $statement = $this->database->prepare('UPDATE transaction_import_rows SET outcome = :outcome, transaction_id = :transaction_id WHERE id = :id');
        $statement->execute(['outcome' => $outcome, 'transaction_id' => $transactionId, 'id' => $stagedId]);
    }

    /** Mengelompokkan outcome akhir ke statistik batch. */
    private function incrementStats(array &$stats, string $outcome): void
    {
        if ($outcome === 'INSERTED') $stats['inserted']++;
        elseif ($outcome === 'UPDATED') $stats['updated']++;
        elseif (str_starts_with($outcome, 'DUPLICATE')) $stats['duplicate']++;
        else $stats['rejected']++;
    }

    /** Menandai batch selesai dan menyimpan statistik final tanpa menyimpan token lagi. */
    private function completeBatch(int $batchId, array $stats): void
    {
        $statement = $this->database->prepare("UPDATE import_batches SET inserted_rows = :inserted, updated_rows = :updated, duplicate_rows = :duplicate, rejected_rows = :rejected, status = 'COMPLETED', completed_at = NOW() WHERE id = :id");
        $statement->execute([...$stats, 'id' => $batchId]);
    }

    /** Mengambil snapshot kolom yang harus tetap sama antara preview dan konfirmasi. */
    private function transactionSnapshot(array $row): array
    {
        $fields = [...self::NATURAL_KEY_FIELDS, 'total_trx', 'total_amount', 'source_batch_id', 'source_row_number'];
        $snapshot = array_intersect_key($row, array_flip($fields));
        $snapshot['total_trx'] = (int) $snapshot['total_trx']; $snapshot['total_amount'] = (string) $snapshot['total_amount'];
        $snapshot['source_batch_id'] = (int) $snapshot['source_batch_id']; $snapshot['source_row_number'] = (int) $snapshot['source_row_number'];
        return $snapshot;
    }

    /** Menyusun payload aman yang dibutuhkan UI untuk menampilkan preview dan perubahan. */
    private function previewPayload(int $id, int $sourceRow, string $outcome, ?array $normalized, ?array $existing, ?array $errors, ?string $paymentChannel): array
    {
        $changedFields = [];
        if ($outcome === 'CHANGED' && $normalized !== null && $existing !== null) {
            foreach (['total_trx', 'total_amount'] as $field) {
                $old = $field === 'total_trx' ? (int) $existing[$field] : (string) $existing[$field];
                if ((string) $old !== (string) $normalized[$field]) $changedFields[] = $field;
            }
        }
        return ['id' => $id, 'source_row_number' => $sourceRow, 'outcome' => $outcome, 'changed_fields' => $changedFields, 'payment_channel' => $paymentChannel, 'data' => $normalized, 'existing' => $existing === null ? null : $this->transactionSnapshot($existing), 'errors' => $errors];
    }

    /** Mengubah array menjadi JSON dengan kegagalan eksplisit. */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** Mengubah JSON staging menjadi array dan menolak payload rusak. */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Data staging tidak valid.');
        return $decoded;
    }
}
