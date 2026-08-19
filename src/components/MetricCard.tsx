import { useLayoutEffect, useRef, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';

interface MetricCardProps {
  label: string;
  value: string;
  compactValue?: string;
  note: string;
  change?: number;
  icon?: LucideIcon;
  iconLabel?: string;
  tone: 'indigo' | 'emerald' | 'amber' | 'sky';
  delay?: string;
}

const tones = {
  indigo: 'bg-indigo-50 text-indigo-600', emerald: 'bg-emerald-50 text-emerald-600',
  amber: 'bg-amber-50 text-amber-600', sky: 'bg-sky-50 text-sky-600',
};

// Membuat fallback Rupiah ringkas dari nilai Rupiah utuh bila caller tidak menyediakannya.
function inferCompactCurrency(value: string): string | undefined {
  if (!value.startsWith('Rp')) return undefined;
  const numericValue = Number(value.replace(/[^0-9-]/g, ''));
  if (!Number.isFinite(numericValue)) return undefined;
  return new Intl.NumberFormat('id-ID', {
    style: 'currency', currency: 'IDR', notation: 'compact', maximumFractionDigits: 2,
  }).format(numericValue);
}

// Menampilkan metrik utama dan meringkas nominal hanya ketika teks utuh tidak muat.
export function MetricCard({ label, value, compactValue, note, change, icon: Icon, iconLabel, tone, delay = '0ms' }: MetricCardProps) {
  const positive = (change ?? 0) >= 0;
  const effectiveCompactValue = compactValue ?? inferCompactCurrency(value);
  const valueContainer = useRef<HTMLDivElement>(null);
  const fullValueMeasure = useRef<HTMLSpanElement>(null);
  const [useCompactValue, setUseCompactValue] = useState(false);

  // Mengukur ulang teks ketika nilai atau lebar card berubah.
  useLayoutEffect(() => {
    const container = valueContainer.current;
    const measure = fullValueMeasure.current;
    if (!container || !measure || !effectiveCompactValue) {
      setUseCompactValue(false);
      return;
    }
    const updateFormat = () => setUseCompactValue(measure.getBoundingClientRect().width > container.clientWidth);
    updateFormat();
    const observer = new ResizeObserver(updateFormat);
    observer.observe(container);
    return () => observer.disconnect();
  }, [value, effectiveCompactValue]);

  return (
    <article style={{ animationDelay: delay }} className="animate-fade-up rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 transition hover:-translate-y-0.5 hover:shadow-md">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <p className="text-[10px] font-bold uppercase tracking-[.13em] text-slate-400">{label}</p>
          <div ref={valueContainer} className="relative mt-3 min-w-0 overflow-hidden">
            <span ref={fullValueMeasure} aria-hidden="true" className="pointer-events-none absolute whitespace-nowrap text-2xl font-bold tracking-tight opacity-0">{value}</span>
            <p className="whitespace-nowrap text-2xl font-bold tracking-tight text-slate-900">{useCompactValue && effectiveCompactValue ? effectiveCompactValue : value}</p>
          </div>
        </div>
        <span className={`flex h-10 min-w-10 items-center justify-center rounded-xl px-2 text-sm font-extrabold ${tones[tone]}`}>
          {Icon ? <Icon className="h-5 w-5" /> : iconLabel}
        </span>
      </div>
      <div className="mt-4 flex items-center gap-2 border-t border-slate-100 pt-3">
        {change !== undefined && <span className={`flex items-center gap-0.5 text-xs font-bold ${positive ? 'text-emerald-600' : 'text-rose-600'}`}>{positive ? <ArrowUpRight className="h-3.5 w-3.5" /> : <ArrowDownRight className="h-3.5 w-3.5" />}{Math.abs(change)}%</span>}
        <span className="truncate text-[11px] text-slate-400">{note}</span>
      </div>
    </article>
  );
}
