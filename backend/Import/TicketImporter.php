<?php
declare(strict_types=1);

namespace App\Import;

use PDO;
use RuntimeException;
use Throwable;

final class TicketImporter
{
    private const DATA_FIELDS = ['ticket_number', 'status', 'complaint_segment', 'opened_at', 'closed_at', 'last_updated_at', 'duration_raw', 'duration_minutes', 'response_time_minutes', 'classification_flag'];

    /** Menyimpan koneksi database dan reader yang digunakan selama alur import tiket. */
    public function __construct(private readonly PDO $database, private readonly TicketWorkbookReader $reader)
    {
    }

    /** Memvalidasi workbook dan menyimpan hasil perbandingan sebagai preview staging. */
    public function preview(string $filePath, string $originalFilename, ?int $merchantId, ?string $newMerchantName = null): array
    {
        if (strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException('File tiket aduan harus berformat XLSX.');
        $hash = hash_file('sha256', $filePath);
        if ($hash === false) throw new RuntimeException('Hash file tidak dapat dihitung.');
        $existingBatch = $this->findBatchByHash($hash);
        $replaceableBatchId = null;
        if ($existingBatch !== null && $existingBatch['status'] !== 'PREVIEWED') {
            return ['status' => 'IDENTICAL_FILE', 'batch_id' => (int) $existingBatch['id'], 'batch_status' => $existingBatch['status'], 'message' => 'File identik sudah pernah selesai di-import.'];
        }
        if ($existingBatch !== null) $replaceableBatchId = (int) $existingBatch['id'];
        if (($merchantId === null) === ($newMerchantName === null)) throw new RuntimeException('Pilih merchant existing atau isi satu nama merchant baru.');
        $inspection = $this->reader->inspectTickets($filePath);
        if ($inspection['total_rows'] === 0) throw new RuntimeException('Workbook tidak memiliki baris tiket aduan.');
        $rows = $inspection['rows'];
        $periodStart = $rows === [] ? null : substr(min(array_column($rows, 'opened_at')), 0, 10);
        $periodEnd = $rows === [] ? null : substr(max(array_column($rows, 'opened_at')), 0, 10);
        $token = bin2hex(random_bytes(32));
        $this->database->beginTransaction();
        try {
            if ($replaceableBatchId !== null) $this->deleteReplaceablePreview($replaceableBatchId, $hash);
            $resolvedMerchantId = $this->resolveMerchant($merchantId, $newMerchantName);
            $batchId = $this->createPreviewBatch($resolvedMerchantId, $originalFilename, $hash, $periodStart, $periodEnd, (int) $inspection['total_rows'], hash('sha256', $token));
            $summary = $this->stageRows($batchId, $resolvedMerchantId, $rows, $inspection['invalid_rows']);
            $this->updatePreviewBatch($batchId, count($rows), $summary);
            $this->database->commit();
            $previewPage = $this->previewRows($batchId, $token, 1, 50, null);
            return ['status' => 'PREVIEWED', 'batch_id' => $batchId, 'original_filename' => mb_substr(basename(str_replace('\\', '/', $originalFilename)), 0, 255), 'confirmation_token' => $token, 'confirmation_expires_at' => date('c', time() + 86400), 'period_start' => $periodStart, 'period_end' => $periodEnd, 'summary' => $summary, 'rows' => $previewPage['items'], 'pagination' => $previewPage['pagination']];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** Mengonfirmasi preview dan menerapkan insert atau perubahan yang disetujui dalam satu transaction. */
    public function confirm(int $batchId, string $token, bool $updateChangedRows, ?int $confirmedByUserId): array
    {
        $this->database->beginTransaction();
        try {
            $batch = $this->lockPreviewBatch($batchId);
            $this->validateConfirmation($batch, $token);
            $this->markBatchProcessing($batchId, $confirmedByUserId);
            $stats = ['inserted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected' => 0];
            foreach ($this->loadStagedRows($batchId) as $stagedRow) {
                $outcome = (string) $stagedRow['outcome'];
                if ($outcome === 'READY') $result = $this->insertReadyRow((int) $batch['merchant_id'], $batchId, $stagedRow);
                elseif ($outcome === 'CHANGED' && $updateChangedRows) $result = $this->updateChangedRow((int) $batch['merchant_id'], $batchId, $stagedRow, $confirmedByUserId);
                elseif ($outcome === 'CHANGED') {
                    $result = 'SKIPPED_CHANGE';
                    $this->updateStagedOutcome((int) $stagedRow['id'], $result, $stagedRow['ticket_id'] === null ? null : (int) $stagedRow['ticket_id']);
                } else $result = $outcome;
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

    /** Mengambil halaman preview setelah status, token, dan filter outcome divalidasi. */
    public function previewRows(int $batchId, string $token, int $page, int $perPage, ?string $outcome): array
    {
        $allowed = ['READY', 'CHANGED', 'DUPLICATE_IN_FILE', 'DUPLICATE_DATABASE', 'CONFLICT_IN_FILE', 'INVALID'];
        if ($page < 1 || $perPage < 1 || $perPage > 100 || ($outcome !== null && !in_array($outcome, $allowed, true))) throw new RuntimeException('Parameter halaman atau filter preview tidak valid.');
        $this->validateConfirmation($this->findPreviewBatch($batchId), $token);
        $filterSql = $outcome === null ? '' : ' AND outcome = :outcome';
        $parameters = ['batch_id' => $batchId];
        if ($outcome !== null) $parameters['outcome'] = $outcome;
        $count = $this->database->prepare('SELECT COUNT(*) FROM ticket_import_rows WHERE batch_id = :batch_id' . $filterSql);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $this->database->prepare("SELECT id, source_row_number, outcome, validation_errors, normalized_data, existing_data FROM ticket_import_rows WHERE batch_id = :batch_id{$filterSql} ORDER BY FIELD(outcome, 'CHANGED', 'READY', 'INVALID', 'CONFLICT_IN_FILE', 'DUPLICATE_IN_FILE', 'DUPLICATE_DATABASE'), source_row_number LIMIT :limit OFFSET :offset");
        foreach ($parameters as $key => $value) $statement->bindValue($key, $value);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $items = array_map(fn (array $row): array => $this->previewPayload((int) $row['id'], (int) $row['source_row_number'], (string) $row['outcome'], $row['normalized_data'] === null ? null : $this->decodeJson((string) $row['normalized_data']), $row['existing_data'] === null ? null : $this->decodeJson((string) $row['existing_data']), $row['validation_errors'] === null ? null : $this->decodeJson((string) $row['validation_errors'])), $statement->fetchAll());
        return ['items' => $items, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))]];
    }

    /** Menghapus batch preview yang sah sehingga staging ikut terhapus melalui foreign key cascade. */
    public function deletePreview(int $batchId, string $token): array
    {
        $this->database->beginTransaction();
        try {
            $batch = $this->lockPreviewBatch($batchId);
            $this->validateConfirmation($batch, $token);
            $statement = $this->database->prepare("DELETE FROM import_batches WHERE id = :id AND data_type = 'TICKET' AND status = 'PREVIEWED'");
            $statement->execute(['id' => $batchId]);
            if ($statement->rowCount() !== 1) throw new RuntimeException('Preview tiket tidak dapat dihapus.');
            $this->database->commit();
            return ['status' => 'DELETED', 'batch_id' => $batchId];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            throw $error;
        }
    }

    /** Menghapus preview tiket kedaluwarsa dalam jumlah terbatas tanpa menyentuh batch completed. */
    public function cleanupExpiredPreviews(int $retentionDays = 7, int $limit = 1000): array
    {
        if ($retentionDays < 1 || $retentionDays > 365 || $limit < 1 || $limit > 10000) throw new RuntimeException('Parameter cleanup preview tidak valid.');
        $statement = $this->database->prepare("DELETE b FROM import_batches b WHERE b.data_type = 'TICKET' AND b.status = 'PREVIEWED' AND b.created_at < DATE_SUB(NOW(), INTERVAL :days DAY) ORDER BY b.id LIMIT :limit");
        $statement->bindValue('days', $retentionDays, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return ['deleted_batches' => $statement->rowCount()];
    }

    /** Memastikan merchant existing aktif atau membuat merchant baru secara atomik bersama batch preview. */
    private function resolveMerchant(?int $merchantId, ?string $newMerchantName): int
    {
        if ($merchantId !== null) {
            $statement = $this->database->prepare('SELECT id FROM merchants WHERE id = :id AND is_active = 1 LIMIT 1');
            $statement->execute(['id' => $merchantId]);
            if ($statement->fetch() === false) throw new RuntimeException('Merchant tidak ditemukan atau sudah tidak aktif.');
            return $merchantId;
        }
        $name = preg_replace('/\s+/u', ' ', trim((string) $newMerchantName)) ?? '';
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Nama merchant baru wajib diisi dan maksimal 160 karakter.');
        $existing = $this->database->prepare('SELECT id FROM merchants WHERE TRIM(merchant_name) = :name LIMIT 1');
        $existing->execute(['name' => $name]);
        if ($existing->fetch() !== false) throw new RuntimeException('Nama merchant sudah tersedia. Pilih merchant tersebut dari daftar.');
        $insert = $this->database->prepare('INSERT INTO merchants (merchant_code, merchant_name) VALUES (:code, :name)');
        $insert->execute(['code' => 'MRC-' . strtoupper(bin2hex(random_bytes(6))), 'name' => $name]);
        return (int) $this->database->lastInsertId();
    }

    /** Mencari batch tiket berdasarkan hash file untuk idempotensi upload. */
    private function findBatchByHash(string $hash): ?array
    {
        $statement = $this->database->prepare("SELECT id, status FROM import_batches WHERE file_sha256 = :hash AND data_type = 'TICKET' LIMIT 1");
        $statement->execute(['hash' => $hash]);
        $batch = $statement->fetch();
        return $batch === false ? null : $batch;
    }

    /** Menghapus preview identik lama setelah mengunci dan memeriksa ulang hash serta jenis datanya. */
    private function deleteReplaceablePreview(int $batchId, string $hash): void
    {
        $statement = $this->database->prepare("SELECT id FROM import_batches WHERE id = :id AND file_sha256 = :hash AND data_type = 'TICKET' AND status = 'PREVIEWED' FOR UPDATE");
        $statement->execute(['id' => $batchId, 'hash' => $hash]);
        if ($statement->fetch() === false) throw new RuntimeException('Preview identik sedang berubah. Silakan upload ulang.');
        $delete = $this->database->prepare('DELETE FROM import_batches WHERE id = :id');
        $delete->execute(['id' => $batchId]);
    }

    /** Membuat metadata batch preview dan menyimpan token hanya dalam bentuk hash. */
    private function createPreviewBatch(int $merchantId, string $filename, string $hash, ?string $start, ?string $end, int $totalRows, string $tokenHash): int
    {
        $safeFilename = mb_substr(basename(str_replace('\\', '/', $filename)), 0, 255);
        $statement = $this->database->prepare("INSERT INTO import_batches (merchant_id, data_type, original_filename, file_sha256, detected_period_start, detected_period_end, total_rows, status, confirmation_token_hash, confirmation_expires_at) VALUES (:merchant_id, 'TICKET', :filename, :hash, :period_start, :period_end, :total_rows, 'PREVIEWED', :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
        $statement->execute(['merchant_id' => $merchantId, 'filename' => $safeFilename, 'hash' => $hash, 'period_start' => $start, 'period_end' => $end, 'total_rows' => $totalRows, 'token_hash' => $tokenHash]);
        return (int) $this->database->lastInsertId();
    }

    /** Mengklasifikasikan seluruh baris valid dan invalid lalu menyimpannya ke staging. */
    private function stageRows(int $batchId, int $merchantId, array $rows, array $invalidRows): array
    {
        $summary = ['total' => count($rows) + count($invalidRows), 'ready' => 0, 'changed' => 0, 'duplicate_in_file' => 0, 'duplicate_database' => 0, 'conflict_in_file' => 0, 'invalid' => 0];
        $seenNumbers = [];
        foreach ($rows as $row) {
            $fingerprint = $this->reader->fingerprint($row);
            $ticketNumber = $row['ticket_number'];
            $normalized = $row + ['merchant_id' => $merchantId];
            $existing = null;
            if (isset($seenNumbers[$ticketNumber])) $outcome = $seenNumbers[$ticketNumber] === $fingerprint ? 'DUPLICATE_IN_FILE' : 'CONFLICT_IN_FILE';
            else {
                $existing = $this->findTicket($ticketNumber, false);
                $sameMerchant = $existing !== null && (int) $existing['merchant_id'] === $merchantId;
                $outcome = $existing === null ? 'READY' : ($sameMerchant && $this->ticketData($existing) === $this->ticketData($row) ? 'DUPLICATE_DATABASE' : 'CHANGED');
            }
            $seenNumbers[$ticketNumber] = $seenNumbers[$ticketNumber] ?? $fingerprint;
            $this->insertStagedRow($batchId, (int) $row['source_row_number'], $existing === null ? null : (int) $existing['id'], $fingerprint, $outcome, null, $normalized, $existing);
            $summary[strtolower($outcome)]++;
        }
        foreach ($invalidRows as $invalid) {
            $errors = ['message' => $invalid['message']];
            $this->insertStagedRow($batchId, (int) $invalid['source_row_number'], null, hash('sha256', $invalid['source_row_number'] . '|' . $invalid['message']), 'INVALID', $errors, null, null);
            $summary['invalid']++;
        }
        return $summary;
    }

    /** Menyimpan satu hasil staging beserta snapshot aman untuk preview dan audit. */
    private function insertStagedRow(int $batchId, int $sourceRow, ?int $ticketId, string $fingerprint, string $outcome, ?array $errors, ?array $normalized, ?array $existing): void
    {
        $statement = $this->database->prepare('INSERT INTO ticket_import_rows (batch_id, source_row_number, ticket_id, row_fingerprint, outcome, validation_errors, normalized_data, existing_data) VALUES (:batch_id, :source_row, :ticket_id, :fingerprint, :outcome, :errors, :normalized, :existing)');
        $statement->execute(['batch_id' => $batchId, 'source_row' => $sourceRow, 'ticket_id' => $ticketId, 'fingerprint' => $fingerprint, 'outcome' => $outcome, 'errors' => $errors === null ? null : $this->encodeJson($errors), 'normalized' => $normalized === null ? null : $this->encodeJson($normalized), 'existing' => $existing === null ? null : $this->encodeJson($this->ticketSnapshot($existing))]);
    }

    /** Mencari tiket dengan unique key Ticket No dan opsional row lock untuk konfirmasi. */
    private function findTicket(string $ticketNumber, bool $forUpdate): ?array
    {
        $statement = $this->database->prepare('SELECT id, merchant_id, ticket_number, status, complaint_segment, opened_at, closed_at, last_updated_at, duration_raw, duration_minutes, response_time_minutes, classification_flag, source_batch_id, source_row_number FROM complaint_tickets WHERE ticket_number = :ticket_number LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute(['ticket_number' => $ticketNumber]);
        $ticket = $statement->fetch();
        return $ticket === false ? null : $ticket;
    }

    /** Memperbarui statistik batch setelah seluruh staging berhasil dibuat. */
    private function updatePreviewBatch(int $batchId, int $validRows, array $summary): void
    {
        $statement = $this->database->prepare('UPDATE import_batches SET valid_rows = :valid_rows, duplicate_rows = :duplicates, rejected_rows = :rejected WHERE id = :id');
        $statement->execute(['valid_rows' => $validRows, 'duplicates' => $summary['duplicate_in_file'] + $summary['duplicate_database'], 'rejected' => $summary['invalid'] + $summary['conflict_in_file'], 'id' => $batchId]);
    }

    /** Mengunci satu batch preview tiket untuk mencegah konfirmasi atau penghapusan bersamaan. */
    private function lockPreviewBatch(int $batchId): array
    {
        $statement = $this->database->prepare("SELECT id, merchant_id, status, confirmation_token_hash, confirmation_expires_at FROM import_batches WHERE id = :id AND data_type = 'TICKET' FOR UPDATE");
        $statement->execute(['id' => $batchId]);
        $batch = $statement->fetch();
        if ($batch === false) throw new RuntimeException('Batch import tiket tidak ditemukan.');
        return $batch;
    }

    /** Mengambil metadata preview tiket tanpa lock untuk pagination read-only. */
    private function findPreviewBatch(int $batchId): array
    {
        $statement = $this->database->prepare("SELECT id, merchant_id, status, confirmation_token_hash, confirmation_expires_at FROM import_batches WHERE id = :id AND data_type = 'TICKET' LIMIT 1");
        $statement->execute(['id' => $batchId]);
        $batch = $statement->fetch();
        if ($batch === false) throw new RuntimeException('Batch import tiket tidak ditemukan.');
        return $batch;
    }

    /** Memvalidasi status, kedaluwarsa, dan token rahasia preview. */
    private function validateConfirmation(array $batch, string $token): void
    {
        if ($batch['status'] !== 'PREVIEWED') throw new RuntimeException('Batch tidak lagi dapat dikonfirmasi.');
        if ($batch['confirmation_expires_at'] === null || strtotime((string) $batch['confirmation_expires_at']) < time()) throw new RuntimeException('Masa berlaku preview sudah berakhir. Upload ulang file untuk membuat preview baru.');
        if ($token === '' || !hash_equals((string) $batch['confirmation_token_hash'], hash('sha256', $token))) throw new RuntimeException('Token konfirmasi tidak valid.');
    }

    /** Menandai batch sedang diproses dan mengaitkan user yang mengonfirmasi. */
    private function markBatchProcessing(int $batchId, ?int $userId): void
    {
        $statement = $this->database->prepare("UPDATE import_batches SET status = 'PROCESSING', confirmed_at = NOW(), imported_by = NULL, imported_by_user_id = :user_id, confirmation_token_hash = NULL, confirmation_expires_at = NULL WHERE id = :id");
        $statement->execute(['user_id' => $userId, 'id' => $batchId]);
    }

    /** Memuat seluruh staging tiket dalam urutan sumber yang deterministik dan terkunci. */
    private function loadStagedRows(int $batchId): array
    {
        $statement = $this->database->prepare('SELECT id, source_row_number, ticket_id, outcome, normalized_data, existing_data FROM ticket_import_rows WHERE batch_id = :batch_id ORDER BY source_row_number FOR UPDATE');
        $statement->execute(['batch_id' => $batchId]);
        return $statement->fetchAll();
    }

    /** Menyisipkan tiket READY setelah mengecek ulang unique key untuk menghadapi proses bersamaan. */
    private function insertReadyRow(int $merchantId, int $batchId, array $stagedRow): string
    {
        $row = $this->decodeJson((string) $stagedRow['normalized_data']);
        $existing = $this->findTicket((string) $row['ticket_number'], true);
        if ($existing !== null) {
            $outcome = $this->ticketData($existing) === $this->ticketData($row) ? 'DUPLICATE_DATABASE' : 'STALE_CONFLICT';
            $this->updateStagedOutcome((int) $stagedRow['id'], $outcome, (int) $existing['id']);
            return $outcome;
        }
        $statement = $this->database->prepare('INSERT INTO complaint_tickets (merchant_id, ticket_number, status, complaint_segment, opened_at, closed_at, last_updated_at, duration_raw, duration_minutes, response_time_minutes, classification_flag, source_batch_id, source_row_number) VALUES (:merchant_id, :ticket_number, :status, :complaint_segment, :opened_at, :closed_at, :last_updated_at, :duration_raw, :duration_minutes, :response_time_minutes, :classification_flag, :batch_id, :source_row_number)');
        $parameters = $this->ticketData($row) + ['merchant_id' => $merchantId, 'batch_id' => $batchId, 'source_row_number' => (int) $row['source_row_number']];
        $statement->execute($parameters);
        $ticketId = (int) $this->database->lastInsertId();
        $this->updateStagedOutcome((int) $stagedRow['id'], 'INSERTED', $ticketId);
        return 'INSERTED';
    }

    /** Memperbarui tiket CHANGED hanya jika snapshot sejak preview belum berubah. */
    private function updateChangedRow(int $merchantId, int $batchId, array $stagedRow, ?int $userId): string
    {
        $row = $this->decodeJson((string) $stagedRow['normalized_data']);
        $previewExisting = $this->decodeJson((string) $stagedRow['existing_data']);
        $current = $this->findTicket((string) $row['ticket_number'], true);
        if ($current === null || $this->ticketSnapshot($current) !== $previewExisting) {
            $this->updateStagedOutcome((int) $stagedRow['id'], 'STALE_CONFLICT', $current === null ? null : (int) $current['id']);
            return 'STALE_CONFLICT';
        }
        $statement = $this->database->prepare('UPDATE complaint_tickets SET merchant_id = :merchant_id, status = :status, complaint_segment = :complaint_segment, opened_at = :opened_at, closed_at = :closed_at, last_updated_at = :last_updated_at, duration_raw = :duration_raw, duration_minutes = :duration_minutes, response_time_minutes = :response_time_minutes, classification_flag = :classification_flag, source_batch_id = :batch_id, source_row_number = :source_row_number WHERE id = :id');
        $updateData = $this->ticketData($row);
        unset($updateData['ticket_number']);
        $statement->execute($updateData + ['merchant_id' => $merchantId, 'batch_id' => $batchId, 'source_row_number' => (int) $row['source_row_number'], 'id' => (int) $current['id']]);
        $this->recordChangeHistory((int) $current['id'], $batchId, (int) $row['source_row_number'], $previewExisting, $row, $userId);
        $this->updateStagedOutcome((int) $stagedRow['id'], 'UPDATED', (int) $current['id']);
        return 'UPDATED';
    }

    /** Menyimpan snapshot sebelum dan sesudah untuk audit koreksi tiket. */
    private function recordChangeHistory(int $ticketId, int $batchId, int $sourceRow, array $oldData, array $newData, ?int $userId): void
    {
        $statement = $this->database->prepare('INSERT INTO ticket_change_history (ticket_id, batch_id, source_row_number, old_data, new_data, confirmed_by_user_id) VALUES (:ticket_id, :batch_id, :source_row, :old_data, :new_data, :user_id)');
        $statement->execute(['ticket_id' => $ticketId, 'batch_id' => $batchId, 'source_row' => $sourceRow, 'old_data' => $this->encodeJson($oldData), 'new_data' => $this->encodeJson($newData), 'user_id' => $userId]);
    }

    /** Memperbarui outcome akhir staging dan relasinya ke tiket utama. */
    private function updateStagedOutcome(int $stagedId, string $outcome, ?int $ticketId): void
    {
        $statement = $this->database->prepare('UPDATE ticket_import_rows SET outcome = :outcome, ticket_id = :ticket_id WHERE id = :id');
        $statement->execute(['outcome' => $outcome, 'ticket_id' => $ticketId, 'id' => $stagedId]);
    }

    /** Mengelompokkan outcome akhir menjadi statistik batch. */
    private function incrementStats(array &$stats, string $outcome): void
    {
        if ($outcome === 'INSERTED') $stats['inserted']++;
        elseif ($outcome === 'UPDATED') $stats['updated']++;
        elseif (str_starts_with($outcome, 'DUPLICATE')) $stats['duplicate']++;
        else $stats['rejected']++;
    }

    /** Menandai batch selesai dan menyimpan statistik hasil konfirmasi. */
    private function completeBatch(int $batchId, array $stats): void
    {
        $statement = $this->database->prepare("UPDATE import_batches SET inserted_rows = :inserted, updated_rows = :updated, duplicate_rows = :duplicate, rejected_rows = :rejected, status = 'COMPLETED', completed_at = NOW() WHERE id = :id");
        $statement->execute([...$stats, 'id' => $batchId]);
    }

    /** Mengambil dan menyeragamkan field data bisnis yang dibandingkan antarversi tiket. */
    private function ticketData(array $row): array
    {
        $data = array_intersect_key($row, array_flip(self::DATA_FIELDS));
        $data['duration_minutes'] = $data['duration_minutes'] === null ? null : (int) $data['duration_minutes'];
        $data['response_time_minutes'] = $data['response_time_minutes'] === null ? null : (int) $data['response_time_minutes'];
        $data['closed_at'] = $data['closed_at'] === null ? null : (string) $data['closed_at'];
        $data['last_updated_at'] = $data['last_updated_at'] === null ? null : (string) $data['last_updated_at'];
        foreach (array_diff(self::DATA_FIELDS, ['duration_minutes', 'response_time_minutes', 'closed_at', 'last_updated_at']) as $field) $data[$field] = (string) $data[$field];
        return $data;
    }

    /** Membentuk snapshot optimistic-lock termasuk sumber data versi sebelumnya. */
    private function ticketSnapshot(array $row): array
    {
        $snapshot = $this->ticketData($row);
        $snapshot['merchant_id'] = $row['merchant_id'] === null ? null : (int) $row['merchant_id'];
        $snapshot['source_batch_id'] = (int) $row['source_batch_id'];
        $snapshot['source_row_number'] = (int) $row['source_row_number'];
        return $snapshot;
    }

    /** Menyusun payload preview serta daftar field yang berubah tanpa data pribadi yang dibuang. */
    private function previewPayload(int $id, int $sourceRow, string $outcome, ?array $normalized, ?array $existing, ?array $errors): array
    {
        $changedFields = [];
        if ($outcome === 'CHANGED' && $normalized !== null && $existing !== null) {
            if (($normalized['merchant_id'] ?? null) !== ($existing['merchant_id'] ?? null)) $changedFields[] = 'merchant_id';
            foreach (self::DATA_FIELDS as $field) if (($this->ticketData($existing)[$field] ?? null) !== ($this->ticketData($normalized)[$field] ?? null)) $changedFields[] = $field;
        }
        return ['id' => $id, 'source_row_number' => $sourceRow, 'outcome' => $outcome, 'changed_fields' => $changedFields, 'data' => $normalized, 'existing' => $existing, 'errors' => $errors];
    }

    /** Mengubah array staging menjadi JSON secara eksplisit dan aman. */
    private function encodeJson(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** Membaca JSON staging dan menolak struktur selain object/array. */
    private function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) throw new RuntimeException('Data staging tiket tidak valid.');
        return $decoded;
    }
}
