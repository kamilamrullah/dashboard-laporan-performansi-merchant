<?php
declare(strict_types=1);

namespace App\Auth;

use PDO;
use RuntimeException;
use Throwable;

final class UserService
{
    /** Menyimpan koneksi database untuk seluruh operasi manajemen user. */
    public function __construct(private readonly PDO $database)
    {
    }

    /** Mengambil daftar user publik tanpa password hash atau ID internal. */
    public function list(): array
    {
        return $this->database->query('SELECT u.public_id, u.username, u.email, u.full_name, r.code role, r.name role_name, u.is_active, u.must_change_password, u.last_login_at, u.created_at FROM users u INNER JOIN roles r ON r.id = u.role_id ORDER BY u.created_at DESC, u.id DESC')->fetchAll();
    }

    /** Mengambil pilihan role yang dapat diberikan Super Admin. */
    public function roles(): array
    {
        return $this->database->query('SELECT code, name, description FROM roles ORDER BY id')->fetchAll();
    }

    /** Menjalankan mutasi user yang didukung berdasarkan payload tervalidasi. */
    public function mutate(string $action, array $payload, array $actor): array
    {
        return match ($action) {
            'create' => $this->create($payload),
            'update' => $this->update($payload, $actor),
            'reset_password' => $this->resetPassword($payload),
            default => throw new RuntimeException('Aksi user tidak valid.'),
        };
    }

    /** Membuat user baru dengan password ter-hash dan kewajiban mengganti password awal. */
    private function create(array $payload): array
    {
        $username = trim((string) ($payload['username'] ?? '')); $name = $this->name($payload['full_name'] ?? null); [$password, $hash] = $this->password($payload);
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) throw new RuntimeException('Username harus 3-50 karakter berupa huruf, angka, titik, underscore, atau tanda hubung.');
        unset($password);
        $publicId = $this->uuid();
        $statement = $this->database->prepare('INSERT INTO users (public_id, username, email, full_name, password_hash, role_id, must_change_password) VALUES (:public_id, :username, :email, :full_name, :password_hash, :role_id, 1)');
        $statement->execute(['public_id' => $publicId, 'username' => $username, 'email' => $this->email($payload['email'] ?? null), 'full_name' => $name, 'password_hash' => $hash, 'role_id' => $this->roleId($payload['role'] ?? null)]);
        return ['status' => 'CREATED', 'public_id' => $publicId];
    }

    /** Memperbarui profil, role, dan status dengan perlindungan super admin terakhir. */
    private function update(array $payload, array $actor): array
    {
        $publicId = trim((string) ($payload['public_id'] ?? '')); $name = $this->name($payload['full_name'] ?? null); $active = $payload['is_active'] ?? null; $newRole = (string) ($payload['role'] ?? '');
        if (!is_bool($active)) throw new RuntimeException('Status user tidak valid.');
        $this->database->beginTransaction();
        try {
            $target = $this->lock($publicId); $newRoleId = $this->roleId($newRole);
            if ($publicId === (string) $actor['public_id'] && (!$active || $newRole !== 'super_admin')) throw new RuntimeException('Super admin tidak dapat menurunkan role atau menonaktifkan akunnya sendiri.');
            if ((string) $target['role'] === 'super_admin' && (!$active || $newRole !== 'super_admin')) {
                $count = (int) $this->database->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.code = 'super_admin' AND u.is_active = 1")->fetchColumn();
                if ($count <= 1) throw new RuntimeException('Minimal satu super admin aktif harus tersedia.');
            }
            $statement = $this->database->prepare('UPDATE users SET full_name = :full_name, email = :email, role_id = :role_id, is_active = :is_active, session_version = session_version + 1 WHERE id = :id');
            $statement->execute(['full_name' => $name, 'email' => $this->email($payload['email'] ?? null), 'role_id' => $newRoleId, 'is_active' => $active ? 1 : 0, 'id' => $target['id']]);
            $this->database->commit(); return ['status' => 'UPDATED'];
        } catch (Throwable $error) { if ($this->database->inTransaction()) $this->database->rollBack(); throw $error; }
    }

    /** Mengganti password target, mewajibkan perubahan berikutnya, dan memutus session lama. */
    private function resetPassword(array $payload): array
    {
        [, $hash] = $this->password($payload); $publicId = trim((string) ($payload['public_id'] ?? '')); $this->validatePublicId($publicId);
        $statement = $this->database->prepare('UPDATE users SET password_hash = :hash, must_change_password = 1, session_version = session_version + 1 WHERE public_id = :public_id');
        $statement->execute(['hash' => $hash, 'public_id' => $publicId]); if ($statement->rowCount() !== 1) throw new RuntimeException('User tidak ditemukan.');
        return ['status' => 'PASSWORD_RESET'];
    }

    /** Mengambil user target dengan row lock untuk mutasi yang konsisten. */
    private function lock(string $publicId): array
    {
        $this->validatePublicId($publicId); $statement = $this->database->prepare('SELECT u.id, u.public_id, r.code role FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE u.public_id = :public_id FOR UPDATE'); $statement->execute(['public_id' => $publicId]); $user = $statement->fetch();
        if ($user === false) throw new RuntimeException('User tidak ditemukan.'); return $user;
    }

    /** Mengambil ID role dari kode yang masuk whitelist aplikasi. */
    private function roleId(mixed $value): int
    {
        $code = (string) $value; if (!in_array($code, ['super_admin', 'admin', 'viewer'], true)) throw new RuntimeException('Role user tidak valid.');
        $statement = $this->database->prepare('SELECT id FROM roles WHERE code = :code'); $statement->execute(['code' => $code]); $id = $statement->fetchColumn(); if ($id === false) throw new RuntimeException('Role user belum tersedia.'); return (int) $id;
    }

    /** Menormalisasi nama lengkap dan menerapkan batas kolom. */
    private function name(mixed $value): string
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $value)) ?? ''; if ($name === '' || mb_strlen($name) > 100) throw new RuntimeException('Nama lengkap wajib diisi dan maksimal 100 karakter.'); return $name;
    }

    /** Menormalisasi serta memvalidasi email opsional. */
    private function email(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value)); if ($email === '') return null; if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('Alamat email tidak valid.'); return $email;
    }

    /** Memvalidasi pasangan password dan menghasilkan hash aman. */
    private function password(array $payload): array
    {
        $password = (string) ($payload['password'] ?? ''); $confirmation = (string) ($payload['password_confirmation'] ?? '');
        if (strlen($password) < 8 || strlen($password) > 128 || trim($password) === '' || !hash_equals($password, $confirmation)) throw new RuntimeException('Password harus 8-128 karakter dan konfirmasinya harus sama.');
        $hash = password_hash($password, PASSWORD_DEFAULT); if ($hash === false) throw new RuntimeException('Password tidak dapat diamankan.'); return [$password, $hash];
    }

    /** Memastikan identifier publik memiliki bentuk UUID. */
    private function validatePublicId(string $publicId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $publicId)) throw new RuntimeException('User tidak valid.');
    }

    /** Membuat UUID v4 menggunakan sumber acak kriptografis. */
    private function uuid(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
