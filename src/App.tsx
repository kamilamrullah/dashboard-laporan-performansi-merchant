import { useCallback, useEffect, useRef, useState } from 'react';
import { Activity, CalendarDays, CheckCircle2, FileText, Gauge, RefreshCw, SlidersHorizontal } from 'lucide-react';
import type { LucideIcon, LucideProps } from 'lucide-react';
import { AppHeader } from './components/AppHeader';
import { ChannelTable } from './components/ChannelTable';
import { DashboardFilters } from './components/DashboardFilters';
import { ImportPanel } from './components/ImportPanel';
import { MetricCard } from './components/MetricCard';
import { Modal } from './components/Modal';
import { PerformanceChart } from './components/PerformanceChart';
import { ReportPanel } from './components/ReportPanel';
import { TransactionStatusChart } from './components/TransactionStatusChart';
import { fetchDashboard } from './services/dashboardApi';
import type { DashboardData, DashboardFiltersState } from './types';

type ActiveModal = 'transactions' | 'tickets' | 'report' | null;
const initialFilters: DashboardFiltersState = { dateFrom: '', dateTo: '', merchantId: '', partnerChannel: '', paymentChannel: '', transactionType: '', responseCode: '' };

// Menyediakan penanda Rupiah karena Lucide tidak memiliki simbol mata uang Indonesia.
const RupiahIcon = ((props: LucideProps) => <span className={`${props.className ?? ''} flex items-center justify-center text-[11px] font-extrabold`}>Rp</span>) as LucideIcon;

// Mengubah angka menjadi format Indonesia.
function formatNumber(value: string): string { return Number(value).toLocaleString('id-ID'); }

// Mengubah nominal database menjadi mata uang Rupiah utuh.
function formatCurrency(value: string): string {
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(Number(value));
}

// Menentukan rentang bulan terbaru yang benar-benar tersedia di database.
function latestAvailableMonth(dateFrom: string | null, dateTo: string | null): Pick<DashboardFiltersState, 'dateFrom' | 'dateTo'> | null {
  if (!dateFrom || !dateTo) return null;
  const monthStart = `${dateTo.slice(0, 7)}-01`;
  return { dateFrom: monthStart < dateFrom ? dateFrom : monthStart, dateTo };
}

// Memformat rentang periode API menjadi label Indonesia.
function formatPeriod(start: string | null, end: string | null): string {
  if (!start || !end) return 'Tidak ada periode';
  const formatter = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' });
  return `${formatter.format(new Date(`${start}T00:00:00`))} – ${formatter.format(new Date(`${end}T00:00:00`))}`;
}

interface DashboardViewProps { data: DashboardData; filters: DashboardFiltersState; isRefreshing: boolean; onFilterChange: (filters: DashboardFiltersState) => void; onReset: () => void; onRefresh: () => void; }

// Merender seluruh metrik laporan dan visual dari response database yang sama.
function DashboardView({ data, filters, isRefreshing, onFilterChange, onReset, onRefresh }: DashboardViewProps) {
  const inquiry = Number(data.summary.total_inquiry);
  const payment = Number(data.summary.total_payment);
  const ratio = inquiry > 0 ? (payment / inquiry) * 100 : 0;
  return <>
    <div className="mb-6 flex flex-col justify-between gap-3 rounded-2xl border border-indigo-100 bg-gradient-to-r from-indigo-50 via-white to-sky-50 p-5 sm:flex-row sm:items-center">
      <div><div className="flex flex-wrap items-center gap-2"><span className="rounded-md bg-emerald-100 px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-emerald-700">Database aktif</span><span className="text-[10px] text-slate-400">{data.summary.aggregate_rows} baris agregat</span></div><h2 className="mt-3 text-lg font-bold text-slate-900">Ikhtisar {formatPeriod(data.summary.period_start, data.summary.period_end)}</h2><p className="mt-1 text-xs text-slate-500">Metrik laporan menggunakan transaksi yang diklasifikasikan sukses.</p></div>
      <button onClick={onRefresh} disabled={isRefreshing} className="flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50 disabled:opacity-50"><RefreshCw className={`h-3.5 w-3.5 ${isRefreshing ? 'animate-spin' : ''}`} />Muat ulang</button>
    </div>
    <DashboardFilters filters={filters} options={data.options} onChange={onFilterChange} onReset={onReset} />
    <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <MetricCard label="Inquiry Sukses" value={formatNumber(data.summary.total_inquiry)} note="TYPE INQUIRY dengan RC sukses" icon={Activity} tone="indigo" />
      <MetricCard label="Payment Sukses" value={formatNumber(data.summary.total_payment)} note="TYPE PAYMENT dengan RC sukses" icon={CheckCircle2} tone="emerald" delay="60ms" />
      <MetricCard label="Nominal Payment Sukses" value={formatCurrency(data.summary.payment_amount)} note="nominal PAYMENT dengan RC sukses" icon={RupiahIcon} tone="sky" delay="120ms" />
      <MetricCard label="Payment / Inquiry" value={`${ratio.toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`} note="rasio transaksi sukses" icon={Gauge} tone="amber" delay="180ms" />
    </div>
    <div className="mb-6 grid gap-6 lg:grid-cols-3"><PerformanceChart data={data.daily} /><TransactionStatusChart data={data.response_codes} /></div>
    <ChannelTable data={data.partners} />
    <section className="mt-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 className="text-sm font-bold text-slate-900">Payment Channel Terfilter</h2><div className="mt-4 flex flex-wrap gap-3">{data.payment_channels.map((item) => <div key={item.sic_code} className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3"><p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">SIC {item.sic_code}</p><p className="mt-1 text-xs font-bold text-slate-800">{item.name}</p><p className="mt-2 text-[11px] text-slate-500">{formatNumber(item.total_trx)} transaksi sukses</p></div>)}{data.payment_channels.length === 0 && <p className="text-xs text-slate-400">Tidak ada data.</p>}</div></section>
  </>;
}

