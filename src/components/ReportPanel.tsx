import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, CalendarDays, Check, Download, FileText, LoaderCircle, PenLine, Settings2, Sparkles } from 'lucide-react';
import { downloadGeneratedReport, fetchReportOptions, generateReport } from '../services/reportApi';
import type { GeneratedReport, ReportMerchantOption } from '../services/reportApi';

// Mengubah tanggal awal bulan dari API menjadi label periode berbahasa Indonesia.
function reportPeriodLabel(period: string): string {
  return new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${period}T00:00:00Z`));
}

// Menampilkan konfigurasi generator, menjalankan pembuatan DOCX, dan menyediakan hasil unduhan.
export function ReportPanel() {
  const [merchants, setMerchants] = useState<ReportMerchantOption[]>([]);
  const [merchantId, setMerchantId] = useState('');
  const [period, setPeriod] = useState('');
  const [result, setResult] = useState<GeneratedReport | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isGenerating, setIsGenerating] = useState(false);
  const [isDownloading, setIsDownloading] = useState(false);
  const selectedMerchant = useMemo(() => merchants.find((merchant) => String(merchant.id) === merchantId) ?? null, [merchantId, merchants]);

  // Memuat merchant beserta periode yang benar-benar memiliki data transaksi laporan.
  useEffect(() => {
    const controller = new AbortController();
    void fetchReportOptions(controller.signal).then((options) => {
      setMerchants(options.merchants);
      const first = options.merchants[0];
      if (first) { setMerchantId(String(first.id)); setPeriod(first.periods[0] ?? ''); }
    }).catch((reason) => {
      if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Pilihan laporan gagal dimuat.');
    }).finally(() => setIsLoading(false));
    return () => controller.abort();
  }, []);

  // Mengganti pilihan periode ke periode terbaru ketika merchant berubah.
  const selectMerchant = (value: string) => {
    const merchant = merchants.find((item) => String(item.id) === value);
    setMerchantId(value); setPeriod(merchant?.periods[0] ?? ''); setResult(null); setError(null);
  };

  // Menjalankan generator backend berdasarkan pilihan pengguna.
  const createReport = async () => {
    if (!selectedMerchant || !period) { setError('Pilih merchant dan periode laporan.'); return; }
    setIsGenerating(true); setError(null); setResult(null);
    try { setResult(await generateReport(selectedMerchant.id, period)); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Laporan Word gagal dibuat.'); }
    finally { setIsGenerating(false); }
  };

  // Mengunduh hasil laporan terbaru tanpa membuka alamat file secara langsung.
  const downloadReport = async () => {
    if (!result) return;
    setIsDownloading(true); setError(null);
    try { await downloadGeneratedReport(result.download_url, result.filename); }
    catch (reason) { setError(reason instanceof Error ? reason.message : 'Laporan gagal diunduh.'); }
    finally { setIsDownloading(false); }
  };

  const sections = ['Ringkasan Bulanan', 'Performansi Transaksi', 'Payment Channel', 'Tren Transaksi Harian', 'Laporan Insiden', 'Kesimpulan'];
  return <div className="grid gap-6 xl:grid-cols-[.8fr_1.2fr]">
    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <div className="flex items-center gap-3"><span className="rounded-xl bg-indigo-50 p-3 text-indigo-600"><Settings2 className="h-5 w-5"/></span><div><h2 className="text-base font-bold text-slate-900">Konfigurasi Laporan</h2><p className="text-xs text-slate-400">Pilih merchant dan periode data laporan</p></div></div>
      <div className="mt-6 space-y-5">
        <label className="block"><span className="mb-2 block text-xs font-bold text-slate-700">Nama Merchant</span><select value={merchantId} onChange={(event) => selectMerchant(event.target.value)} disabled={isLoading || isGenerating} className="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-4 focus:ring-indigo-50"><option value="">{isLoading ? 'Memuat merchant...' : 'Pilih merchant'}</option>{merchants.map((merchant) => <option key={merchant.id} value={merchant.id}>{merchant.merchant_name}</option>)}</select></label>
        <label className="block"><span className="mb-2 block text-xs font-bold text-slate-700">Periode Laporan</span><span className="flex items-center gap-2 rounded-xl border border-slate-200 px-4"><CalendarDays className="h-4 w-4 text-indigo-500"/><select value={period} onChange={(event) => { setPeriod(event.target.value); setResult(null); setError(null); }} disabled={!selectedMerchant || isGenerating} className="w-full bg-transparent py-3 text-sm outline-none"><option value="">Pilih periode</option>{selectedMerchant?.periods.map((item) => <option key={item} value={item}>{reportPeriodLabel(item)}</option>)}</select></span></label>
        {error && <div role="alert" className="flex gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700"><AlertCircle className="h-4 w-4 shrink-0"/>{error}</div>}
        {result && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4"><p className="flex items-center gap-2 text-xs font-bold text-emerald-800"><Check className="h-4 w-4"/>Dokumen berhasil dibuat</p><p className="mt-1 break-all text-[10px] text-emerald-700">{result.filename}</p><button type="button" onClick={() => void downloadReport()} disabled={isDownloading} className="mt-3 flex w-full items-center justify-center gap-2 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-xs font-bold text-emerald-700 disabled:opacity-60">{isDownloading ? <LoaderCircle className="h-4 w-4 animate-spin"/> : <Download className="h-4 w-4"/>}{isDownloading ? 'Mengunduh...' : 'Unduh DOCX'}</button></div>}
        <button type="button" onClick={() => void createReport()} disabled={isLoading || isGenerating || !selectedMerchant || !period} className="flex w-full items-center justify-center gap-2 rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-lg shadow-indigo-200 disabled:cursor-not-allowed disabled:opacity-50">{isGenerating ? <LoaderCircle className="h-4 w-4 animate-spin"/> : <Sparkles className="h-4 w-4"/>}{isGenerating ? 'Membuat Dokumen...' : 'Generate Dokumen DOCX'}</button>
        {!isLoading && merchants.length === 0 && <p className="text-center text-[10px] text-slate-400">Belum ada merchant dengan data transaksi yang dapat dilaporkan.</p>}
      </div>
    </section>
    <section className="rounded-2xl border border-slate-200 bg-slate-100 p-4 shadow-sm sm:p-7"><div className="mx-auto min-h-[620px] max-w-2xl bg-white p-8 shadow-lg sm:p-12"><div className="flex items-start justify-between border-b-2 border-indigo-600 pb-6"><div><p className="text-[10px] font-bold uppercase tracking-[.2em] text-indigo-600">Laporan Bulanan</p><h2 className="mt-2 text-xl font-bold text-slate-900">Performansi Merchant</h2><p className="mt-1 text-xs text-slate-400">{selectedMerchant?.merchant_name ?? 'Pilih merchant'} · {period ? reportPeriodLabel(period) : 'Pilih periode'}</p></div><span className="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-950 text-white"><FileText className="h-6 w-6"/></span></div><div className="mt-8 space-y-6">{sections.map((title, index) => <div key={title} className="flex gap-4"><span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-[10px] font-bold text-indigo-600">{index + 1}</span><div className="flex-1"><h3 className="text-xs font-bold text-slate-800">{title}</h3><div className="mt-2 space-y-1.5"><div className="h-1.5 w-full rounded bg-slate-100"/><div className="h-1.5 w-5/6 rounded bg-slate-100"/><div className="h-1.5 w-3/5 rounded bg-slate-100"/></div></div></div>)}</div><div className="mt-9 flex gap-3 rounded-xl border border-dashed border-amber-300 bg-amber-50 p-4"><PenLine className="h-4 w-4 shrink-0 text-amber-600"/><div><p className="text-[10px] font-bold text-amber-800">Bagian yang dapat dilengkapi manual</p><p className="mt-1 text-[9px] leading-4 text-amber-700">Dokumen keluaran tetap dapat diedit untuk melengkapi konteks bisnis dan detail insiden.</p></div></div><div className="mt-6 flex items-center gap-2 text-[9px] font-semibold text-emerald-600"><Check className="h-3.5 w-3.5"/>Angka, tabel, grafik, dan narasi dibuat dari periode terpilih</div></div></section>
  </div>;
}
