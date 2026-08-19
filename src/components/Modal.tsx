import { useEffect, type ReactNode } from 'react';
import { X } from 'lucide-react';

interface ModalProps { isOpen: boolean; title: string; description: string; children: ReactNode; onClose: () => void; size?: 'large' | 'full'; }

// Menampilkan dialog responsif, mengunci scroll halaman, dan mendukung tombol Escape.
export function Modal({ isOpen, title, description, children, onClose, size = 'large' }: ModalProps) {
  useEffect(() => {
    if (!isOpen) return;
    const closeWithEscape = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', closeWithEscape);
    return () => {
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', closeWithEscape);
    };
  }, [isOpen, onClose]);

  if (!isOpen) return null;
  const width = size === 'full' ? 'max-w-[1500px]' : 'max-w-5xl';
  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-slate-950/55 p-0 backdrop-blur-sm sm:items-center sm:p-5" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <section role="dialog" aria-modal="true" aria-labelledby="modal-title" className={`flex max-h-[100dvh] w-full flex-col overflow-hidden bg-slate-50 shadow-2xl sm:max-h-[92dvh] sm:rounded-3xl ${width}`}>
        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 bg-white px-5 py-4 sm:px-6">
          <div><h2 id="modal-title" className="text-base font-bold text-slate-900">{title}</h2><p className="mt-1 text-xs text-slate-400">{description}</p></div>
          <button aria-label="Tutup modal" onClick={onClose} className="rounded-xl border border-slate-200 p-2 text-slate-500 transition hover:bg-slate-100"><X className="h-4 w-4" /></button>
        </header>
        <div className="flex-1 overflow-y-auto p-4 scrollbar-subtle sm:p-6">{children}</div>
      </section>
    </div>
  );
}

