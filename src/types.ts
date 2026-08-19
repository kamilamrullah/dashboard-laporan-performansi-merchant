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

