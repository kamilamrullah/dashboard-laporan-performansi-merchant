export interface DashboardFiltersState {
  dateFrom: string;
  dateTo: string;
  merchantId: string;
  partnerChannel: string;
  paymentChannel: string;
  transactionType: string;
  responseCode: string;
}

export interface DashboardSummary {
  total_trx: string;
  total_inquiry: string;
  total_payment: string;
  payment_amount: string;
  aggregate_rows: number;
  period_start: string | null;
  period_end: string | null;
}

export interface DailyPerformance { transaction_date: string; inquiry: string; payment: string; payment_amount: string; }
export interface PartnerPerformance { name: string; total_trx: string; inquiry: string; payment: string; payment_amount: string; }
export interface PaymentChannelPerformance { sic_code: string; name: string; total_trx: string; total_amount: string; }
export interface ResponseCodePerformance { code: string; total_trx: string; inquiry: string; payment: string; }
export interface MerchantOption { id: number; merchant_code: string; merchant_name: string; }

export interface DashboardOptions {
  merchants: MerchantOption[];
  partner_channels: string[];
  payment_channels: string[];
  transaction_types: string[];
  response_codes: string[];
  available_period: { date_from: string | null; date_to: string | null };
}

export interface DashboardData {
  summary: DashboardSummary;
  daily: DailyPerformance[];
  partners: PartnerPerformance[];
  payment_channels: PaymentChannelPerformance[];
  response_codes: ResponseCodePerformance[];
  options: DashboardOptions;
}

export type TransactionImportOutcome = 'READY' | 'CHANGED' | 'DUPLICATE_IN_FILE' | 'DUPLICATE_DATABASE' | 'CONFLICT_IN_FILE' | 'INVALID';

export interface TransactionImportData {
  source_row_number: number;
  transaction_date: string;
  datasource: string;
  transaction_type: string;
  ca_id: string;
  partner_channel: string;
  biller: string;
  sic_code: string;
  response_code: string;
  total_trx: number;
  total_amount: string;
  source_batch_id?: number;
}

export interface TransactionImportRow {
  id: number;
  source_row_number: number;
  outcome: TransactionImportOutcome;
  changed_fields: string[];
  payment_channel: string | null;
  data: TransactionImportData | null;
  existing: TransactionImportData | null;
  errors: { message: string } | null;
}

export interface TransactionImportPreview {
  status: 'PREVIEWED';
  batch_id: number;
  confirmation_token: string;
  confirmation_expires_at: string;
  period_start: string | null;
  period_end: string | null;
  summary: { total: number; ready: number; changed: number; duplicate_in_file: number; duplicate_database: number; conflict_in_file: number; invalid: number };
  rows: TransactionImportRow[];
  visible_rows: number;
  rows_truncated: boolean;
}

export interface TransactionImportResult {
  status: 'COMPLETED';
  batch_id: number;
  inserted: number;
  updated: number;
  duplicate: number;
  rejected: number;
}
