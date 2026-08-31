import type { DashboardData, DashboardFiltersState } from '../types';
import { apiFetch } from './apiClient';

// Mengambil data agregasi dashboard dari API menggunakan filter yang sedang aktif.
export async function fetchDashboard(filters: DashboardFiltersState, signal?: AbortSignal): Promise<DashboardData> {
  const parameters = new URLSearchParams();
  const mapping: Array<[keyof DashboardFiltersState, string]> = [
    ['period', 'period'], ['merchantId', 'merchant_id'],
    ['partnerChannel', 'partner_channel'], ['paymentChannel', 'payment_channel'],
    ['transactionType', 'transaction_type'], ['responseCode', 'response_code'],
  ];
  mapping.forEach(([stateKey, queryKey]) => {
    if (filters[stateKey]) parameters.set(queryKey, filters[stateKey]);
  });
  const response = await apiFetch(`/api/dashboard.php?${parameters.toString()}`, { signal, headers: { Accept: 'application/json' } });
  const payload = await response.json() as DashboardData & { error?: string };
  if (!response.ok) throw new Error(payload.error ?? 'Data dashboard gagal dimuat.');
  return payload;
}

// Mengambil tren 12 bulan atau drill-down harian dengan filter domain dashboard yang sama.
export async function fetchDashboardTrend(filters: DashboardFiltersState, granularity: 'monthly' | 'daily', period: string, signal?: AbortSignal): Promise<import('../types').DashboardTrendData> {
  const parameters = new URLSearchParams({ period, granularity });
  const mapping: Array<[keyof DashboardFiltersState, string]> = [
    ['merchantId', 'merchant_id'], ['partnerChannel', 'partner_channel'],
    ['paymentChannel', 'payment_channel'], ['transactionType', 'transaction_type'],
    ['responseCode', 'response_code'],
  ];
  mapping.forEach(([stateKey, queryKey]) => {
    if (filters[stateKey]) parameters.set(queryKey, filters[stateKey]);
  });
  const response = await apiFetch(`/api/dashboard-trend.php?${parameters.toString()}`, { signal, headers: { Accept: 'application/json' } });
  const payload = await response.json() as import('../types').DashboardTrendData & { error?: string };
  if (!response.ok) throw new Error(payload.error ?? 'Data tren transaksi gagal dimuat.');
  return payload;
}
