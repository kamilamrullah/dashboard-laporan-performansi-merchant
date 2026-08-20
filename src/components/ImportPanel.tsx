import { useRef, useState } from 'react';
import { AlertCircle, CheckCircle2, FileSpreadsheet, History, Info, LoaderCircle, RotateCcw, ShieldCheck, UploadCloud, X } from 'lucide-react';
import { confirmTransactionImport, previewTransactionImport } from '../services/transactionImportApi';
import type { MerchantOption, TransactionImportOutcome, TransactionImportPreview, TransactionImportResult } from '../types';
import { TransactionImportHistory } from './TransactionImportHistory';

interface ImportPanelProps { type?: 'transactions' | 'tickets'; merchantOptions?: MerchantOption[]; onCompleted?: () => void; }

const MAX_FILE_BYTES = 20 * 1024 * 1024;

const outcomeLabels: Record<TransactionImportOutcome, string> = {
  READY: 'Siap', CHANGED: 'Berubah', DUPLICATE_IN_FILE: 'Duplikat file',
  DUPLICATE_DATABASE: 'Duplikat database', CONFLICT_IN_FILE: 'Konflik file', INVALID: 'Invalid',
};

const outcomeStyles: Record<TransactionImportOutcome, string> = {
  READY: 'bg-emerald-100 text-emerald-700', CHANGED: 'bg-amber-100 text-amber-800',
  DUPLICATE_IN_FILE: 'bg-slate-200 text-slate-700', DUPLICATE_DATABASE: 'bg-slate-200 text-slate-700',
  CONFLICT_IN_FILE: 'bg-orange-100 text-orange-800', INVALID: 'bg-rose-100 text-rose-700',
};

// Memformat angka transaksi untuk tabel preview tanpa mengubah nilai sumber.
function formatNumber(value: number | string | undefined): string {
  if (value === undefined) return '—';
  const [whole, fraction = ''] = String(value).split('.');
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  const visibleFraction = fraction.slice(0, 2).replace(/0+$/, '');
  return visibleFraction ? `${grouped},${visibleFraction}` : grouped;
}

// Menampilkan nilai baru dan lama ketika field berubah pada database.
function ChangedValue({ current, previous, changed }: { current: number | string | undefined; previous: number | string | undefined; changed: boolean }) {
  if (!changed) return <>{formatNumber(current)}</>;
  return <span><span className="block text-[10px] text-slate-400 line-through">{formatNumber(previous)}</span><span className="font-bold text-amber-700">{formatNumber(current)}</span></span>;
}

