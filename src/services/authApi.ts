import { apiFetch, setCsrfToken } from './apiClient';

export type UserRole = 'super_admin' | 'admin' | 'viewer';
export interface AuthUser { public_id: string; username: string; email: string | null; full_name: string; role: UserRole; must_change_password: boolean; }
export interface AuthSession { authenticated: boolean; user: AuthUser | null; csrf_token: string; message?: string; }

// Membaca payload auth dan menyimpan CSRF token terbaru dari server.
async function parseAuth(response: Response): Promise<AuthSession> {
  const payload = await response.json() as AuthSession & { error?: string };
  if (!response.ok) throw new Error(payload.error ?? 'Autentikasi gagal diproses.');
  setCsrfToken(payload.csrf_token); return payload;
}

// Memeriksa session saat aplikasi pertama kali dibuka.
export async function fetchAuthSession(): Promise<AuthSession> { return parseAuth(await apiFetch('/api/auth.php?action=session')); }

// Mengautentikasi username/email dan password menggunakan CSRF session awal.
export async function login(loginValue: string, password: string): Promise<AuthSession> {
  return parseAuth(await apiFetch('/api/auth.php?action=login', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ login: loginValue, password }) }));
}

// Mengakhiri session aktif dan mengganti CSRF token browser.
export async function logout(): Promise<AuthSession> { return parseAuth(await apiFetch('/api/auth.php?action=logout', { method: 'POST', headers: { Accept: 'application/json' } })); }

// Mengganti password wajib lalu mengakhiri semua session lama.
export async function changePassword(currentPassword: string, newPassword: string, confirmation: string): Promise<AuthSession> {
  return parseAuth(await apiFetch('/api/auth.php?action=change-password', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ current_password: currentPassword, new_password: newPassword, new_password_confirmation: confirmation }) }));
}
