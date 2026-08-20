import { apiFetch } from './apiClient';
import type { UserRole } from './authApi';

export interface ManagedUser { public_id: string; username: string; email: string | null; full_name: string; role: UserRole; role_name: string; is_active: number | boolean; must_change_password: number | boolean; last_login_at: string | null; created_at: string; }
export interface RoleOption { code: UserRole; name: string; description: string; }

// Membaca response Manajemen Pengguna dan menyeragamkan error backend.
async function parseUserResponse<T>(response: Response): Promise<T> {
  const payload = await response.json() as T & { error?: string };
  if (!response.ok) throw new Error(payload.error ?? 'Manajemen pengguna gagal diproses.');
  return payload;
}

// Mengambil seluruh akun untuk Super Admin.
export async function fetchUsers(): Promise<ManagedUser[]> { return (await parseUserResponse<{ items: ManagedUser[] }>(await apiFetch('/api/users.php'))).items; }

// Mengambil role yang dapat dipilih saat mengelola akun.
export async function fetchRoles(): Promise<RoleOption[]> { return (await parseUserResponse<{ items: RoleOption[] }>(await apiFetch('/api/users.php?resource=roles'))).items; }

// Membuat akun baru dengan password sementara.
export async function createUser(input: { username: string; full_name: string; email: string; role: UserRole; password: string; password_confirmation: string }): Promise<void> { await parseUserResponse(await apiFetch('/api/users.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', ...input }) })); }

// Memperbarui profil, role, dan status akun terpilih.
export async function updateUser(input: { public_id: string; full_name: string; email: string; role: UserRole; is_active: boolean }): Promise<void> { await parseUserResponse(await apiFetch('/api/users.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'update', ...input }) })); }

// Mengatur password sementara baru dan memutus session lama target.
export async function resetUserPassword(publicId: string, password: string, confirmation: string): Promise<void> { await parseUserResponse(await apiFetch('/api/users.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'reset_password', public_id: publicId, password, password_confirmation: confirmation }) })); }
