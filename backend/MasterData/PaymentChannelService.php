<?php
declare(strict_types=1);

namespace App\MasterData;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class PaymentChannelService
{
    /** Menyimpan koneksi database yang dipakai seluruh operasi master payment channel. */
    public function __construct(private readonly PDO $database)
    {
    }

    /** Mengambil daftar mapping dengan pencarian, filter status, penggunaan, dan pagination. */
    public function list(string $search, string $status, int $page, int $perPage): array
    {
        if ($page < 1 || $perPage < 1 || $perPage > 100 || mb_strlen($search) > 100 || !in_array($status, ['all', 'active', 'inactive'], true)) throw new RuntimeException('Parameter daftar payment channel tidak valid.');
        $conditions = ['1=1']; $parameters = [];
        if ($search !== '') { $conditions[] = '(pc.sic_code LIKE :search_code OR pc.channel_name LIKE :search_name)'; $parameters['search_code'] = '%' . $search . '%'; $parameters['search_name'] = '%' . $search . '%'; }
        if ($status !== 'all') { $conditions[] = 'pc.is_active = :is_active'; $parameters['is_active'] = $status === 'active' ? 1 : 0; }
        $where = implode(' AND ', $conditions);
        $count = $this->database->prepare("SELECT COUNT(*) FROM payment_channels pc WHERE {$where}");
        $count->execute($parameters); $total = (int) $count->fetchColumn();
        $statement = $this->database->prepare(
            "SELECT pc.sic_code, pc.channel_name, pc.is_active, pc.created_at, pc.updated_at,
                    COUNT(t.id) aggregate_rows, COALESCE(SUM(t.total_trx), 0) total_trx
             FROM payment_channels pc LEFT JOIN transaction_aggregates t ON t.sic_code = pc.sic_code
             WHERE {$where} GROUP BY pc.sic_code, pc.channel_name, pc.is_active, pc.created_at, pc.updated_at
             ORDER BY pc.is_active DESC, pc.channel_name, pc.sic_code LIMIT :limit OFFSET :offset"
        );
        foreach ($parameters as $name => $value) $statement->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $unmapped = (int) $this->database->query('SELECT COUNT(DISTINCT t.sic_code) FROM transaction_aggregates t LEFT JOIN payment_channels pc ON pc.sic_code = t.sic_code WHERE pc.sic_code IS NULL')->fetchColumn();
        return ['items' => $statement->fetchAll(), 'unmapped_sic_count' => $unmapped, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $perPage))]];
    }

    /** Mengambil maksimal 100 catatan perubahan terbaru untuk satu SIC code. */
    public function history(string $sicCode): array
    {
        $sicCode = $this->normalizeSic($sicCode);
        $statement = $this->database->prepare('SELECT id, action, old_channel_name, new_channel_name, old_is_active, new_is_active, changed_by, created_at FROM payment_channel_change_history WHERE sic_code = :sic_code ORDER BY id DESC LIMIT 100');
        $statement->execute(['sic_code' => $sicCode]);
        return ['sic_code' => $sicCode, 'items' => $statement->fetchAll()];
    }

    /** Menjalankan aksi penambahan atau perubahan status dari payload tervalidasi. */
    public function mutate(string $action, array $payload): array
    {
        return match ($action) {
            'create' => $this->create($payload),
            'set_active' => $this->setActive($payload), default => throw new RuntimeException('Aksi payment channel tidak valid.'),
        };
    }

    /** Membuat mapping baru dan audit CREATED dalam satu database transaction. */
    private function create(array $payload): array
    {
        $sicCode = $this->normalizeSic($payload['sic_code'] ?? null); $name = $this->normalizeName($payload['channel_name'] ?? null);
        $this->database->beginTransaction();
        try {
            $statement = $this->database->prepare('INSERT INTO payment_channels (sic_code, channel_name, is_active) VALUES (:sic_code, :channel_name, 1)');
            $statement->execute(['sic_code' => $sicCode, 'channel_name' => $name]);
            $new = ['channel_name' => $name, 'is_active' => 1]; $this->recordChange($sicCode, 'CREATED', null, $new);
            $this->database->commit(); return ['status' => 'CREATED', 'item' => ['sic_code' => $sicCode, ...$new]];
        } catch (PDOException $error) {
            if ($this->database->inTransaction()) $this->database->rollBack();
            if ($error->getCode() === '23000') throw new RuntimeException('SIC code sudah tersedia. Mapping yang ada dapat diaktifkan atau dinonaktifkan.');
            throw $error;
        } catch (Throwable $error) { if ($this->database->inTransaction()) $this->database->rollBack(); throw $error; }
    }

    /** Mengaktifkan atau menonaktifkan mapping secara reversible dan mencatat audit status. */
    private function setActive(array $payload): array
    {
        $sicCode = $this->normalizeSic($payload['sic_code'] ?? null);
        if (!array_key_exists('is_active', $payload) || !is_bool($payload['is_active'])) throw new RuntimeException('Status aktif harus berupa boolean.');
        $isActive = $payload['is_active'] ? 1 : 0; $this->database->beginTransaction();
        try {
            $old = $this->lock($sicCode);
            if ((int) $old['is_active'] !== $isActive) {
                $statement = $this->database->prepare('UPDATE payment_channels SET is_active = :is_active WHERE sic_code = :sic_code');
                $statement->execute(['is_active' => $isActive, 'sic_code' => $sicCode]);
                $this->recordChange($sicCode, $isActive === 1 ? 'ACTIVATED' : 'DEACTIVATED', $old, ['channel_name' => $old['channel_name'], 'is_active' => $isActive]);
            }
            $this->database->commit(); return ['status' => 'UPDATED', 'item' => ['sic_code' => $sicCode, 'channel_name' => $old['channel_name'], 'is_active' => $isActive]];
        } catch (Throwable $error) { if ($this->database->inTransaction()) $this->database->rollBack(); throw $error; }
    }

    /** Mengambil dan mengunci satu mapping untuk mutasi yang aman dari request bersamaan. */
    private function lock(string $sicCode): array
    {
        $statement = $this->database->prepare('SELECT sic_code, channel_name, is_active FROM payment_channels WHERE sic_code = :sic_code FOR UPDATE');
        $statement->execute(['sic_code' => $sicCode]); $channel = $statement->fetch();
        if ($channel === false) throw new RuntimeException('Payment channel tidak ditemukan.');
        return $channel;
    }

    /** Menyimpan satu catatan audit perubahan master payment channel. */
    private function recordChange(string $sicCode, string $action, ?array $old, ?array $new): void
    {
        $statement = $this->database->prepare('INSERT INTO payment_channel_change_history (sic_code, action, old_channel_name, new_channel_name, old_is_active, new_is_active, changed_by) VALUES (:sic_code, :action, :old_name, :new_name, :old_active, :new_active, NULL)');
        $statement->execute(['sic_code' => $sicCode, 'action' => $action, 'old_name' => $old['channel_name'] ?? null, 'new_name' => $new['channel_name'] ?? null, 'old_active' => isset($old['is_active']) ? (int) $old['is_active'] : null, 'new_active' => isset($new['is_active']) ? (int) $new['is_active'] : null]);
    }

    /** Menormalisasi SIC code sebagai identifier tanpa menghilangkan leading zero. */
    private function normalizeSic(mixed $value): string
    {
        $sicCode = trim((string) $value);
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $sicCode)) throw new RuntimeException('SIC code wajib 1-32 karakter berupa huruf, angka, underscore, atau tanda hubung.');
        return $sicCode;
    }

    /** Menormalisasi whitespace nama channel dan menerapkan batas kolom database. */
    private function normalizeName(mixed $value): string
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $value)) ?? '';
        if ($name === '' || mb_strlen($name) > 160) throw new RuntimeException('Nama payment channel wajib diisi dan maksimal 160 karakter.');
        return $name;
    }
}
