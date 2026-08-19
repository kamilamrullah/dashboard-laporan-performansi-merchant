import { useEffect, useMemo, useState } from 'react';
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import type { ResponseCodePerformance } from '../types';

interface TransactionStatusChartProps { data: ResponseCodePerformance[]; }
const colors = ['#6366f1', '#10b981', '#f59e0b', '#f43f5e', '#0ea5e9', '#8b5cf6', '#14b8a6', '#f97316'];

// Mengubah jumlah transaksi menjadi angka ringkas untuk label pusat chart.
function formatCompactNumber(value: number): string {
  return new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

// Menampilkan komposisi response code mentah tanpa memberikan arti bisnis yang belum dikonfirmasi.
export function TransactionStatusChart({ data }: TransactionStatusChartProps) {
  const [hiddenCodes, setHiddenCodes] = useState<Set<string>>(new Set());

  // Menghapus status tersembunyi yang sudah tidak tersedia setelah filter dashboard berubah.
  useEffect(() => {
    setHiddenCodes((current) => new Set([...current].filter((code) => data.some((item) => item.code === code))));
  }, [data]);

  const chartData = useMemo(() => data.map((item, index) => ({
    code: item.code, name: `RC ${item.code}`, value: Number(item.total_trx), color: colors[index % colors.length],
  })), [data]);
  const visibleData = chartData.filter((item) => !hiddenCodes.has(item.code));
  const visibleTotal = visibleData.reduce((total, item) => total + item.value, 0);
  const completeTotal = chartData.reduce((total, item) => total + item.value, 0);

  // Membalik visibilitas satu response code tanpa memengaruhi response code lainnya.
  const toggleCode = (code: string) => {
    setHiddenCodes((current) => {
      const next = new Set(current);
      if (next.has(code)) next.delete(code); else next.add(code);
      return next;
    });
  };

  return <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 className="text-sm font-bold text-slate-900">Komposisi Response Code</h2><p className="mt-1 text-xs text-slate-400">Kategori RC ditampilkan tanpa interpretasi status</p><div className="relative mt-4 h-52"><ResponsiveContainer width="100%" height="100%"><PieChart><Pie data={visibleData} dataKey="value" nameKey="name" cx="50%" cy="50%" innerRadius={64} outerRadius={88} paddingAngle={visibleData.length > 1 ? 3 : 0} cornerRadius={5} stroke="none" animationDuration={350}>{visibleData.map((item) => <Cell key={item.code} fill={item.color}/>)}</Pie><Tooltip formatter={(value, name) => [Number(value).toLocaleString('id-ID'), name]} contentStyle={{ borderRadius: 12, border: '1px solid #e2e8f0', fontSize: 11, boxShadow: '0 8px 24px #0f172a12' }}/></PieChart></ResponsiveContainer><div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center"><span className="text-2xl font-bold text-slate-900">{formatCompactNumber(visibleTotal)}</span><span className="text-[10px] uppercase tracking-wider text-slate-400">Ditampilkan</span></div></div><div aria-label="Legend response code" className="mt-2 grid max-h-32 grid-cols-2 gap-1 overflow-y-auto scrollbar-subtle">{chartData.map((item) => { const isVisible = !hiddenCodes.has(item.code); const percentage = completeTotal ? Math.round((item.value / completeTotal) * 100) : 0; return <button key={item.code} type="button" aria-pressed={isVisible} title={`${isVisible ? 'Sembunyikan' : 'Tampilkan'} RC ${item.code}`} onClick={() => toggleCode(item.code)} className={`flex items-center justify-between rounded-lg px-2 py-1.5 text-xs transition focus:outline-none focus:ring-2 focus:ring-indigo-200 ${isVisible ? 'hover:bg-slate-50' : 'bg-slate-50 opacity-45'}`}><span className={`flex items-center gap-2 text-slate-500 ${isVisible ? '' : 'line-through'}`}><i className="h-2 w-2 rounded-full" style={{ backgroundColor: item.color }}/>RC {item.code}</span><span className="font-bold text-slate-800">{percentage}%</span></button>; })}</div><p className="mt-4 rounded-lg bg-sky-50 p-3 text-[10px] leading-4 text-sky-700">Arti setiap response code akan ditambahkan setelah aturan bisnis tersedia.</p></section>;
}
