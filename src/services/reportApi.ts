import { apiFetch } from './apiClient';

export interface ReportMerchantOption { id: number; merchant_code: string; merchant_name: string; periods: string[]; }
export interface ReportOptions { merchants: ReportMerchantOption[]; }
export interface GeneratedReport { report_run_id: number; filename: string; sha256: string; sections: string[]; download_url: string; }

// Mengambil merchant dan periode transaksi yang memenuhi syarat untuk dibuatkan laporan.
export async function fetchReportOptions(signal?: AbortSignal): Promise<ReportOptions> {
  const response = await apiFetch('/api/report-options.php', { signal, headers: { Accept: 'application/json' } });
  const payload = await response.json() as ReportOptions & { error?: string };
  if (!response.ok) throw new Error(payload.error ?? 'Pilihan laporan gagal dimuat.');
  return payload;
}

// Meminta backend membuat dokumen Word untuk merchant dan periode yang dipilih.
export async function generateReport(merchantId: number, reportPeriod: string): Promise<GeneratedReport> {
  const response = await apiFetch('/api/report-generate.php', { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ merchant_id: merchantId, report_period: reportPeriod }) });
  const payload = await response.json() as GeneratedReport & { error?: string };
  if (!response.ok) throw new Error(payload.error ?? 'Laporan Word gagal dibuat.');
  return payload;
}

// Mengunduh dokumen melalui endpoint terautentikasi dan mempertahankan nama file dari backend.
export async function downloadGeneratedReport(downloadUrl: string, filename: string): Promise<void> {
  const response = await apiFetch(downloadUrl.startsWith('/') ? downloadUrl : `/${downloadUrl}`, { headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' } });
  if (!response.ok) {
    const payload = await response.json().catch(() => null) as { error?: string } | null;
    throw new Error(payload?.error ?? 'Laporan gagal diunduh.');
  }
  const objectUrl = URL.createObjectURL(await response.blob());
  const anchor = document.createElement('a');
  anchor.href = objectUrl; anchor.download = filename; document.body.appendChild(anchor); anchor.click(); anchor.remove();
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
}
