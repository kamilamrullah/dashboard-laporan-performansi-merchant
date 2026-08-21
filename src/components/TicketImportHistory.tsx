import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, ChevronLeft, ChevronRight, Eye, LoaderCircle, Play, RefreshCw, Trash2 } from 'lucide-react';
import { clearTicketPreviewToken, deleteTicketPreview, fetchTicketImportBatch, fetchTicketImportHistory, getTicketPreviewToken } from '../services/ticketImportApi';
import type { TicketImportBatchDetail, TicketImportHistory as HistoryData } from '../types';

const statusStyles: Record<string, string> = { COMPLETED: 'bg-emerald-100 text-emerald-700', PREVIEWED: 'bg-sky-100 text-sky-700', PROCESSING: 'bg-amber-100 text-amber-700', FAILED: 'bg-rose-100 text-rose-700' };

// Memformat total menit kalender sebagai Hari:Jam:Menit dengan jam dan menit dua digit.
function formatElapsedMinutes(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—';
  const days = Math.floor(value / 1440); const hours = Math.floor((value % 1440) / 60); const minutes = value % 60;
  return `${days}:${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
}

// Memformat datetime database untuk daftar riwayat tiket tanpa mengubah nilai auditnya.
function formatDateTime(value: string | null): string {
  if (!value) return '—';
  const parsed = new Date(value.replace(' ', 'T'));
  return Number.isNaN(parsed.getTime()) ? value : new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed);
}

// Menampilkan navigasi halaman yang digunakan daftar batch dan audit baris tiket.
function Pagination({ page, totalPages, onChange }: { page: number; totalPages: number; onChange: (page: number) => void }) {
  return <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500"><span>Halaman {page} dari {totalPages}</span><div className="flex gap-2"><button aria-label="Halaman sebelumnya" disabled={page <= 1} onClick={() => onChange(page - 1)} className="rounded-lg border border-slate-200 p-2 disabled:opacity-40"><ChevronLeft className="h-3.5 w-3.5"/></button><button aria-label="Halaman berikutnya" disabled={page >= totalPages} onClick={() => onChange(page + 1)} className="rounded-lg border border-slate-200 p-2 disabled:opacity-40"><ChevronRight className="h-3.5 w-3.5"/></button></div></div>;
}

// Menampilkan metadata dan audit baris satu batch tiket serta aksi preview yang masih aktif.
function TicketBatchDetail({ batchId, onBack, onResume, onDeleted }: { batchId: number; onBack: () => void; onResume: (detail: TicketImportBatchDetail) => void; onDeleted: () => void }) {
  const [page, setPage] = useState(1);
  const [detail, setDetail] = useState<TicketImportBatchDetail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isDeleting, setIsDeleting] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  // Memuat detail audit dan membatalkan request ketika batch atau halaman berubah.
  useEffect(() => {
    const controller = new AbortController(); setIsLoading(true); setError(null);
    void fetchTicketImportBatch(batchId, page, controller.signal).then(setDetail).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Detail batch tiket gagal dimuat.'); }).finally(() => { if (!controller.signal.aborted) setIsLoading(false); });
    return () => controller.abort();
  }, [batchId, page]);

  // Menghapus preview tiket setelah pengguna mengonfirmasi tindakan tersebut.
  const removePreview = async () => {
    const token = getTicketPreviewToken(batchId); if (!token) return;
    setIsDeleting(true); setError(null);
    try { await deleteTicketPreview(batchId, token); clearTicketPreviewToken(batchId); onDeleted(); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Preview tiket gagal dihapus.'); setConfirmDelete(false); }
    finally { setIsDeleting(false); }
  };

  if (isLoading && !detail) return <div className="flex min-h-64 items-center justify-center text-xs font-semibold text-slate-500"><LoaderCircle className="mr-2 h-4 w-4 animate-spin"/>Memuat detail tiket...</div>;
  if (!detail) return <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-700">{error ?? 'Detail batch tidak tersedia.'}</div>;
  const { batch } = detail;
  const token = getTicketPreviewToken(batch.id);
  const canResume = batch.status === 'PREVIEWED' && token !== null && batch.confirmation_expires_at !== null && new Date(batch.confirmation_expires_at.replace(' ', 'T')).getTime() >= Date.now();
  return <div className="space-y-4"><button onClick={onBack} className="inline-flex items-center gap-2 text-xs font-bold text-indigo-600"><ArrowLeft className="h-4 w-4"/>Kembali ke riwayat</button><section className="rounded-2xl border border-slate-200 bg-white p-5"><div className="flex flex-col justify-between gap-4 sm:flex-row"><div><div className="flex items-center gap-2"><h3 className="text-sm font-bold text-slate-900">Batch #{batch.id}</h3><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${statusStyles[batch.status] ?? 'bg-slate-100 text-slate-600'}`}>{batch.status}</span></div><p className="mt-1 text-xs font-semibold text-slate-700">{batch.original_filename}</p><p className="mt-1 text-[10px] text-slate-400">{batch.merchant_name ?? '—'} · {batch.detected_period_start ?? '—'} sampai {batch.detected_period_end ?? '—'}</p></div><div className="grid grid-cols-4 gap-2 text-center">{[['Insert', batch.inserted_rows], ['Update', batch.updated_rows], ['Duplikat', batch.duplicate_rows], ['Ditolak', batch.rejected_rows]].map(([label, value]) => <div key={label} className="rounded-lg bg-slate-50 px-3 py-2"><p className="text-sm font-bold">{value}</p><p className="text-[9px] uppercase text-slate-400">{label}</p></div>)}</div></div>{canResume && <div className="mt-4 flex gap-2 border-t border-slate-100 pt-4"><button onClick={() => onResume(detail)} className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white"><Play className="h-3.5 w-3.5"/>Lanjutkan Import</button><button onClick={() => setConfirmDelete(true)} className="inline-flex items-center gap-2 rounded-xl border border-rose-200 px-4 py-2.5 text-xs font-bold text-rose-700"><Trash2 className="h-3.5 w-3.5"/>Hapus Preview</button></div>}{confirmDelete && <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4"><p className="text-xs font-bold text-rose-900">Hapus preview tiket ini?</p><p className="mt-1 text-[10px] text-rose-700">Data staging yang belum dikonfirmasi akan dihapus.</p><div className="mt-3 flex gap-2"><button onClick={() => setConfirmDelete(false)} className="rounded-lg border bg-white px-3 py-2 text-[10px] font-bold">Batal</button><button disabled={isDeleting} onClick={() => void removePreview()} className="rounded-lg bg-rose-600 px-3 py-2 text-[10px] font-bold text-white disabled:opacity-50">Ya, hapus</button></div></div>}</section><section className="rounded-2xl border border-slate-200 bg-white p-5"><h3 className="text-sm font-bold text-slate-900">Ringkasan Segmentasi Keluhan</h3><div className="mt-3 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{detail.segment_summary.map((item) => <div key={item.complaint_segment} className="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3"><span className="text-xs font-semibold text-slate-700">{item.complaint_segment}</span><span className="text-sm font-bold text-indigo-700">{item.total}</span></div>)}</div></section>{error && <div className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error}</div>}<div className="overflow-hidden rounded-2xl border border-slate-200 bg-white"><div className="overflow-x-auto"><table className="w-full min-w-[1250px] text-left text-xs"><thead className="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th className="px-4 py-3">Baris</th><th className="px-4 py-3">Hasil</th><th className="px-4 py-3">Segmentasi Keluhan</th><th className="px-4 py-3">Open Time</th><th className="px-4 py-3">Close Time</th><th className="px-4 py-3">Last Update Time</th><th className="px-4 py-3">Duration (Hari:Jam:Menit)</th><th className="px-4 py-3">Response Time (Hari:Jam:Menit)</th><th className="px-4 py-3 text-right">Response Time (Menit)</th></tr></thead><tbody className="divide-y divide-slate-100">{detail.rows.items.map((row) => { const data = row.normalized_data; return <tr key={row.id}><td className="px-4 py-3 font-mono text-slate-500">{row.source_row_number}</td><td className="px-4 py-3"><span className="rounded-full bg-slate-100 px-2 py-1 text-[9px] font-bold">{row.outcome}</span>{row.validation_errors?.message && <p className="mt-2 text-[10px] text-rose-600">{row.validation_errors.message}</p>}{data?.validation_warnings?.map((warning) => <p key={warning} className="mt-1 max-w-60 text-[10px] text-amber-700">⚠ {warning}</p>)}</td><td className="px-4 py-3 font-semibold">{data?.complaint_segment ?? '—'}</td><td className="px-4 py-3">{data?.opened_at ?? '—'}</td><td className="px-4 py-3">{data?.closed_at ?? '—'}</td><td className="px-4 py-3">{data?.last_updated_at ?? '—'}</td><td className="px-4 py-3 font-mono">{formatElapsedMinutes(data?.duration_minutes)}</td><td className="px-4 py-3 font-mono">{formatElapsedMinutes(data?.response_time_minutes)}</td><td className="px-4 py-3 text-right">{data?.response_time_minutes?.toLocaleString('id-ID') ?? '—'}</td></tr>; })}</tbody></table></div><Pagination page={detail.rows.pagination.page} totalPages={detail.rows.pagination.total_pages} onChange={setPage}/></div></div>;
}

