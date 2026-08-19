import { useState } from 'react';
import { MoreHorizontal } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import type { DailyPerformance } from '../types';

type SeriesKey = 'inquiry' | 'payment';

const series = [
  { key: 'inquiry' as const, label: 'Inquiry Sukses', colorClass: 'bg-indigo-500' },
  { key: 'payment' as const, label: 'Payment Sukses', colorClass: 'bg-emerald-500' },
];

interface PerformanceChartProps { data: DailyPerformance[]; }

// Menampilkan tren harian serta mengelola visibilitas seri melalui legend interaktif.
export function PerformanceChart({ data }: PerformanceChartProps) {
  const [visibleSeries, setVisibleSeries] = useState<Record<SeriesKey, boolean>>({
    inquiry: true,
    payment: true,
  });

  // Membalik visibilitas satu seri tanpa memengaruhi seri lainnya.
  const toggleSeries = (key: SeriesKey) => {
    setVisibleSeries((current) => ({ ...current, [key]: !current[key] }));
  };
  const chartData = data.map((item) => ({
    day: new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short' }).format(new Date(`${item.transaction_date}T00:00:00`)),
    inquiry: Number(item.inquiry), payment: Number(item.payment),
  }));

  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 lg:col-span-2">
      <div className="mb-5 flex items-start justify-between">
        <div>
          <h2 className="text-sm font-bold text-slate-900">Tren Transaksi Harian</h2>
          <p className="mt-1 text-xs text-slate-400">Volume transaksi sukses sesuai aturan laporan</p>
        </div>
        <button aria-label="Opsi grafik" className="rounded-lg p-2 text-slate-400 hover:bg-slate-50">
          <MoreHorizontal className="h-5 w-5" />
        </button>
      </div>

      <div aria-label="Legend grafik" className="mb-4 flex gap-2 text-[11px] font-semibold">
        {series.map((item) => {
          const isVisible = visibleSeries[item.key];
          return (
            <button
              key={item.key}
              type="button"
              aria-pressed={isVisible}
              title={`${isVisible ? 'Sembunyikan' : 'Tampilkan'} seri ${item.label}`}
              onClick={() => toggleSeries(item.key)}
              className={`flex items-center gap-2 rounded-lg border px-2.5 py-1.5 transition focus:outline-none focus:ring-2 focus:ring-indigo-200 ${
                isVisible
                  ? 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                  : 'border-transparent bg-slate-100 text-slate-400 line-through'
              }`}
            >
              <i className={`h-2 w-2 rounded-full transition ${item.colorClass} ${isVisible ? 'opacity-100' : 'opacity-30'}`} />
              {item.label}
            </button>
          );
        })}
      </div>

      <div className="h-72">
        <ResponsiveContainer width="100%" height="100%">
          <AreaChart data={chartData} margin={{ left: -20, right: 5 }}>
            <defs>
              <linearGradient id="inquiry" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#6366f1" stopOpacity={0.25} />
                <stop offset="100%" stopColor="#6366f1" stopOpacity={0} />
              </linearGradient>
              <linearGradient id="payment" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="#10b981" stopOpacity={0.2} />
                <stop offset="100%" stopColor="#10b981" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 5" vertical={false} />
            <XAxis dataKey="day" axisLine={false} tickLine={false} tick={{ fontSize: 10, fill: '#94a3b8' }} />
            <YAxis
              axisLine={false}
              tickLine={false}
              allowDecimals={false}
              domain={[0, 'auto']}
              tickCount={5}
              tick={{ fontSize: 10, fill: '#94a3b8' }}
              tickFormatter={(value) => Number(value).toLocaleString('id-ID')}
            />
            <Tooltip
              contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0', fontSize: 11, boxShadow: '0 8px 24px #0f172a12' }}
            />
            <Area
              type="monotone"
              dataKey="inquiry"
              name="Inquiry Sukses"
              hide={!visibleSeries.inquiry}
              stroke="#6366f1"
              strokeWidth={2.5}
              fill="url(#inquiry)"
            />
            <Area
              type="monotone"
              dataKey="payment"
              name="Payment Sukses"
              hide={!visibleSeries.payment}
              stroke="#10b981"
              strokeWidth={2.5}
              fill="url(#payment)"
            />
          </AreaChart>
        </ResponsiveContainer>
      </div>
    </section>
  );
}
