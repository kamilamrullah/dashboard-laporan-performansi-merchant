import { RotateCcw, SlidersHorizontal } from 'lucide-react';
import type { DashboardFiltersState, DashboardOptions } from '../types';

interface DashboardFiltersProps { filters: DashboardFiltersState; options: DashboardOptions; onChange: (filters: DashboardFiltersState) => void; onReset: () => void; }

// Menampilkan filter global bulanan yang memengaruhi seluruh metrik, chart, dan tabel dashboard.
export function DashboardFilters({ filters, options, onChange, onReset }: DashboardFiltersProps) {
  // Memperbarui satu filter tanpa menghapus pilihan filter lainnya.
  const updateFilter = (key: keyof DashboardFiltersState, value: string) => onChange({ ...filters, [key]: value });
  const selectClass = 'min-w-0 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs font-medium text-slate-800 outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20';
  // Mengubah nilai YYYY-MM menjadi nama bulan dan tahun Indonesia.
  const formatMonth = (period: string) => new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date(`${period}-01T00:00:00`));
  return <section className='mb-6 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm'>
    <div className='mb-3 flex items-center justify-between'><div className='flex items-center gap-2 text-xs font-bold text-slate-700'><SlidersHorizontal className='h-4 w-4 text-indigo-600'/>Filter Dashboard</div><button onClick={onReset} className='flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-[11px] font-bold text-slate-500 hover:bg-slate-100'><RotateCcw className='h-3.5 w-3.5'/>Reset</button></div>
    <div className='grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6'>
      <select aria-label='Periode laporan' value={filters.period} onChange={(event) => updateFilter('period', event.target.value)} className={selectClass}>{options.periods.map((item) => <option key={item.value} value={item.value}>{formatMonth(item.value)}</option>)}</select>
      <select aria-label='Merchant' value={filters.merchantId} onChange={(event) => updateFilter('merchantId', event.target.value)} className={selectClass}><option value=''>Semua Merchant</option>{options.merchants.map((item) => <option key={item.id} value={item.id}>{item.merchant_name}</option>)}</select>
      <select aria-label='Partner Channel' value={filters.partnerChannel} onChange={(event) => updateFilter('partnerChannel', event.target.value)} className={selectClass}><option value=''>Semua Partner</option>{options.partner_channels.map((item) => <option key={item}>{item}</option>)}</select>
      <select aria-label='Payment Channel' value={filters.paymentChannel} onChange={(event) => updateFilter('paymentChannel', event.target.value)} className={selectClass}><option value=''>Semua Payment Channel</option>{options.payment_channels.map((item) => <option key={item}>{item}</option>)}</select>
      <select aria-label='Tipe transaksi' value={filters.transactionType} onChange={(event) => updateFilter('transactionType', event.target.value)} className={selectClass}><option value=''>Semua Type</option>{options.transaction_types.map((item) => <option key={item}>{item}</option>)}</select>
      <select aria-label='Response code' value={filters.responseCode} onChange={(event) => updateFilter('responseCode', event.target.value)} className={selectClass}><option value=''>Semua RC</option>{options.response_codes.map((item) => <option key={item} value={item}>RC {item}</option>)}</select>
    </div>
  </section>;
}
