import type { TransactionImportBatchDetail, TransactionImportHistory, TransactionImportPreview, TransactionImportPreviewPage, TransactionImportResult, TransactionImportOutcome } from '../types';

interface ApiErrorPayload { error?: string; message?: string; }

// Membaca response JSON dan menghasilkan error konsisten ketika server menolak request.
async function parseResponse<T>(response: Response, fallbackMessage: string): Promise<T> {
  let payload: T & ApiErrorPayload;
  try {
    payload = await response.json() as T & ApiErrorPayload;
  } catch {
    throw new Error(fallbackMessage);
  }
  if (!response.ok) throw new Error(payload.error ?? payload.message ?? fallbackMessage);
  return payload;
}

// Mengunggah workbook dan mengambil hasil validasi serta perbandingan tanpa mengimpor data aktif.
export async function previewTransactionImport(file: File, merchantId: number | null, newMerchantName: string | null): Promise<TransactionImportPreview> {
  const form = new FormData();
  form.append('file', file);
  if (merchantId !== null) form.append('merchant_id', String(merchantId));
  if (newMerchantName !== null) form.append('new_merchant_name', newMerchantName);
  const response = await fetch('/api/transaction-import-preview.php', { method: 'POST', body: form, headers: { Accept: 'application/json' } });
  return parseResponse<TransactionImportPreview>(response, 'Preview transaksi gagal diproses.');
}

// Mengonfirmasi batch preview dengan pilihan memperbarui atau melewati data yang berubah.
export async function confirmTransactionImport(preview: TransactionImportPreview, changedRowsAction: 'skip' | 'update', confirmedBy: string): Promise<TransactionImportResult> {
  const response = await fetch('/api/transaction-import-confirm.php', {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ batch_id: preview.batch_id, confirmation_token: preview.confirmation_token, changed_rows_action: changedRowsAction, confirmed_by: confirmedBy }),
  });
  return parseResponse<TransactionImportResult>(response, 'Konfirmasi import transaksi gagal diproses.');
}

// Mengambil halaman staging preview menggunakan token dalam body agar tidak tercatat pada URL server.
export async function fetchTransactionPreviewRows(preview: TransactionImportPreview, page: number, outcome: TransactionImportOutcome | ''): Promise<TransactionImportPreviewPage> {
  const response = await fetch('/api/transaction-import-preview-rows.php', {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ batch_id: preview.batch_id, confirmation_token: preview.confirmation_token, page, per_page: 50, outcome }),
  });
  return parseResponse<TransactionImportPreviewPage>(response, 'Halaman preview transaksi gagal dimuat.');
}

// Mengambil daftar batch transaksi terbaru dengan pagination untuk halaman riwayat.
export async function fetchTransactionImportHistory(page: number, signal?: AbortSignal): Promise<TransactionImportHistory> {
  const parameters = new URLSearchParams({ page: String(page), per_page: '20' });
  const response = await fetch(`/api/transaction-import-history.php?${parameters}`, { signal, headers: { Accept: 'application/json' } });
  return parseResponse<TransactionImportHistory>(response, 'Riwayat import transaksi gagal dimuat.');
}

// Mengambil metadata dan halaman detail baris dari satu batch import transaksi.
export async function fetchTransactionImportBatch(batchId: number, page: number, signal?: AbortSignal): Promise<TransactionImportBatchDetail> {
  const parameters = new URLSearchParams({ batch_id: String(batchId), page: String(page), per_page: '50' });
  const response = await fetch(`/api/transaction-import-history.php?${parameters}`, { signal, headers: { Accept: 'application/json' } });
  return parseResponse<TransactionImportBatchDetail>(response, 'Detail batch transaksi gagal dimuat.');
}
