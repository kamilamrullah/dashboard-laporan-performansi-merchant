<?php
declare(strict_types=1);

namespace App\Import;

use PDO;
use RuntimeException;
use Throwable;

final class TransactionImporter
{
    public function __construct(private readonly PDO $database, private readonly TransactionWorkbookReader $reader)
    {
    }

    /** Mengimpor satu workbook transaksi sebagai batch atomik dan mengembalikan statistik hasil. */
    public function import(string $filePath, string $merchantCode, string $merchantName, ?string $mappingFile = null): array
    {
        $hash = hash_file('sha256', $filePath);
        if ($hash === false) {
            throw new RuntimeException('Hash file tidak dapat dihitung.');
        }
        $existing = $this->findBatchByHash($hash);
        if ($existing !== null) {
            if ($mappingFile !== null) {
                $this->database->beginTransaction();
                try {
                    $this->upsertPaymentChannels($this->reader->readPaymentChannels($mappingFile));
                    $this->database->commit();
                } catch (Throwable $error) {
                    $this->database->rollBack();
                    throw $error;
                }
            }
            return ['status' => 'IDENTICAL_FILE', 'batch_id' => (int) $existing['id'], 'message' => 'File identik sudah pernah diproses.'];
        }

        $rows = $this->reader->readTransactions($filePath);
        if ($rows === []) {
            throw new RuntimeException('Workbook tidak memiliki baris transaksi.');
        }
        $periodStart = min(array_column($rows, 'transaction_date'));
        $periodEnd = max(array_column($rows, 'transaction_date'));
        $stats = ['inserted' => 0, 'updated' => 0, 'duplicate' => 0, 'rejected' => 0];

        $this->database->beginTransaction();
        try {
            $merchantId = $this->upsertMerchant($merchantCode, $merchantName);
            $batchId = $this->createBatch($merchantId, $filePath, $hash, $periodStart, $periodEnd, count($rows));
            if ($mappingFile !== null) {
                $this->upsertPaymentChannels($this->reader->readPaymentChannels($mappingFile));
            }
            foreach ($rows as $row) {
                $outcome = $this->persistTransaction($merchantId, $batchId, $row);
                $stats[strtolower($outcome)]++;
            }
            $this->completeBatch($batchId, count($rows), $stats);
            $this->database->commit();
            return ['status' => 'COMPLETED', 'batch_id' => $batchId, 'period_start' => $periodStart, 'period_end' => $periodEnd, ...$stats];
        } catch (Throwable $error) {
            if ($this->database->inTransaction()) {
                $this->database->rollBack();
            }
            throw $error;
        }
    }

    /** Mencari batch sebelumnya berdasarkan hash file SHA-256. */
    private function findBatchByHash(string $hash): ?array
    {
        $statement = $this->database->prepare('SELECT id, status FROM import_batches WHERE file_sha256 = :hash LIMIT 1');
        $statement->execute(['hash' => $hash]);
        $result = $statement->fetch();
        return $result === false ? null : $result;
    }

    /** Membuat atau memperbarui merchant dan mengembalikan primary key-nya. */
    private function upsertMerchant(string $code, string $name): int
    {
        $statement = $this->database->prepare(
            'INSERT INTO merchants (merchant_code, merchant_name) VALUES (:code, :name)
             ON DUPLICATE KEY UPDATE merchant_name = VALUES(merchant_name), id = LAST_INSERT_ID(id)'
        );
        $statement->execute(['code' => trim($code), 'name' => trim($name)]);
        return (int) $this->database->lastInsertId();
    }

    /** Membuat metadata batch sebelum baris transaksi disimpan. */
    private function createBatch(int $merchantId, string $filePath, string $hash, string $start, string $end, int $rowCount): int
    {
        $statement = $this->database->prepare(
            "INSERT INTO import_batches
             (merchant_id, data_type, original_filename, file_sha256, detected_period_start, detected_period_end, total_rows, valid_rows, status, confirmed_at)
             VALUES (:merchant_id, 'TRANSACTION', :filename, :hash, :period_start, :period_end, :total_rows, :valid_rows, 'PROCESSING', NOW())"
        );
        $statement->execute([
            'merchant_id' => $merchantId,
            'filename' => basename($filePath),
            'hash' => $hash,
            'period_start' => $start,
            'period_end' => $end,
            'total_rows' => $rowCount,
            'valid_rows' => $rowCount,
        ]);
        return (int) $this->database->lastInsertId();
    }