// Mengelola pengambilan data dashboard, filter global, dan modal pekerjaan pengguna.
export default function App() {
  const [activeModal, setActiveModal] = useState<ActiveModal>(null);
  const [filters, setFilters] = useState<DashboardFiltersState>(initialFilters);
  const [data, setData] = useState<DashboardData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);
  const hasInitializedPeriod = useRef(false);

  // Memuat ulang dashboard ketika filter berubah dan membatalkan request lama.
  useEffect(() => {
    const controller = new AbortController();
    setIsLoading(true);
    setError(null);
    void fetchDashboard(filters, controller.signal).then((result) => {
      setData(result);
      if (!hasInitializedPeriod.current) {
        hasInitializedPeriod.current = true;
        const latestMonth = latestAvailableMonth(result.options.available_period.date_from, result.options.available_period.date_to);
        if (latestMonth) setFilters((current) => ({ ...current, ...latestMonth }));
      }
    }).catch((reason: unknown) => {
      if (reason instanceof DOMException && reason.name === 'AbortError') return;
      setError(reason instanceof Error ? reason.message : 'Data dashboard gagal dimuat.');
    }).finally(() => { if (!controller.signal.aborted) setIsLoading(false); });
    return () => controller.abort();
  }, [filters, refreshKey]);

  // Mengembalikan filter ke bulan data terbaru.
  const resetFilters = useCallback(() => {
    const latestMonth = data ? latestAvailableMonth(data.options.available_period.date_from, data.options.available_period.date_to) : null;
    setFilters({ ...initialFilters, ...(latestMonth ?? {}) });
  }, [data]);

  return <div className="min-h-screen bg-slate-50 text-slate-800"><AppHeader onOpenImport={setActiveModal} onOpenReport={() => setActiveModal('report')} /><main className="mx-auto max-w-[1800px] px-4 py-6 sm:px-6 lg:px-8">{error && <div role="alert" className="mb-5 flex items-center justify-between rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs font-semibold text-rose-700"><span>{error}</span><button onClick={() => setRefreshKey((value) => value + 1)} className="font-bold underline">Coba lagi</button></div>}{!data && isLoading && <div className="flex min-h-[60vh] items-center justify-center text-sm font-semibold text-slate-500"><RefreshCw className="mr-2 h-4 w-4 animate-spin" />Memuat data database...</div>}{data && <DashboardView data={data} filters={filters} isRefreshing={isLoading} onFilterChange={setFilters} onReset={resetFilters} onRefresh={() => setRefreshKey((value) => value + 1)} />}</main><footer className="flex flex-col gap-2 border-t border-slate-200 bg-white px-6 py-4 text-[10px] text-slate-400 sm:flex-row sm:items-center sm:justify-between lg:px-8"><span>Merchant Performance Center · Internal Use Only</span><span className="flex flex-wrap items-center gap-3"><span className="flex items-center gap-1"><CalendarDays className="h-3 w-3" />Data dari MariaDB</span><span className="flex items-center gap-1"><SlidersHorizontal className="h-3 w-3" />Filter global aktif</span><span className="flex items-center gap-1"><FileText className="h-3 w-3" />DOCX Ready</span></span></footer><Modal isOpen={activeModal === 'transactions' || activeModal === 'tickets'} title={activeModal === 'tickets' ? 'Import Tiket Aduan' : 'Import Data Transaksi'} description={activeModal === 'tickets' ? 'Upload dan validasi workbook tiket aduan.' : 'Upload dan validasi workbook transaksi agregat.'} onClose={() => setActiveModal(null)}><ImportPanel /></Modal><Modal isOpen={activeModal === 'report'} title="Generate Laporan Performansi" description="Atur periode dan konten sebelum membuat dokumen Microsoft Word." size="full" onClose={() => setActiveModal(null)}><ReportPanel /></Modal></div>;
}
