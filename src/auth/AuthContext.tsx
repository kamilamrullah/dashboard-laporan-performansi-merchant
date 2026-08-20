import { createContext, useContext } from 'react';
import type { AuthUser } from '../services/authApi';

interface AuthContextValue { user: AuthUser; logout: () => Promise<void>; openUserManagement: () => void; }
export const AuthContext = createContext<AuthContextValue | null>(null);

// Mengambil session user dari provider dan menolak penggunaan di luar area terautentikasi.
export function useAuth(): AuthContextValue {
  const value = useContext(AuthContext);
  if (!value) throw new Error('AuthContext tidak tersedia.');
  return value;
}
