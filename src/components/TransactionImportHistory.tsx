import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, ChevronLeft, ChevronRight, Eye, LoaderCircle, RefreshCw } from 'lucide-react';
import { fetchTransactionImportBatch, fetchTransactionImportHistory } from '../services/transactionImportApi';
import type { TransactionImportBatchDetail, TransactionImportHistory as HistoryData } from '../types';

const statusStyles: Record<string, string> = {
  COMPLETED: 'bg-emerald-100 text-emerald-700', PREVIEWED: 'bg-sky-100 text-sky-700',
  PROCESSING: 'bg-amber-100 text-amber-700', FAILED: 'bg-rose-100 text-rose-700',
};

// Memformat tanggal database menjadi waktu Indonesia yang ringkas dan mudah dipindai.
function formatDateTime(value: string | null): string {
  if (!value) return '—';
  const parsed = new Date(value.replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? value : new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

// Memformat angka audit tanpa mengubah presisi string nominal besar.
function formatAuditNumber(value: number | string | undefined): string {
  if (value === undefined) return '—';
  const [whole, fraction = ''] = String(value).split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return fraction ? `${grouped},${fraction}` : grouped;
}

// Menampilkan kontrol halaman yang konsisten untuk daftar batch dan detail baris.
function Pagination({ page, totalPages, onChange }: { page: number; totalPages: number; onChange: (page: number) => void }) {
  return <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500"><span>Halaman {page} dari {totalPages}</span><div className="flex gap-2"><button aria-label="Halaman sebelumnya" disabled={page <= 1} onClick={() => onChange(page - 1)} className="rounded-lg border border-slate-200 p-2 disabled:opacity-40"><ChevronLeft className="h-3.5 w-3.5"/></button><button aria-label="Halaman berikutnya" disabled={page >= totalPages} onClick={() => onChange(page + 1)} className="rounded-lg border border-slate-200 p-2 disabled:opacity-40"><ChevronRight className="h-3.5 w-3.5"/></button></div></div>;
}

// Menampilkan metadata batch dan hasil pemrosesan setiap baris secara paginated.
function BatchDetail({ batchId, onBack }: { batchId: number; onBack: () => void }) {
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<TransactionImportBatchDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Memuat halaman detail dan membatalkan request bila komponen berganti batch atau dilepas.
  useEffect(() => {
    const controller = new AbortController();
    setIsLoading(true); setError(null);
    void fetchTransactionImportBatch(batchId, page, controller.signal).then(setDetail).catch((reason: unknown) => {
      if (reason instanceof DOMException && reason.name === 'AbortError') return;
      setError(reason instanceof Error ? reason.message : 'Detail batch gagal dimuat.');
    }).finally(() => { if (!controller.signal.aborted) setIsLoading(false); });
    return () => controller.abort();
  }, [batchId, page]);

  if (isLoading && !detail) return <div className="flex min-h-64 items-center justify-center text-xs font-semibold text-slate-500"><LoaderCircle className="mr-2 h-4 w-4 animate-spin"/>Memuat detail batch...</div>;
  if (error && !detail) return <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-700">{error}</div>;
  if (!detail) return null;
  const batch = detail.batch;
  return <div className="space-y-4"><button onClick={onBack} className="inline-flex items-center gap-2 text-xs font-bold text-indigo-600"><ArrowLeft className="h-4 w-4"/>Kembali ke riwayat</button><section className="rounded-2xl border border-slate-200 bg-white p-5"><div className="flex flex-col justify-between gap-3 sm:flex-row"><div><div className="flex items-center gap-2"><h3 className="text-sm font-bold text-slate-900">Batch #{batch.id}</h3><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${statusStyles[batch.status] ?? 'bg-slate-100 text-slate-600'}`}>{batch.status}</span></div><p className="mt-1 text-xs text-slate-500">{batch.original_filename} · {batch.merchant_name ?? 'Merchant tidak tersedia'}</p><p className="mt-1 text-[10px] text-slate-400">{batch.detected_period_start ?? '—'} sampai {batch.detected_period_end ?? '—'} · dibuat {formatDateTime(batch.created_at)}</p></div><div className="grid grid-cols-4 gap-2 text-center">{[['Insert', batch.inserted_rows], ['Update', batch.updated_rows], ['Duplikat', batch.duplicate_rows], ['Ditolak', batch.rejected_rows]].map(([label, value]) => <div key={label} className="rounded-lg bg-slate-50 px-3 py-2"><p className="text-sm font-bold text-slate-900">{value}</p><p className="text-[9px] uppercase text-slate-400">{label}</p></div>)}</div></div></section>{error && <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error}</div>}<div className="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div className="overflow-x-auto scrollbar-subtle"><table className="min-w-[1050px] w-full text-left text-xs"><thead className="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th className="px-4 py-3">Baris</th><th className="px-4 py-3">Hasil</th><th className="px-4 py-3">Tanggal</th><th className="px-4 py-3">Tipe</th><th className="px-4 py-3">Partner</th><th className="px-4 py-3">SIC / RC</th><th className="px-4 py-3 text-right">Total trx</th><th className="px-4 py-3 text-right">Nominal</th></tr></thead><tbody className="divide-y divide-slate-100">{detail.rows.items.map((row) => { const data = row.normalized_data; const old = row.existing_data; return <tr key={row.id}><td className="px-4 py-3 font-mono text-slate-500">{row.source_row_number}</td><td className="px-4 py-3"><span className="rounded-full bg-slate-100 px-2 py-1 text-[9px] font-bold text-slate-700">{row.outcome}</span>{row.validation_errors?.message && <p className="mt-2 max-w-56 text-[10px] text-rose-600">{row.validation_errors.message}</p>}</td><td className="px-4 py-3">{data?.transaction_date ?? '—'}</td><td className="px-4 py-3 font-semibold">{data?.transaction_type ?? '—'}</td><td className="px-4 py-3">{data?.partner_channel ?? '—'}</td><td className="px-4 py-3 font-mono text-[10px]">{data ? `${data.sic_code} / ${data.response_code}` : '—'}</td><td className="px-4 py-3 text-right">{old && old.total_trx !== data?.total_trx && <span className="mr-2 text-[10px] text-slate-400 line-through">{formatAuditNumber(old.total_trx)}</span>}{formatAuditNumber(data?.total_trx)}</td><td className="px-4 py-3 text-right">{old && old.total_amount !== data?.total_amount && <span className="mr-2 text-[10px] text-slate-400 line-through">{formatAuditNumber(old.total_amount)}</span>}{formatAuditNumber(data?.total_amount)}</td></tr>; })}{detail.rows.items.length === 0 && <tr><td colSpan={8} className="px-4 py-10 text-center text-slate-400">Batch belum memiliki audit baris.</td></tr>}</tbody></table></div><Pagination page={detail.rows.pagination.page} totalPages={detail.rows.pagination.total_pages} onChange={setPage}/></div></div>;
}

// Menampilkan daftar batch import transaksi dan membuka detail audit batch yang dipilih.
export function TransactionImportHistory() {
  const [page, setPage] = useState(1);
  const [refreshKey, setRefreshKey] = useState(0);
  const [selectedBatch, setSelectedBatch] = useState<number | null>(null);
  const [history, setHistory] = useState<HistoryData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Memicu pemuatan ulang daftar tanpa mengubah halaman yang sedang dibuka.
  const refresh = useCallback(() => setRefreshKey((value) => value + 1), []);

  // Memuat daftar batch dan membatalkan request lama saat halaman berubah.
  useEffect(() => {
    const controller = new AbortController();
    setIsLoading(true); setError(null);
    void fetchTransactionImportHistory(page, controller.signal).then(setHistory).catch((reason: unknown) => {
      if (reason instanceof DOMException && reason.name === 'AbortError') return;
      setError(reason instanceof Error ? reason.message : 'Riwayat import gagal dimuat.');
    }).finally(() => { if (!controller.signal.aborted) setIsLoading(false); });
    return () => controller.abort();
  }, [page, refreshKey]);

  if (selectedBatch !== null) return <BatchDetail batchId={selectedBatch} onBack={() => setSelectedBatch(null)}/>;
  return <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><header className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h3 className="text-sm font-bold text-slate-900">Riwayat Import Transaksi</h3><p className="mt-1 text-[10px] text-slate-400">Batch terbaru, hasil pemrosesan, dan audit setiap baris</p></div><button onClick={refresh} disabled={isLoading} className="rounded-lg border border-slate-200 p-2 text-slate-500 disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`}/></button></header>{error && <div className="m-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error}</div>}{isLoading && !history ? <div className="flex min-h-64 items-center justify-center text-xs font-semibold text-slate-500"><LoaderCircle className="mr-2 h-4 w-4 animate-spin"/>Memuat riwayat...</div> : <><div className="overflow-x-auto scrollbar-subtle"><table className="min-w-[1000px] w-full text-left text-xs"><thead className="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th className="px-4 py-3">Batch</th><th className="px-4 py-3">Merchant / File</th><th className="px-4 py-3">Periode</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Insert</th><th className="px-4 py-3 text-right">Update</th><th className="px-4 py-3 text-right">Duplikat</th><th className="px-4 py-3 text-right">Ditolak</th><th className="px-4 py-3">Waktu</th><th className="px-4 py-3"></th></tr></thead><tbody className="divide-y divide-slate-100">{history?.items.map((batch) => <tr key={batch.id} className="hover:bg-slate-50"><td className="px-4 py-3 font-mono font-bold text-slate-600">#{batch.id}</td><td className="px-4 py-3"><p className="font-bold text-slate-800">{batch.merchant_name ?? '—'}</p><p className="mt-1 max-w-56 truncate text-[10px] text-slate-400">{batch.original_filename}</p></td><td className="px-4 py-3 text-[10px]">{batch.detected_period_start ?? '—'}<br/>{batch.detected_period_end ?? '—'}</td><td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${statusStyles[batch.status] ?? 'bg-slate-100 text-slate-600'}`}>{batch.status}</span></td><td className="px-4 py-3 text-right font-semibold">{batch.inserted_rows}</td><td className="px-4 py-3 text-right font-semibold">{batch.updated_rows}</td><td className="px-4 py-3 text-right">{batch.duplicate_rows}</td><td className="px-4 py-3 text-right">{batch.rejected_rows}</td><td className="px-4 py-3 text-[10px] text-slate-500">{formatDateTime(batch.completed_at ?? batch.created_at)}</td><td className="px-4 py-3"><button onClick={() => setSelectedBatch(batch.id)} className="rounded-lg border border-slate-200 p-2 text-indigo-600 hover:bg-indigo-50" aria-label={`Lihat batch ${batch.id}`}><Eye className="h-3.5 w-3.5"/></button></td></tr>)}{history?.items.length === 0 && <tr><td colSpan={10} className="px-4 py-12 text-center text-slate-400">Belum ada riwayat import transaksi.</td></tr>}</tbody></table></div>{history && <Pagination page={history.pagination.page} totalPages={history.pagination.total_pages} onChange={setPage}/>}</>}</section>;
}
