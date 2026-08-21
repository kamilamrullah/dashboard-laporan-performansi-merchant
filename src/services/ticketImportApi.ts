import type { TicketImportBatchDetail, TicketImportHistory, TicketImportOutcome, TicketImportPreview, TicketImportPreviewPage, TicketImportResult, TicketPreviewDeleteResult } from '../types';
import { apiFetch } from './apiClient';

interface ApiErrorPayload { error?: string; message?: string; }
const previewTokenPrefix = 'ticket-import-preview-token:';

// Membaca response JSON tiket dan menghasilkan pesan error backend yang konsisten.
async function parseTicketResponse<T>(response: Response, fallbackMessage: string): Promise<T> {
  let payload: T & ApiErrorPayload;
  try { payload = await response.json() as T & ApiErrorPayload; }
  catch { throw new Error(fallbackMessage); }
  if (!response.ok) throw new Error(payload.error ?? payload.message ?? fallbackMessage);
  return payload;
}

// Mengunggah workbook tiket untuk validasi dan staging tanpa menulis ke tabel utama.
export async function previewTicketImport(file: File, merchantId: number | null, newMerchantName: string | null): Promise<TicketImportPreview> {
  const form = new FormData();
  form.append('file', file);
  if (merchantId !== null) form.append('merchant_id', String(merchantId));
  if (newMerchantName !== null) form.append('new_merchant_name', newMerchantName);
  const response = await apiFetch('/api/ticket-import-preview.php', { method: 'POST', body: form, headers: { Accept: 'application/json' } });
  return parseTicketResponse<TicketImportPreview>(response, 'Preview tiket aduan gagal diproses.');
}

// Mengonfirmasi batch tiket dan menerapkan pilihan update untuk baris yang berubah.
export async function confirmTicketImport(preview: TicketImportPreview, changedRowsAction: 'skip' | 'update'): Promise<TicketImportResult> {
  const response = await apiFetch('/api/ticket-import-confirm.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ batch_id: preview.batch_id, confirmation_token: preview.confirmation_token, changed_rows_action: changedRowsAction }) });
  return parseTicketResponse<TicketImportResult>(response, 'Konfirmasi import tiket gagal diproses.');
}

// Mengambil halaman staging preview tiket dengan token tetap berada pada request body.
export async function fetchTicketPreviewRows(preview: TicketImportPreview, page: number, outcome: TicketImportOutcome | ''): Promise<TicketImportPreviewPage> {
  const response = await apiFetch('/api/ticket-import-preview-rows.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ batch_id: preview.batch_id, confirmation_token: preview.confirmation_token, page, per_page: 50, outcome }) });
  return parseTicketResponse<TicketImportPreviewPage>(response, 'Halaman preview tiket gagal dimuat.');
}

// Menyimpan token preview dalam sesi browser agar preview dapat dilanjutkan dari riwayat.
export function storeTicketPreviewToken(batchId: number, token: string): void {
  try { sessionStorage.setItem(`${previewTokenPrefix}${batchId}`, token); } catch { /* Preview tetap aktif dalam state bila storage browser ditolak. */ }
}

// Mengambil token preview tiket hanya dari sesi browser yang membuat preview.
export function getTicketPreviewToken(batchId: number): string | null {
  try { return sessionStorage.getItem(`${previewTokenPrefix}${batchId}`); } catch { return null; }
}

// Menghapus token lokal setelah batch selesai atau preview dihapus.
export function clearTicketPreviewToken(batchId: number): void {
  try { sessionStorage.removeItem(`${previewTokenPrefix}${batchId}`); } catch { /* Tidak ada data server yang perlu dipulihkan. */ }
}

// Menghapus staging preview tiket yang belum dikonfirmasi.
export async function deleteTicketPreview(batchId: number, token: string): Promise<TicketPreviewDeleteResult> {
  const response = await apiFetch('/api/ticket-import-preview-delete.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ batch_id: batchId, confirmation_token: token }) });
  return parseTicketResponse<TicketPreviewDeleteResult>(response, 'Preview tiket gagal dihapus.');
}

// Mengambil daftar riwayat batch tiket terbaru secara paginated.
export async function fetchTicketImportHistory(page: number, signal?: AbortSignal): Promise<TicketImportHistory> {
  const parameters = new URLSearchParams({ page: String(page), per_page: '20' });
  const response = await apiFetch(`/api/ticket-import-history.php?${parameters}`, { signal, headers: { Accept: 'application/json' } });
  return parseTicketResponse<TicketImportHistory>(response, 'Riwayat import tiket gagal dimuat.');
}

// Mengambil metadata dan audit baris dari satu batch tiket.
export async function fetchTicketImportBatch(batchId: number, page: number, signal?: AbortSignal): Promise<TicketImportBatchDetail> {
  const parameters = new URLSearchParams({ batch_id: String(batchId), page: String(page), per_page: '50' });
  const response = await apiFetch(`/api/ticket-import-history.php?${parameters}`, { signal, headers: { Accept: 'application/json' } });
  return parseTicketResponse<TicketImportBatchDetail>(response, 'Detail batch tiket gagal dimuat.');
}