    /** Menyimpan mapping SIC code secara idempotent tanpa menebak mapping tambahan. */
    private function upsertPaymentChannels(array $mapping): void
    {
        $statement = $this->database->prepare(
            'INSERT INTO payment_channels (sic_code, channel_name) VALUES (:code, :name)
             ON DUPLICATE KEY UPDATE channel_name = VALUES(channel_name), is_active = 1'
        );
        foreach ($mapping as $code => $name) {
            $statement->execute(['code' => $code, 'name' => trim((string) $name)]);
        }
    }

    /** Menyimpan, memperbarui, atau menandai duplikat berdasarkan natural key transaksi. */
    private function persistTransaction(int $merchantId, int $batchId, array $row): string
    {
        $lookup = $this->database->prepare(
            'SELECT id, total_trx, total_amount FROM transaction_aggregates
             WHERE merchant_id = :merchant_id AND transaction_date = :transaction_date AND datasource = :datasource AND transaction_type = :transaction_type
               AND ca_id = :ca_id AND partner_channel = :partner_channel AND biller = :biller
               AND sic_code = :sic_code AND response_code = :response_code LIMIT 1'
        );
        $naturalKey = array_intersect_key($row, array_flip(['transaction_date', 'datasource', 'transaction_type', 'ca_id', 'partner_channel', 'biller', 'sic_code', 'response_code']));
        $lookup->execute([...$naturalKey, 'merchant_id' => $merchantId]);
        $existing = $lookup->fetch();

        if ($existing === false) {
            $statement = $this->database->prepare(
                'INSERT INTO transaction_aggregates
                 (merchant_id, transaction_date, datasource, transaction_type, ca_id, partner_channel, biller, sic_code, response_code, total_trx, total_amount, source_batch_id, source_row_number)
                 VALUES (:merchant_id, :transaction_date, :datasource, :transaction_type, :ca_id, :partner_channel, :biller, :sic_code, :response_code, :total_trx, :total_amount, :batch_id, :source_row_number)'
            );
            $statement->execute([...$row, 'merchant_id' => $merchantId, 'batch_id' => $batchId]);
            $transactionId = (int) $this->database->lastInsertId();
            $outcome = 'INSERTED';
        } elseif ((int) $existing['total_trx'] === $row['total_trx'] && number_format((float) $existing['total_amount'], 2, '.', '') === $row['total_amount']) {
            $transactionId = (int) $existing['id'];
            $outcome = 'DUPLICATE';
        } else {
            $statement = $this->database->prepare(
                'UPDATE transaction_aggregates SET total_trx = :total_trx, total_amount = :total_amount,
                 merchant_id = :merchant_id, source_batch_id = :batch_id, source_row_number = :source_row_number WHERE id = :id'
            );
            $statement->execute([
                'total_trx' => $row['total_trx'], 'total_amount' => $row['total_amount'], 'merchant_id' => $merchantId,
                'batch_id' => $batchId, 'source_row_number' => $row['source_row_number'], 'id' => $existing['id'],
            ]);
            $transactionId = (int) $existing['id'];
            $outcome = 'UPDATED';
        }
        $this->recordAuditRow($batchId, $transactionId, $row, $outcome);
        return $outcome;
    }

    /** Mencatat fingerprint, nomor sumber, target, dan hasil pemrosesan satu baris. */
    private function recordAuditRow(int $batchId, int $transactionId, array $row, string $outcome): void
    {
        $fingerprint = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $statement = $this->database->prepare(
            'INSERT INTO transaction_import_rows (batch_id, source_row_number, transaction_id, row_fingerprint, outcome)
             VALUES (:batch_id, :source_row_number, :transaction_id, :fingerprint, :outcome)'
        );
        $statement->execute([
            'batch_id' => $batchId, 'source_row_number' => $row['source_row_number'], 'transaction_id' => $transactionId,
            'fingerprint' => $fingerprint, 'outcome' => $outcome,
        ]);
    }

    /** Menandai batch selesai dan menyimpan seluruh statistik import. */
    private function completeBatch(int $batchId, int $validRows, array $stats): void
    {
        $statement = $this->database->prepare(
            "UPDATE import_batches SET valid_rows = :valid_rows, inserted_rows = :inserted, updated_rows = :updated,
             duplicate_rows = :duplicate, rejected_rows = :rejected, status = 'COMPLETED', completed_at = NOW() WHERE id = :id"
        );
        $statement->execute([...$stats, 'valid_rows' => $validRows, 'id' => $batchId]);
    }
}
