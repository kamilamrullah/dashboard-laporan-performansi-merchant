import { UserManagementModal } from './UserManagementModal';

interface Props { onBack: () => void; }

// Menempatkan pengelolaan user sebagai halaman utama sambil mempertahankan dialog aksi tambah/edit/reset.
export function UserManagementPage({ onBack }: Props) {
  return <main className="min-h-[calc(100vh-5rem)] bg-slate-50 px-4 py-6 sm:px-6 lg:px-8"><div className="mx-auto mb-4 max-w-6xl"><button onClick={onBack} className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-xs font-bold text-slate-600 hover:bg-slate-100">← Kembali ke Dashboard</button></div><div className="user-management-page-mode mx-auto max-w-6xl"><UserManagementModal open onClose={onBack}/></div></main>;
}
