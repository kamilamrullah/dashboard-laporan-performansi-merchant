import type { PaymentChannelChangeHistory, PaymentChannelMasterList } from '../types';
import { apiFetch } from './apiClient';

// Membaca response JSON master data dan mengubah kegagalan HTTP menjadi Error yang konsisten.
async function parsePaymentChannelResponse<T>(response: Response, fallback: string): Promise<T> {
  let payload: T & { error?: string };
  try { payload = await response.json() as T & { error?: string }; }
  catch { throw new Error(fallback); }
  if (!response.ok) throw new Error(payload.error ?? fallback);
  return payload;
}

// Mengambil daftar payment channel berdasarkan pencarian, status, dan halaman.
export async function fetchPaymentChannels(search: string, status: 'all' | 'active' | 'inactive', page: number, signal?: AbortSignal): Promise<PaymentChannelMasterList> {
  const parameters = new URLSearchParams({ search, status, page: String(page), per_page: '20' });
  const response = await apiFetch(`/api/payment-channels.php?${parameters}`, { signal, headers: { Accept: 'application/json' } });
  return parsePaymentChannelResponse<PaymentChannelMasterList>(response, 'Master payment channel gagal dimuat.');
}

// Membuat mapping SIC code baru dengan status aktif.
export async function createPaymentChannel(sicCode: string, channelName: string): Promise<void> {
  const response = await apiFetch('/api/payment-channels.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'create', sic_code: sicCode, channel_name: channelName }) });
  await parsePaymentChannelResponse(response, 'Payment channel gagal ditambahkan.');
}

// Mengubah status aktif mapping secara reversible.
export async function setPaymentChannelActive(sicCode: string, isActive: boolean): Promise<void> {
  const response = await apiFetch('/api/payment-channels.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'set_active', sic_code: sicCode, is_active: isActive }) });
  await parsePaymentChannelResponse(response, 'Status payment channel gagal diubah.');
}

// Mengambil audit perubahan satu SIC code untuk panel history.
export async function fetchPaymentChannelHistory(sicCode: string, signal?: AbortSignal): Promise<PaymentChannelChangeHistory[]> {
  const parameters = new URLSearchParams({ history_sic_code: sicCode });
  const response = await apiFetch(`/api/payment-channels.php?${parameters}`, { signal, headers: { Accept: 'application/json' } });
  const payload = await parsePaymentChannelResponse<{ items: PaymentChannelChangeHistory[] }>(response, 'Riwayat payment channel gagal dimuat.');
  return payload.items;
}
