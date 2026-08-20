import type { TransactionImportPreview, TransactionImportResult } from '../types';

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
