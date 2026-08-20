import { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight, Clock3, LoaderCircle, Plus, RefreshCw, Search, X } from 'lucide-react';
import { createPaymentChannel, fetchPaymentChannelHistory, fetchPaymentChannels, setPaymentChannelActive } from '../services/paymentChannelApi';
import type { PaymentChannelChangeHistory, PaymentChannelMasterItem, PaymentChannelMasterList } from '../types';

// Menampilkan angka penggunaan mapping dalam format lokal Indonesia.
function formatNumber(value: string | number): string {
  return new Intl.NumberFormat('id-ID').format(Number(value));
}

// Menampilkan waktu audit dalam zona waktu pengguna.
function formatDate(value: string): string {
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value.replace(' ', 'T')));
}

// Mengubah kode audit database menjadi label yang mudah dipahami pengguna.
function formatHistoryAction(action: string): string {
  return ({ CREATED: 'Ditambahkan', ACTIVATED: 'Diaktifkan', DEACTIVATED: 'Dinonaktifkan' } as Record<string, string>)[action] ?? action;
}

// Menyediakan penambahan, aktivasi, nonaktivasi, dan audit mapping payment channel.
export function PaymentChannelMaster() {
  const [data, setData] = useState<PaymentChannelMasterList | null>(null);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<'all' | 'active' | 'inactive'>('all');
  const [page, setPage] = useState(1);
  const [reloadKey, setReloadKey] = useState(0);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [form, setForm] = useState<{ sicCode: string; channelName: string } | null>(null);
  const [pendingToggle, setPendingToggle] = useState<PaymentChannelMasterItem | null>(null);
  const [historySic, setHistorySic] = useState<string | null>(null);
  const [history, setHistory] = useState<PaymentChannelChangeHistory[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  // Memuat daftar sesuai filter dan membatalkan request ketika komponen berubah.
  useEffect(() => {
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    void fetchPaymentChannels(search, status, page, controller.signal)
      .then(setData)
      .catch((reason: unknown) => { if (!(reason instanceof DOMException && reason.name === 'AbortError')) setError(reason instanceof Error ? reason.message : 'Master payment channel gagal dimuat.'); })
      .finally(() => setLoading(false));
    return () => controller.abort();
  }, [search, status, page, reloadKey]);

  // Menyimpan mapping baru setelah validasi form di sisi tampilan.
  async function saveMapping(): Promise<void> {
    if (!form) return;
    setSaving(true);
    setError(null);
    try {
      await createPaymentChannel(form.sicCode.trim(), form.channelName.trim());
      setForm(null);
      setReloadKey((value) => value + 1);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Payment channel gagal ditambahkan.'); }
    finally { setSaving(false); }
  }

  // Menerapkan status kebalikan setelah pengguna mengonfirmasinya.
  async function applyToggle(): Promise<void> {
    if (!pendingToggle) return;
    setSaving(true);
    setError(null);
    try {
      await setPaymentChannelActive(pendingToggle.sic_code, Number(pendingToggle.is_active) !== 1);
      setPendingToggle(null);
      setReloadKey((value) => value + 1);
    } catch (reason) { setError(reason instanceof Error ? reason.message : 'Status payment channel gagal diubah.'); }
    finally { setSaving(false); }
  }

  // Membuka audit terbaru untuk satu SIC code.
  function openHistory(sicCode: string): void {
    setHistorySic(sicCode);
    setHistory([]);
    setHistoryLoading(true);
    void fetchPaymentChannelHistory(sicCode)
      .then(setHistory)
      .catch((reason: unknown) => setError(reason instanceof Error ? reason.message : 'Riwayat gagal dimuat.'))
      .finally(() => setHistoryLoading(false));
  }

  return <div className="space-y-5">
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
        <div><h2 className="text-base font-bold text-slate-900">Master Payment Channel</h2><p className="mt-1 text-xs text-slate-500">Mapping SIC code untuk preview, dashboard, dan laporan.</p></div>
        <div className="flex gap-2"><button title="Muat ulang" onClick={() => setReloadKey((value) => value + 1)} disabled={loading} className="rounded-xl border border-slate-200 p-2.5 text-slate-500 disabled:opacity-50"><RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`}/></button><button onClick={() => setForm({ sicCode: '', channelName: '' })} className="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white"><Plus className="h-4 w-4"/>Tambah Mapping</button></div>
      </div>
      <form onSubmit={(event) => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()); }} className="mt-5 flex flex-col gap-3 sm:flex-row">
        <label className="flex flex-1 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2.5"><Search className="h-4 w-4 text-slate-400"/><input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari SIC code atau nama channel" className="w-full bg-transparent text-xs outline-none"/></label>
        <select value={status} onChange={(event) => { setStatus(event.target.value as typeof status); setPage(1); }} className="rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs font-semibold"><option value="all">Semua status</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
        <button className="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-600">Cari</button>
      </form>
      {data && data.unmapped_sic_count > 0 && <p className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">Terdapat {data.unmapped_sic_count} SIC code transaksi yang belum memiliki mapping.</p>}
    </section>

    {error && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs font-semibold text-rose-700">{error}</div>}
    {form && <section className="rounded-2xl border border-indigo-200 bg-indigo-50/40 p-5">
      <div className="flex items-center justify-between"><h3 className="text-sm font-bold text-slate-900">Tambah Mapping</h3><button onClick={() => setForm(null)}><X className="h-4 w-4 text-slate-500"/></button></div>
      <div className="mt-4 grid gap-4 sm:grid-cols-2"><label className="text-xs font-bold text-slate-700">SIC Code<input value={form.sicCode} maxLength={32} onChange={(event) => setForm({ ...form, sicCode: event.target.value })} className="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 font-mono text-xs outline-none"/></label><label className="text-xs font-bold text-slate-700">Nama Channel<input value={form.channelName} maxLength={160} onChange={(event) => setForm({ ...form, channelName: event.target.value })} className="mt-2 w-full rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-xs outline-none"/></label></div>
      <div className="mt-4 flex justify-end gap-2"><button onClick={() => setForm(null)} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600">Batal</button><button disabled={saving || !form.sicCode.trim() || !form.channelName.trim()} onClick={() => void saveMapping()} className="rounded-xl bg-indigo-600 px-4 py-2.5 text-xs font-bold text-white disabled:opacity-50">Simpan</button></div>
    </section>}

    {pendingToggle && <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget && !saving) setPendingToggle(null); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="toggle-payment-channel-title" className="w-full max-w-md rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
        <div className="flex items-start justify-between gap-4"><div><p className="text-[10px] font-bold uppercase tracking-wider text-indigo-600">Konfirmasi Status</p><h3 id="toggle-payment-channel-title" className="mt-2 text-base font-bold text-slate-900">{Number(pendingToggle.is_active) === 1 ? 'Nonaktifkan' : 'Aktifkan'} {pendingToggle.channel_name}?</h3></div><button aria-label="Tutup konfirmasi" disabled={saving} onClick={() => setPendingToggle(null)} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 disabled:opacity-50"><X className="h-4 w-4"/></button></div>
        <p className="mt-3 text-xs leading-5 text-slate-600">SIC Code <span className="font-mono font-bold text-slate-800">{pendingToggle.sic_code}</span>. Transaksi historis tidak dihapus dan status dapat diubah kembali.</p>
        <div className="mt-6 flex justify-end gap-2"><button disabled={saving} onClick={() => setPendingToggle(null)} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600 disabled:opacity-50">Batal</button><button disabled={saving} onClick={() => void applyToggle()} className={`inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-xs font-bold text-white disabled:opacity-50 ${Number(pendingToggle.is_active) === 1 ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'}`}>{saving && <LoaderCircle className="h-3.5 w-3.5 animate-spin"/>}{Number(pendingToggle.is_active) === 1 ? 'Ya, Nonaktifkan' : 'Ya, Aktifkan'}</button></div>
      </section>
    </div>}

    <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
      {loading && !data ? <div className="flex min-h-64 items-center justify-center text-xs text-slate-500"><LoaderCircle className="mr-2 h-4 w-4 animate-spin"/>Memuat master data...</div> : <div className="overflow-x-auto"><table className="min-w-[900px] w-full text-left text-xs">
        <thead className="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th className="px-4 py-3">SIC Code</th><th className="px-4 py-3">Payment Channel</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Baris agregat</th><th className="px-4 py-3 text-right">Total trx</th><th className="px-4 py-3">Diperbarui</th><th className="px-4 py-3">Aksi</th></tr></thead>
        <tbody className="divide-y divide-slate-100">{data?.items.map((item) => <tr key={item.sic_code}><td className="px-4 py-3 font-mono font-bold text-indigo-700">{item.sic_code}</td><td className="px-4 py-3 font-semibold">{item.channel_name}</td><td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-[9px] font-bold ${Number(item.is_active) === 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600'}`}>{Number(item.is_active) === 1 ? 'AKTIF' : 'NONAKTIF'}</span></td><td className="px-4 py-3 text-right">{formatNumber(item.aggregate_rows)}</td><td className="px-4 py-3 text-right">{formatNumber(item.total_trx)}</td><td className="px-4 py-3 text-[10px] text-slate-500">{formatDate(item.updated_at)}</td><td className="px-4 py-3"><div className="flex items-center gap-2"><button type="button" role="switch" aria-checked={Number(item.is_active) === 1} aria-label={`${Number(item.is_active) === 1 ? 'Nonaktifkan' : 'Aktifkan'} ${item.channel_name}`} title={Number(item.is_active) === 1 ? 'Nonaktifkan' : 'Aktifkan'} onClick={() => setPendingToggle(item)} className={`relative h-6 w-11 rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 ${Number(item.is_active) === 1 ? 'bg-emerald-500' : 'bg-slate-300'}`}><span className={`absolute top-0.5 h-5 w-5 rounded-full bg-white shadow-sm transition-transform ${Number(item.is_active) === 1 ? 'translate-x-5' : 'translate-x-0.5'}`}/></button><button title="Riwayat" onClick={() => openHistory(item.sic_code)} className="rounded-lg border border-slate-200 p-2"><Clock3 className="h-3.5 w-3.5"/></button></div></td></tr>)}{data?.items.length === 0 && <tr><td colSpan={7} className="px-4 py-12 text-center text-slate-400">Mapping tidak ditemukan.</td></tr>}</tbody>
      </table></div>}
      {data && <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-xs text-slate-500"><span>Halaman {data.pagination.page} dari {data.pagination.total_pages} · {data.pagination.total} mapping</span><div className="flex gap-2"><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)} className="rounded-lg border p-2 disabled:opacity-40"><ChevronLeft className="h-3.5 w-3.5"/></button><button disabled={page >= data.pagination.total_pages} onClick={() => setPage((value) => value + 1)} className="rounded-lg border p-2 disabled:opacity-40"><ChevronRight className="h-3.5 w-3.5"/></button></div></div>}
    </section>

    {historySic && <div className="fixed inset-0 z-[70] flex items-center justify-center bg-slate-950/45 p-4 backdrop-blur-sm" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setHistorySic(null); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="payment-channel-history-title" className="flex max-h-[80vh] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
        <div className="flex items-start justify-between gap-4 border-b border-slate-100 p-6"><div><p className="text-[10px] font-bold uppercase tracking-wider text-indigo-600">Riwayat Perubahan</p><h3 id="payment-channel-history-title" className="mt-2 text-base font-bold text-slate-900">{data?.items.find((item) => item.sic_code === historySic)?.channel_name ?? 'Payment Channel'}</h3><p className="mt-1 text-xs text-slate-500">SIC Code <span className="font-mono font-bold text-slate-700">{historySic}</span></p></div><button aria-label="Tutup riwayat" onClick={() => setHistorySic(null)} className="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100"><X className="h-4 w-4"/></button></div>
        <div className="overflow-y-auto p-6">{historyLoading ? <div className="flex items-center justify-center py-10 text-xs font-semibold text-slate-500"><LoaderCircle className="mr-2 h-4 w-4 animate-spin"/>Memuat riwayat...</div> : <div className="space-y-3">{history.map((item) => <div key={item.id} className="relative rounded-xl border border-slate-100 bg-slate-50 p-4"><div className="flex flex-wrap items-center justify-between gap-2"><span className={`rounded-full px-2.5 py-1 text-[10px] font-bold ${item.action === 'DEACTIVATED' ? 'bg-rose-100 text-rose-700' : item.action === 'ACTIVATED' ? 'bg-emerald-100 text-emerald-700' : 'bg-indigo-100 text-indigo-700'}`}>{formatHistoryAction(item.action)}</span><span className="text-[10px] text-slate-400">{formatDate(item.created_at)}</span></div><p className="mt-3 text-xs text-slate-600">Status: <strong>{item.new_is_active == null ? '—' : Number(item.new_is_active) === 1 ? 'Aktif' : 'Nonaktif'}</strong></p></div>)}{history.length === 0 && <p className="py-10 text-center text-xs text-slate-400">Belum ada riwayat perubahan.</p>}</div>}</div>
        <div className="flex justify-end border-t border-slate-100 p-4"><button onClick={() => setHistorySic(null)} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50">Tutup</button></div>
      </section>
    </div>}
  </div>;
}
