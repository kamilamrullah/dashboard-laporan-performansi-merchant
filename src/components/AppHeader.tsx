import { useEffect, useRef, useState } from 'react';
import { Bell, ChevronDown, CreditCard, FileOutput, FileSpreadsheet, Menu, Search, UploadCloud, WalletCards } from 'lucide-react';

interface AppHeaderProps {
  onOpenImport: (type: 'transactions' | 'tickets') => void;
  onOpenReport: () => void;
  onOpenPaymentChannels: () => void;
}

// Menampilkan navbar responsif beserta dropdown aksi untuk import dan generate laporan.
export function AppHeader({ onOpenImport, onOpenReport, onOpenPaymentChannels }: AppHeaderProps) {
  const [isActionOpen, setIsActionOpen] = useState(false);
  const [isMobileOpen, setIsMobileOpen] = useState(false);
  const actionRef = useRef<HTMLDivElement>(null);

  // Menutup dropdown aksi saat pengguna mengklik area lain atau menekan Escape.
  useEffect(() => {
    const closeMenus = (event: MouseEvent) => {
      if (!actionRef.current?.contains(event.target as Node)) setIsActionOpen(false);
    };
    const closeWithKeyboard = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setIsActionOpen(false);
        setIsMobileOpen(false);
      }
    };
    document.addEventListener('mousedown', closeMenus);
    document.addEventListener('keydown', closeWithKeyboard);
    return () => {
      document.removeEventListener('mousedown', closeMenus);
      document.removeEventListener('keydown', closeWithKeyboard);
    };
  }, []);

  // Menjalankan aksi menu lalu menutup seluruh menu navigasi.
  const runAction = (action: () => void) => {
    setIsActionOpen(false);
    setIsMobileOpen(false);
    action();
  };

  const actionItems = [
    { label: 'Import Data Transaksi', note: 'Workbook transaksi agregat', icon: FileSpreadsheet, action: () => onOpenImport('transactions') },
    { label: 'Import Tiket Aduan', note: 'Workbook data tiket', icon: UploadCloud, action: () => onOpenImport('tickets') },
    { label: 'Generate Laporan', note: 'Dokumen Microsoft Word', icon: FileOutput, action: onOpenReport },
    { label: 'Master Payment Channel', note: 'Mapping SIC code', icon: CreditCard, action: onOpenPaymentChannels },
  ];

  return (
    <header className="sticky top-0 z-30 border-b border-slate-200/80 bg-white/95 backdrop-blur-xl">
      <div className="mx-auto max-w-[1800px] px-4 sm:px-6 lg:px-8">
        <div className="flex min-h-20 items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-indigo-600 text-white shadow-lg shadow-indigo-200">
              <WalletCards className="h-5 w-5" />
            </span>
            <div className="min-w-0">
              <h1 className="truncate text-sm font-bold text-slate-900 sm:text-base">Merchant Performance</h1>
              <p className="hidden text-[10px] font-medium uppercase tracking-[.16em] text-slate-400 sm:block">Reporting Center</p>
            </div>
          </div>

          <div className="hidden items-center gap-3 md:flex">
            <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5">
              <Search className="h-4 w-4 text-slate-400" />
              <input aria-label="Cari data" placeholder="Cari data..." className="w-36 bg-transparent text-xs outline-none xl:w-52" />
            </label>
            <div ref={actionRef} className="relative">
              <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={isActionOpen}
                onClick={() => setIsActionOpen((current) => !current)}
                className="flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-indigo-200 transition hover:bg-indigo-700"
              >
                <FileOutput className="h-4 w-4" /> Aksi
                <ChevronDown className={`h-3.5 w-3.5 transition ${isActionOpen ? 'rotate-180' : ''}`} />
              </button>
              {isActionOpen && (
                <div role="menu" className="absolute right-0 top-full mt-2 w-72 overflow-hidden rounded-2xl border border-slate-200 bg-white p-2 shadow-2xl shadow-slate-300/50">
                  {actionItems.map(({ label, note, icon: Icon, action }) => (
                    <button key={label} role="menuitem" onClick={() => runAction(action)} className="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left hover:bg-indigo-50">
                      <span className="rounded-lg bg-indigo-50 p-2 text-indigo-600"><Icon className="h-4 w-4" /></span>
                      <span><span className="block text-xs font-bold text-slate-800">{label}</span><span className="mt-0.5 block text-[10px] text-slate-400">{note}</span></span>
                    </button>
                  ))}
                </div>
              )}
            </div>
            <button aria-label="Notifikasi" className="relative rounded-xl border border-slate-200 p-2.5 text-slate-600 hover:bg-slate-50">
              <Bell className="h-4 w-4" /><span className="absolute right-2 top-2 h-2 w-2 rounded-full border-2 border-white bg-rose-500" />
            </button>
            <button className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white p-1.5 pr-2.5 hover:bg-slate-50">
              <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-100 text-xs font-bold text-indigo-700">KA</span>
              <span className="hidden text-left lg:block"><span className="block text-xs font-bold text-slate-800">Kamil</span><span className="block text-[10px] text-slate-400">Administrator</span></span>
              <ChevronDown className="hidden h-3.5 w-3.5 text-slate-400 lg:block" />
            </button>
          </div>

          <button onClick={() => setIsMobileOpen((current) => !current)} aria-label="Buka menu" aria-expanded={isMobileOpen} className="rounded-xl border border-slate-200 p-2.5 text-slate-600 md:hidden">
            <Menu className="h-5 w-5" />
          </button>
        </div>

        {isMobileOpen && (
          <div className="border-t border-slate-100 pb-4 pt-3 md:hidden">
            <label className="mb-2 flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2.5"><Search className="h-4 w-4 text-slate-400" /><input aria-label="Cari data" placeholder="Cari data..." className="w-full bg-transparent text-xs outline-none" /></label>
            <div className="grid gap-1 sm:grid-cols-3">
              {actionItems.map(({ label, icon: Icon, action }) => <button key={label} onClick={() => runAction(action)} className="flex items-center gap-2 rounded-xl px-3 py-2.5 text-left text-xs font-bold text-slate-700 hover:bg-indigo-50 hover:text-indigo-700"><Icon className="h-4 w-4" />{label}</button>)}
            </div>
          </div>
        )}
      </div>
    </header>
  );
}