// Menyediakan alur upload, preview, dan konfirmasi import transaksi dari antarmuka aplikasi.
export function ImportPanel({ type = 'transactions', merchantOptions = [], onCompleted }: ImportPanelProps) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [merchantInput, setMerchantInput] = useState('');
  const [preview, setPreview] = useState<TransactionImportPreview | null>(null);
  const [result, setResult] = useState<TransactionImportResult | null>(null);
  const [changedAction, setChangedAction] = useState<'skip' | 'update'>('skip');
  const [error, setError] = useState<string | null>(null);
  const [isPreviewing, setIsPreviewing] = useState(false);
  const [isConfirming, setIsConfirming] = useState(false);
  const [activeView, setActiveView] = useState<'upload' | 'history'>('upload');
  const selectedMerchant = merchantOptions.find((merchant) => merchant.merchant_name.trim().toLocaleLowerCase('id-ID') === merchantInput.trim().toLocaleLowerCase('id-ID')) ?? null;
  const isNewMerchant = merchantInput.trim() !== '' && selectedMerchant === null;

  // Mengirim workbook ke backend dan menyimpan hasil preview untuk ditinjau pengguna.
  const createPreview = async (selectedFile: File) => {
    if (!merchantInput.trim()) { setError('Pilih merchant terlebih dahulu.'); return; }
    setIsPreviewing(true); setError(null);
    try { setPreview(await previewTransactionImport(selectedFile, selectedMerchant?.id ?? null, isNewMerchant ? merchantInput.trim() : null)); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Preview transaksi gagal diproses.'); }
    finally { setIsPreviewing(false); }
  };

  // Memvalidasi pilihan file lalu otomatis meminta preview tanpa klik konfirmasi tambahan.
  const selectFile = (selected: File | undefined) => {
    setError(null); setPreview(null); setResult(null);
    if (!selected) { setFile(null); return; }
    if (!merchantInput.trim()) { setFile(null); setError('Pilih merchant sebelum memilih file transaksi.'); return; }
    if (!selected.name.toLowerCase().endsWith('.xlsx')) { setFile(null); setError('File transaksi harus berformat XLSX.'); return; }
    if (selected.size <= 0 || selected.size > MAX_FILE_BYTES) { setFile(null); setError('Ukuran file harus lebih dari 0 dan maksimal 20 MB.'); return; }
    setFile(selected);
    void createPreview(selected);
  };

  // Mengonfirmasi seluruh baris siap dan menerapkan pilihan pengguna untuk baris yang berubah.
  const confirmImport = async () => {
    if (!preview) return;
    setIsConfirming(true); setError(null);
    try { const completed = await confirmTransactionImport(preview, changedAction, 'Kamil'); setResult(completed); setPreview(null); onCompleted?.(); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Konfirmasi import gagal diproses.'); }
    finally { setIsConfirming(false); }
  };

  // Mengosongkan state agar pengguna dapat memulai upload baru.
  const reset = () => {
    setFile(null); setPreview(null); setResult(null); setError(null); setChangedAction('skip');
    if (inputRef.current) inputRef.current.value = '';
  };

  if (type === 'tickets') return <div className="flex gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-5"><Info className="h-5 w-5 shrink-0 text-amber-600"/><div><h3 className="text-sm font-bold text-amber-900">Import tiket belum tersedia</h3><p className="mt-1 text-xs leading-5 text-amber-800">Tahap ini khusus implementasi data transaksi. Import tiket akan menggunakan validasi dan staging terpisah.</p></div></div>;

  const viewTabs = <nav className="flex w-fit rounded-xl border border-slate-200 bg-white p-1 shadow-sm"><button onClick={() => setActiveView('upload')} className={`flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-bold ${activeView === 'upload' ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'}`}><UploadCloud className="h-3.5 w-3.5"/>Upload Baru</button><button onClick={() => setActiveView('history')} className={`flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-bold ${activeView === 'history' ? 'bg-indigo-600 text-white' : 'text-slate-500 hover:bg-slate-50'}`}><History className="h-3.5 w-3.5"/>Riwayat Import</button></nav>;

  if (activeView === 'history') return <div className="space-y-5">{viewTabs}<TransactionImportHistory/></div>;

  if (result) return <div className="space-y-5">{viewTabs}<div className="mx-auto max-w-2xl rounded-2xl border border-emerald-200 bg-white p-7 text-center shadow-sm"><span className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><CheckCircle2 className="h-7 w-7"/></span><h3 className="mt-4 text-lg font-bold text-slate-900">Import transaksi selesai</h3><p className="mt-1 text-xs text-slate-500">Batch #{result.batch_id} berhasil diproses dan dashboard sedang dimuat ulang.</p><div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">{[['Ditambahkan', result.inserted], ['Diperbarui', result.updated], ['Duplikat', result.duplicate], ['Ditolak', result.rejected]].map(([label, value]) => <div key={label} className="rounded-xl bg-slate-50 p-4"><p className="text-xl font-bold text-slate-900">{value}</p><p className="mt-1 text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</p></div>)}</div><div className="mt-6 flex flex-wrap justify-center gap-3"><button onClick={reset} className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-3 text-xs font-bold text-white hover:bg-indigo-700"><RotateCcw className="h-4 w-4"/>Import file lain</button><button onClick={() => setActiveView('history')} className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-5 py-3 text-xs font-bold text-slate-600"><History className="h-4 w-4"/>Lihat riwayat</button></div></div></div>;

  return <div className="space-y-5">{viewTabs}
    {error && <div role="alert" className="flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-700"><AlertCircle className="h-4 w-4 shrink-0"/><span className="flex-1">{error}</span><button aria-label="Tutup pesan" onClick={() => setError(null)}><X className="h-4 w-4"/></button></div>}
    {!preview && <div className="grid gap-6 xl:grid-cols-[1.25fr_.75fr]">
      <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div className="flex items-center gap-3"><span className="rounded-xl bg-indigo-50 p-3 text-indigo-600"><UploadCloud className="h-5 w-5"/></span><div><h2 className="text-base font-bold text-slate-900">Upload Data Transaksi</h2><p className="text-xs text-slate-400">Workbook akan divalidasi sebelum data disimpan</p></div></div>
        <label className="mt-5 block text-xs font-bold text-slate-700">Nama merchant<input list="merchant-import-options" value={merchantInput} onChange={(event) => { setMerchantInput(event.target.value); setPreview(null); setResult(null); }} maxLength={160} placeholder="Cari atau ketik nama merchant baru" autoComplete="off" className="mt-2 w-full rounded-xl border border-slate-200 px-3 py-2.5 text-xs font-medium outline-none focus:border-indigo-400"/><datalist id="merchant-import-options">{merchantOptions.map((merchant) => <option key={merchant.id} value={merchant.merchant_name}/>)}</datalist></label>
        {merchantInput.trim() && <div className={`mt-2 flex items-center gap-2 rounded-lg px-3 py-2 text-[10px] font-semibold ${isNewMerchant ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700'}`}>{isNewMerchant ? <><Info className="h-3.5 w-3.5"/>Merchant baru akan ditambahkan saat preview dibuat. Kode internal dibuat otomatis.</> : <><CheckCircle2 className="h-3.5 w-3.5"/>Merchant existing dipilih dari database.</>}</div>}
        <input ref={inputRef} type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" className="hidden" onChange={(event) => selectFile(event.target.files?.[0])}/>
        <button type="button" disabled={!merchantInput.trim() || isPreviewing} onClick={() => inputRef.current?.click()} className="mt-5 flex min-h-48 w-full flex-col items-center justify-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/70 px-5 transition hover:border-indigo-300 hover:bg-indigo-50/40 disabled:cursor-not-allowed disabled:opacity-50"><span className="rounded-full bg-white p-4 text-indigo-600 shadow-sm">{isPreviewing ? <LoaderCircle className="h-7 w-7 animate-spin"/> : <FileSpreadsheet className="h-7 w-7"/>}</span><p className="mt-4 text-sm font-bold text-slate-800">{isPreviewing ? 'Memvalidasi dan menyiapkan preview...' : merchantInput.trim() ? 'Klik untuk memilih file Excel' : 'Pilih merchant terlebih dahulu'}</p><p className="mt-1 text-xs text-slate-400">{isPreviewing ? 'Mohon tunggu, file sedang diproses' : 'XLSX, maksimal 20 MB · preview tampil otomatis'}</p></button>
        {file && <div className="mt-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3"><CheckCircle2 className="h-5 w-5 shrink-0 text-emerald-600"/><div className="min-w-0 flex-1"><p className="truncate text-xs font-bold text-emerald-800">{file.name}</p><p className="text-[10px] text-emerald-600">{(file.size / 1024 / 1024).toLocaleString('id-ID', { maximumFractionDigits: 2 })} MB · siap divalidasi</p></div><button aria-label="Hapus file" onClick={() => selectFile(undefined)} className="text-emerald-600"><X className="h-4 w-4"/></button></div>}
      </section>
      <aside className="space-y-5"><div className="rounded-2xl bg-slate-950 p-6 text-white"><ShieldCheck className="h-7 w-7 text-emerald-400"/><h3 className="mt-4 text-sm font-bold">Import Aman & Teraudit</h3><p className="mt-2 text-xs leading-5 text-slate-400">File tidak langsung masuk ke data aktif. Hash, nomor baris, hasil validasi, dan setiap perubahan dicatat.</p><div className="mt-5 space-y-3 text-xs">{['Validasi format dan isi workbook', 'Deteksi duplikat file dan database', 'Tinjau perubahan sebelum konfirmasi', 'Simpan atomik dengan riwayat perubahan'].map((text, index) => <div key={text} className="flex items-center gap-3"><span className="flex h-6 w-6 items-center justify-center rounded-full bg-white/10 text-[10px] font-bold text-indigo-300">{index + 1}</span><span className="text-slate-300">{text}</span></div>)}</div></div><div className="flex gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4"><Info className="h-5 w-5 shrink-0 text-sky-600"/><p className="text-xs leading-5 text-sky-800">File hanya dipakai selama request preview. Setelah dinormalisasi, file fisik tidak disimpan permanen.</p></div></aside>
    </div>}
    {preview && <section className="space-y-5">
      <div className="flex flex-col justify-between gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:flex-row sm:items-center"><div><div className="flex items-center gap-2"><CheckCircle2 className="h-5 w-5 text-emerald-600"/><h3 className="text-sm font-bold text-slate-900">Preview siap ditinjau</h3></div><p className="mt-1 text-xs text-slate-500">Batch #{preview.batch_id} · periode {preview.period_start ?? '—'} sampai {preview.period_end ?? '—'}</p></div><button onClick={reset} className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50"><RotateCcw className="h-4 w-4"/>Ganti file</button></div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-7">{[['Total', preview.summary.total], ['Siap', preview.summary.ready], ['Berubah', preview.summary.changed], ['Duplikat file', preview.summary.duplicate_in_file], ['Duplikat DB', preview.summary.duplicate_database], ['Konflik', preview.summary.conflict_in_file], ['Invalid', preview.summary.invalid]].map(([label, value]) => <div key={label} className="rounded-xl border border-slate-200 bg-white p-3"><p className="text-lg font-bold text-slate-900">{value}</p><p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">{label}</p></div>)}</div>
      {preview.rows_truncated && <div className="flex gap-2 rounded-xl border border-sky-200 bg-sky-50 p-3 text-xs text-sky-800"><Info className="h-4 w-4 shrink-0"/>Menampilkan {preview.visible_rows.toLocaleString('id-ID')} baris prioritas dari {preview.summary.total.toLocaleString('id-ID')} baris. Seluruh baris tetap diproses berdasarkan status preview.</div>}
      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"><div className="overflow-x-auto scrollbar-subtle"><table className="min-w-[1250px] w-full text-left text-xs"><thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500"><tr><th className="px-4 py-3">Baris</th><th className="px-4 py-3">Status</th><th className="px-4 py-3">Tanggal</th><th className="px-4 py-3">Tipe</th><th className="px-4 py-3">Partner Channel</th><th className="px-4 py-3">Payment Channel</th><th className="px-4 py-3">CA / Biller / SIC</th><th className="px-4 py-3">RC</th><th className="px-4 py-3 text-right">Total trx</th><th className="px-4 py-3 text-right">Nominal</th></tr></thead><tbody className="divide-y divide-slate-100">{preview.rows.map((row) => <tr key={row.id} className={row.outcome === 'CHANGED' ? 'bg-amber-50/60' : row.outcome === 'INVALID' ? 'bg-rose-50/50' : ''}><td className="px-4 py-3 font-mono text-slate-500">{row.source_row_number}</td><td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-[9px] font-bold uppercase ${outcomeStyles[row.outcome]}`}>{outcomeLabels[row.outcome]}</span>{row.errors && <p className="mt-2 max-w-52 text-[10px] leading-4 text-rose-600">{row.errors.message}</p>}</td><td className="px-4 py-3">{row.data?.transaction_date ?? '—'}</td><td className="px-4 py-3 font-semibold">{row.data?.transaction_type ?? '—'}</td><td className="px-4 py-3">{row.data?.partner_channel ?? '—'}</td><td className="px-4 py-3">{row.payment_channel ?? <span className="text-[10px] font-semibold text-amber-600">Belum dimapping</span>}</td><td className="px-4 py-3 font-mono text-[10px]">{row.data ? `${row.data.ca_id} / ${row.data.biller} / ${row.data.sic_code}` : '—'}</td><td className="px-4 py-3 font-mono">{row.data?.response_code ?? '—'}</td><td className="px-4 py-3 text-right"><ChangedValue current={row.data?.total_trx} previous={row.existing?.total_trx} changed={row.changed_fields.includes('total_trx')}/></td><td className="px-4 py-3 text-right"><ChangedValue current={row.data?.total_amount} previous={row.existing?.total_amount} changed={row.changed_fields.includes('total_amount')}/></td></tr>)}</tbody></table></div></div>
      {preview.summary.changed > 0 && <fieldset className="rounded-2xl border border-amber-200 bg-amber-50 p-5"><legend className="px-2 text-xs font-bold text-amber-900">Tindakan untuk {preview.summary.changed} baris berubah</legend><div className="mt-2 grid gap-3 sm:grid-cols-2"><label className={`flex cursor-pointer gap-3 rounded-xl border bg-white p-4 ${changedAction === 'skip' ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-slate-200'}`}><input type="radio" name="changed-action" checked={changedAction === 'skip'} onChange={() => setChangedAction('skip')} className="mt-0.5"/><span><span className="block text-xs font-bold text-slate-800">Pertahankan data lama</span><span className="mt-1 block text-[10px] leading-4 text-slate-500">Baris berubah dilewati dan tidak mengubah dashboard.</span></span></label><label className={`flex cursor-pointer gap-3 rounded-xl border bg-white p-4 ${changedAction === 'update' ? 'border-indigo-400 ring-2 ring-indigo-100' : 'border-slate-200'}`}><input type="radio" name="changed-action" checked={changedAction === 'update'} onChange={() => setChangedAction('update')} className="mt-0.5"/><span><span className="block text-xs font-bold text-slate-800">Gunakan data baru</span><span className="mt-1 block text-[10px] leading-4 text-slate-500">Nilai lama dicatat dalam riwayat sebelum diperbarui.</span></span></label></div></fieldset>}
      <div className="flex flex-col-reverse justify-end gap-3 sm:flex-row"><button onClick={reset} disabled={isConfirming} className="rounded-xl border border-slate-200 bg-white px-5 py-3 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50">Batalkan</button><button onClick={() => void confirmImport()} disabled={isConfirming || preview.summary.ready + preview.summary.changed === 0} className="flex items-center justify-center gap-2 rounded-xl bg-indigo-600 px-6 py-3 text-xs font-bold text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50">{isConfirming ? <LoaderCircle className="h-4 w-4 animate-spin"/> : <CheckCircle2 className="h-4 w-4"/>}{isConfirming ? 'Mengimpor data...' : 'Konfirmasi import'}</button></div>
    </section>}
  </div>;
}
