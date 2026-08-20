import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { AlertTriangle, X } from 'lucide-react';

interface ModalProps { isOpen: boolean; title: string; description: string; children: ReactNode; onClose: () => void; size?: 'large' | 'full'; confirmClose?: boolean; closeConfirmationMessage?: string; }

// Menampilkan dialog responsif, mengunci scroll halaman, dan mendukung tombol Escape.
export function Modal({ isOpen, title, description, children, onClose, size = 'large', confirmClose = false, closeConfirmationMessage = 'Perubahan yang belum disimpan akan hilang.' }: ModalProps) {
  const [isCloseConfirmationOpen, setIsCloseConfirmationOpen] = useState(false);

  // Meminta konfirmasi sebelum menutup ketika konten modal masih memiliki pekerjaan yang belum selesai.
  const requestClose = useCallback(() => {
    if (confirmClose) { setIsCloseConfirmationOpen(true); return; }
    onClose();
  }, [confirmClose, onClose]);

  useEffect(() => {
    if (!isOpen) return;
    const closeWithEscape = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      if (isCloseConfirmationOpen) setIsCloseConfirmationOpen(false);
      else requestClose();
    };
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', closeWithEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', closeWithEscape);
    };
  }, [isCloseConfirmationOpen, isOpen, requestClose]);

  // Menutup dialog konfirmasi internal ketika modal utama sudah ditutup atau guard tidak lagi diperlukan.
  useEffect(() => {
    if (!isOpen || !confirmClose) setIsCloseConfirmationOpen(false);
  }, [confirmClose, isOpen]);

  if (!isOpen) return null;
  const width = size === 'full' ? 'max-w-[1500px]' : 'max-w-5xl';
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/55 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) requestClose(); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="modal-title" className={`flex max-h-[100dvh] w-full flex-col overflow-hidden bg-slate-50 shadow-2xl sm:max-h-[92dvh] sm:rounded-3xl ${width}`}>
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
          <div><h2 id="modal-title" className="text-base font-bold text-slate-900">{title}</h2><p className="mt-1 text-xs text-slate-400">{description}</p></div>
          <button aria-label="Tutup modal" onClick={requestClose} className="rounded-xl border border-slate-200 p-2 text-slate-500 transition hover:bg-slate-100"><X className="h-4 w-4" /></button>
        </header>
        <div className="flex-1 overflow-y-auto p-4 scrollbar-subtle sm:p-6">{children}</div>
      </section>
      {isCloseConfirmationOpen && <div className="fixed inset-0 z-[60] flex items-center justify-center bg-slate-950/65 p-4" role="presentation"><section role="alertdialog" aria-modal="true" aria-labelledby="close-confirmation-title" className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><span className="flex h-11 w-11 items-center justify-center rounded-full bg-amber-100 text-amber-700"><AlertTriangle className="h-5 w-5"/></span><h3 id="close-confirmation-title" className="mt-4 text-base font-bold text-slate-900">Tutup proses import?</h3><p className="mt-2 text-xs leading-5 text-slate-500">{closeConfirmationMessage}</p><div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><button autoFocus onClick={() => setIsCloseConfirmationOpen(false)} className="rounded-xl border border-slate-200 px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-50">Tetap di halaman</button><button onClick={() => { setIsCloseConfirmationOpen(false); onClose(); }} className="rounded-xl bg-rose-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-rose-700">Tutup dan tinggalkan preview</button></div></section></div>}
    </div>
  );
}
