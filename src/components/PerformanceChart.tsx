import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, RefreshCw } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { fetchDashboardTrend } from '../services/dashboardApi';
import type { DashboardFiltersState, DashboardTrendData, DailyPerformance, MonthlyPerformance } from '../types';

type SeriesKey = 'inquiry' | 'payment';
const series = [
  { key: 'inquiry' as const, label: 'Inquiry Sukses', colorClass: 'bg-indigo-500' },
  { key: 'payment' as const, label: 'Payment Sukses', colorClass: 'bg-emerald-500' },
];
interface PerformanceChartProps { filters: DashboardFiltersState; drilldownPeriod: string | null; onMonthSelect: (period: string) => void; onBack: () => void; }

// Menampilkan tren tahun kalender dan memuat detail harian hanya untuk bulan yang memiliki data.
export function PerformanceChart({ filters, drilldownPeriod, onMonthSelect, onBack }: PerformanceChartProps) {
  const [trend, setTrend] = useState<DashboardTrendData | null>(null);
  const [visibleSeries, setVisibleSeries] = useState<Record<SeriesKey, boolean>>({ inquiry: true, payment: true });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const request = useRef<AbortController | null>(null);

  // Mengambil tren sesuai granularitas dan membatalkan request chart sebelumnya.
  const loadTrend = async (granularity: 'monthly' | 'daily', period: string) => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    setIsLoading(true);
    setError(null);
    try {
      setTrend(await fetchDashboardTrend(filters, granularity, period, controller.signal));
    } catch (reason) {
      if (reason instanceof DOMException && reason.name === 'AbortError') return;
      setError(reason instanceof Error ? reason.message : 'Data tren transaksi gagal dimuat.');
    } finally {
      if (!controller.signal.aborted) setIsLoading(false);
    }
  };

  // Memuat tren tahunan atau detail harian sesuai mode yang dikendalikan dashboard.
  useEffect(() => {
    if (filters.period) void loadTrend(drilldownPeriod ? 'daily' : 'monthly', drilldownPeriod ?? filters.period);
    return () => request.current?.abort();
  }, [filters, drilldownPeriod]);

  // Membalik visibilitas satu seri tanpa memengaruhi seri lainnya.
  const toggleSeries = (key: SeriesKey) => setVisibleSeries((current) => ({ ...current, [key]: !current[key] }));

  // Membuka detail harian untuk bulan yang dipilih pada chart bulanan.
  const openDaily = (period: string) => {
    if (trend?.granularity !== 'monthly' || !/^\d{4}-\d{2}$/.test(period)) return;
    const month = (trend.rows as MonthlyPerformance[]).find((item) => item.period === period);
    if (month?.has_data) onMonthSelect(period);
  };

  const isDaily = trend?.granularity === 'daily';
  const chartData = (trend?.rows ?? []).map((item: MonthlyPerformance | DailyPerformance) => ({
    key: 'period' in item ? item.period : item.transaction_date,
    inquiry: item.inquiry === null ? null : Number(item.inquiry),
    payment: item.payment === null ? null : Number(item.payment),
    hasData: 'has_data' in item ? item.has_data : true,
  }));
  // Membuat label sumbu waktu sesuai granularitas grafik aktif.
  const formatAxis = (value: string) => new Intl.DateTimeFormat('id-ID', isDaily ? { day: '2-digit' } : { month: 'short' }).format(new Date(`${value}${isDaily ? '' : '-01'}T00:00:00`));
  const periodLabel = trend ? new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${trend.selected_period}-01T00:00:00`)) : '';
  const trendYear = trend?.selected_period.slice(0, 4) ?? filters.period.slice(0, 4);

  return <section className='rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 lg:col-span-2'>
    <div className='mb-5 flex items-start justify-between gap-3'><div><h2 className='text-sm font-bold text-slate-900'>{isDaily ? `Tren Transaksi Harian - ${periodLabel}` : `Tren Transaksi Bulanan ${trendYear}`}</h2><p className='mt-1 text-xs text-slate-400'>{isDaily ? 'Seluruh dashboard mengikuti bulan yang dipilih' : 'Bulan tanpa data dibiarkan kosong dan tidak dapat dibuka'}</p></div>{isDaily && <button type='button' onClick={onBack} className='flex items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 py-2 text-[11px] font-bold text-slate-600 hover:bg-slate-50'><ArrowLeft className='h-3.5 w-3.5'/>Tahun {trendYear}</button>}</div>
    <div aria-label='Legend grafik' className='mb-4 flex gap-2 text-[11px] font-semibold'>{series.map((item) => <button key={item.key} type='button' aria-pressed={visibleSeries[item.key]} onClick={() => toggleSeries(item.key)} className={`flex items-center gap-2 rounded-lg border px-2.5 py-1.5 transition ${visibleSeries[item.key] ? 'border-slate-200 bg-white text-slate-600' : 'border-transparent bg-slate-100 text-slate-400 line-through'}`}><i className={`h-2 w-2 rounded-full ${item.colorClass} ${visibleSeries[item.key] ? 'opacity-100' : 'opacity-30'}`}/>{item.label}</button>)}</div>
    {error && <div role='alert' className='mb-3 rounded-lg bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700'>{error}</div>}
    <div className='relative h-72'>{isLoading && <div className='absolute inset-0 z-10 flex items-center justify-center rounded-xl bg-white/70 text-xs font-semibold text-slate-500'><RefreshCw className='mr-2 h-4 w-4 animate-spin'/>Memuat tren...</div>}<ResponsiveContainer width='100%' height='100%'><AreaChart data={chartData} margin={{ left: -20, right: 5 }} onClick={(state) => { if (state?.activeLabel) openDaily(String(state.activeLabel)); }}><defs><linearGradient id='inquiry' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stopColor='#6366f1' stopOpacity={0.25}/><stop offset='100%' stopColor='#6366f1' stopOpacity={0}/></linearGradient><linearGradient id='payment' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stopColor='#10b981' stopOpacity={0.2}/><stop offset='100%' stopColor='#10b981' stopOpacity={0}/></linearGradient></defs><CartesianGrid stroke='#e2e8f0' strokeDasharray='3 5' vertical={false}/><XAxis dataKey='key' axisLine={false} tickLine={false} tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={formatAxis}/><YAxis axisLine={false} tickLine={false} allowDecimals={false} domain={[0, 'auto']} tickCount={5} tick={{ fontSize: 10, fill: '#94a3b8' }} tickFormatter={(value) => Number(value).toLocaleString('id-ID')}/><Tooltip labelFormatter={(value) => formatAxis(String(value))} contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0', fontSize: 11 }}/><Area type='monotone' dataKey='inquiry' name='Inquiry Sukses' hide={!visibleSeries.inquiry} stroke='#6366f1' strokeWidth={2.5} fill='url(#inquiry)'/><Area type='monotone' dataKey='payment' name='Payment Sukses' hide={!visibleSeries.payment} stroke='#10b981' strokeWidth={2.5} fill='url(#payment)'/></AreaChart></ResponsiveContainer></div>
  </section>;
}
