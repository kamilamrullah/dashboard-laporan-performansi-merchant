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
  original_filename: string;
  confirmation_token: string;
  confirmation_expires_at: string;
  period_start: string | null;
  period_end: string | null;
  summary: TransactionImportSummary;
  rows: TransactionImportRow[];
  pagination: PaginationMeta;
}

export interface TransactionImportPreviewPage { items: TransactionImportRow[]; pagination: PaginationMeta; }

export interface TransactionImportSummary { total: number; ready: number; changed: number; duplicate_in_file: number; duplicate_database: number; conflict_in_file: number; invalid: number; }

export interface TransactionImportResult {
  status: 'COMPLETED';
  batch_id: number;
  inserted: number;
  updated: number;
  duplicate: number;
  rejected: number;
}

export interface PaginationMeta { page: number; per_page: number; total: number; total_pages: number; }

export interface TransactionImportBatch {
  id: number;
  original_filename: string;
  file_sha256?: string;
  status: string;
  detected_period_start: string | null;
  detected_period_end: string | null;
  total_rows: number;
  valid_rows: number;
  inserted_rows: number;
  updated_rows: number;
  duplicate_rows: number;
  rejected_rows: number;
  failure_message?: string | null;
  imported_by: string | null;
  confirmation_expires_at: string | null;
  confirmed_at: string | null;
  completed_at: string | null;
  created_at: string;
  merchant_id: number | null;
  merchant_name: string | null;
}

export interface TransactionImportHistoryRow {
  id: number;
  source_row_number: number;
  outcome: string;
  normalized_data: TransactionImportData | null;
  existing_data: TransactionImportData | null;
  validation_errors: { message?: string } | null;
  created_at: string;
}

export interface TransactionImportHistory {
  items: TransactionImportBatch[];
  pagination: PaginationMeta;
}

export interface TransactionImportBatchDetail {
  batch: TransactionImportBatch;
  summary: TransactionImportSummary;
  rows: { items: TransactionImportHistoryRow[]; pagination: PaginationMeta };
}

export interface TransactionPreviewDeleteResult { status: 'DELETED'; batch_id: number; }

export type TicketImportOutcome = TransactionImportOutcome;

export interface TicketImportData {
  source_row_number: number;
  merchant_id?: number;
  ticket_number: string;
  status: string;
  complaint_segment: string;
  opened_at: string;
  closed_at: string | null;
  last_updated_at: string | null;
  duration_raw: string | null;
  duration_minutes: number | null;
  response_time_minutes: number | null;
  classification_flag: string;
  validation_warnings?: string[];
  source_batch_id?: number;
}

export interface TicketImportRow {
  id: number;
  source_row_number: number;
  outcome: TicketImportOutcome;
  changed_fields: string[];
  warnings: string[];
  data: TicketImportData | null;
  existing: TicketImportData | null;
  errors: { message: string } | null;
}

export interface TicketImportPreview {
  status: 'PREVIEWED';
  batch_id: number;
  original_filename: string;
  confirmation_token: string;
  confirmation_expires_at: string;
  period_start: string | null;
  period_end: string | null;
  summary: TransactionImportSummary;
  segment_summary: TicketSegmentSummary[];
  rows: TicketImportRow[];
  pagination: PaginationMeta;
}

export interface TicketImportPreviewPage { items: TicketImportRow[]; pagination: PaginationMeta; }
export type TicketImportResult = TransactionImportResult;
export type TicketImportBatch = TransactionImportBatch;
export interface TicketSegmentSummary { complaint_segment: string; total: number; }

export interface TicketImportHistoryRow {
  id: number;
  source_row_number: number;
  outcome: string;
  normalized_data: TicketImportData | null;
  existing_data: TicketImportData | null;
  validation_errors: { message?: string } | null;
  created_at: string;
}

export interface TicketImportHistory { items: TicketImportBatch[]; pagination: PaginationMeta; }

export interface TicketImportBatchDetail {
  batch: TicketImportBatch;
  summary: TransactionImportSummary;
  segment_summary: TicketSegmentSummary[];
  rows: { items: TicketImportHistoryRow[]; pagination: PaginationMeta };
}

export type TicketPreviewDeleteResult = TransactionPreviewDeleteResult;

export interface PaymentChannelMasterItem {
  sic_code: string;
  channel_name: string;
  is_active: number;
  aggregate_rows: number;
  total_trx: string;
  created_at: string;
  updated_at: string;
}

export interface PaymentChannelMasterList {
  items: PaymentChannelMasterItem[];
  unmapped_sic_count: number;
  pagination: PaginationMeta;
}

export interface PaymentChannelChangeHistory {
  id: number;
  action: string;
  old_channel_name: string | null;
  new_channel_name: string | null;
  old_is_active: number | null;
  new_is_active: number | null;
  changed_by: string | null;
  created_at: string;
}
