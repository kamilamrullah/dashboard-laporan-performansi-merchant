import { ChevronRight } from 'lucide-react';
import type { PartnerPerformance } from '../types';

interface ChannelTableProps { data: PartnerPerformance[]; }

// Mengubah nominal menjadi format mata uang Rupiah utuh.
function formatCurrency(value: string): string {
  return new Intl.NumberFormat('id-ID', {
    style: 'currency', currency: 'IDR', minimumFractionDigits: 0, maximumFractionDigits: 0,
  }).format(Number(value));
}

// Menampilkan ranking transaksi sukses Partner Channel sesuai aturan laporan.
export function ChannelTable({ data }: ChannelTableProps) {
  return (
    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm shadow-slate-200/40">
      <div className="flex items-center justify-between border-b border-slate-100 px-5 py-4">
        <div><h2 className="text-sm font-bold text-slate-900">Performa Partner Channel</h2><p className="mt-1 text-xs text-slate-400">Transaksi sukses sesuai aturan laporan, diurutkan berdasarkan total</p></div>
        <button className="flex items-center gap-1 text-xs font-bold text-indigo-600 hover:text-indigo-800">Lihat detail <ChevronRight className="h-4 w-4" /></button>
      </div>
      <div className="overflow-x-auto scrollbar-subtle">
        <table className="w-full min-w-[820px] text-left">
          <thead className="bg-slate-50/80 text-[10px] font-bold uppercase tracking-wider text-slate-400"><tr><th className="px-5 py-3">Partner Channel</th><th className="px-4 py-3">Inquiry Sukses</th><th className="px-4 py-3">Payment Sukses</th><th className="px-4 py-3">Total Sukses</th><th className="px-4 py-3">Nominal Payment</th><th className="px-5 py-3 text-right">Payment/Inquiry</th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {data.map((item, index) => {
              const inquiry = Number(item.inquiry);
              const payment = Number(item.payment);
              const ratio = inquiry > 0 ? (payment / inquiry) * 100 : 0;
              return <tr key={item.name} className="text-xs transition hover:bg-slate-50/70"><td className="px-5 py-4"><div className="flex items-center gap-3"><span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-[10px] font-bold text-indigo-600">{index + 1}</span><span className="font-semibold text-slate-800">{item.name}</span></div></td><td className="px-4 py-4 font-medium text-slate-600">{inquiry.toLocaleString('id-ID')}</td><td className="px-4 py-4 font-medium text-slate-600">{payment.toLocaleString('id-ID')}</td><td className="px-4 py-4 font-bold text-slate-800">{Number(item.total_trx).toLocaleString('id-ID')}</td><td className="px-4 py-4 font-bold text-slate-800">{formatCurrency(item.payment_amount)}</td><td className="px-5 py-4 text-right font-bold text-indigo-600">{ratio.toLocaleString('id-ID', { maximumFractionDigits: 2 })}%</td></tr>;
            })}
            {data.length === 0 && <tr><td colSpan={6} className="px-5 py-10 text-center text-xs text-slate-400">Tidak ada data untuk filter yang dipilih.</td></tr>}
          </tbody>
        </table>
      </div>
    </section>
  );
}