// Menampilkan riwayat batch import tiket dan membuka detail audit batch terpilih.
export function TicketImportHistory({ onResume }: { onResume: (detail: TicketImportBatchDetail) => void }) {
  const [page, setPage] = useState(1); const [refreshKey, setRefreshKey] = useState(0); const [selectedBatch, setSelectedBatch] = useState<number | null>(null);
  const [history, setHistory] = useState<HistoryData | null>(null); const [error, setError] = useState<string | null>(null); const [isLoading, setIsLoading] = useState(true);
  // Memicu refresh daftar setelah preview dihapus tanpa mengubah halaman aktif.
  const refresh = useCallback(() => setRefreshKey((value) => value + 1), []);
  // Memuat daftar batch tiket terbaru dan membatalkan request lama saat navigasi.
  useEffect(() => { const controller = new AbortController(); setIsLoading(true); setError(null); void fetchTicketImportHistory(page, controller.signal).then(setHistory).catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Riwayat tiket gagal dimuat.'); }).finally(() => { if (!controller.signal.aborted) setIsLoading(false); }); return () => controller.abort(); }, [page, refreshKey]);
  if (selectedBatch !== null) return <TicketBatchDetail batchId={selectedBatch} onBack={() => setSelectedBatch(null)} onResume={onResume} onDeleted={() => { setSelectedBatch(null); refresh(); }}/>;
  return <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><header className="flex items-center justify-between border-b border-slate-100 px-5 py-4"><div><h3 className="text-sm font-bold text-slate-900">Riwayat Import Tiket Aduan</h3><p className="mt-1 text-[10px] text-slate-400">Batch terbaru dan hasil pemrosesan tiket</p></div><button onClick={refresh} disabled={isLoading} className="rounded-lg border border-slate-200 p-2 text-slate-500"><RefreshCw className={`h-4 w-4 ${isLoading ? 'animate-spin' : ''}`}/></button></header>{error && <div className="m-4 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">{error}</div>}{isLoading && !history ? <div className="flex min-h-64 items-center justify-center text-xs font-semibold text-slate-500"><LoaderCircle className="mr-2 h-4 w-4 animate-spin"/>Memuat riwayat...</div> : <><div className="overflow-x-auto"><table className="w-full min-w-[900px] text-left text-xs"><thead className="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th className="px-4 py-3">Batch</th><th className="px-4 py-3">Merchant / File</th><th className="px-4 py-3">Periode</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Insert</th><th className="px-4 py-3 text-right">Update</th><th className="px-4 py-3 text-right">Duplikat</th><th className="px-4 py-3">Oleh</th><th className="px-4 py-3"></th></tr></thead><tbody className="divide-y divide-slate-100">{history?.items.map((batch) => <tr key={batch.id}><td className="px-4 py-3 font-mono font-bold">#{batch.id}</td><td className="px-4 py-3"><p className="font-bold">{batch.merchant_name ?? '—'}</p><p className="mt-1 max-w-56 truncate text-[10px] text-slate-400">{batch.original_filename}</p></td><td className="px-4 py-3 text-[10px]">{batch.detected_period_start ?? '—'}<br/>{batch.detected_period_end ?? '—'}</td><td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${statusStyles[batch.status] ?? 'bg-slate-100'}`}>{batch.status}</span></td><td className="px-4 py-3 text-right">{batch.inserted_rows}</td><td className="px-4 py-3 text-right">{batch.updated_rows}</td><td className="px-4 py-3 text-right">{batch.duplicate_rows}</td><td className="px-4 py-3 text-[10px]">{batch.imported_by ?? '—'}<br/><span className="text-slate-400">{formatDateTime(batch.completed_at ?? batch.created_at)}</span></td><td className="px-4 py-3"><button aria-label={`Lihat batch ${batch.id}`} onClick={() => setSelectedBatch(batch.id)} className="rounded-lg border border-slate-200 p-2 text-indigo-600"><Eye className="h-3.5 w-3.5"/></button></td></tr>)}</tbody></table></div>{history && <Pagination page={history.pagination.page} totalPages={history.pagination.total_pages} onChange={setPage}/>}</>}</section>;
}
