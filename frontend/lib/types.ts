// ============================================================================
// DragonMart POS — Kontrak API (cermin backend Laravel, branch fase/backend)
// Semua nilai uang adalah integer rupiah. Jangan pernah memakai float JS
// sebagai otoritas moneter.
// ============================================================================

export type ISODateString = string;

// ---------- Envelope respons ----------
export interface ApiMeta {
  request_id: string;
  pagination?: Pagination;
  [key: string]: unknown;
}

export interface Pagination {
  current_page: number;
  per_page: number;
  total: number;
  last_page: number;
}

export interface ApiSuccess<T> {
  success: true;
  data: T;
  message: string;
  meta: ApiMeta;
}

export interface ApiErrorBody {
  success: false;
  error: {
    code: string;
    message: string;
    fields: Record<string, string[] | string>;
  };
  meta: ApiMeta;
}

export type ApiEnvelope<T> = ApiSuccess<T> | ApiErrorBody;

// ---------- Auth / Pengguna ----------
export type Role = "owner" | "manager" | "cashier";

export interface CurrentShiftSummary {
  id: number;
  shift_number: string;
  terminal_id: number;
  terminal_code: string | null;
  opened_at: ISODateString | null;
  starting_cash: number; // integer rupiah
}

export interface User {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  is_active: boolean;
  must_change_password: boolean;
  roles: Role[];
  permissions: string[];
  current_shift: CurrentShiftSummary | null;
}

// ---------- Produk ----------
export interface ProductCashier {
  id: number;
  sku: string;
  barcode: string | null;
  name: string;
  unit: string;
  category_id: number | null;
  category_name?: string | null;
  selling_price: number; // integer rupiah
  stock: number;
  min_stock?: number;
  is_active: boolean;
  commercial_version: number;
  // Hanya untuk owner/manager — TIDAK PERNAH dirender untuk kasir:
  average_cost?: number;
  margin_percent?: number;
}

// ---------- Quote ----------
export type DiscountType = "percent" | "fixed";

export interface QuoteRequestItem {
  product_id: number;
  quantity: number;
  discount_type?: DiscountType | null;
  discount_value?: number | null;
}

export interface QuoteRequest {
  items: QuoteRequestItem[];
  sale_discount_type?: DiscountType | null;
  sale_discount_value?: number | null;
  customer_id?: number | null;
}

export interface QuoteLine {
  product_id: number;
  sku: string;
  name: string;
  unit: string;
  quantity: number;
  unit_price: number;
  discount_type: DiscountType | null;
  discount_value: number | null;
  discount_amount: number;
  line_subtotal: number; // setelah diskon baris
  is_available: boolean;
  stock_available: number;
}

export interface QuoteCalculation {
  items: QuoteLine[];
  item_count: number;
  total_quantity: number;
  subtotal: number; // sebelum diskon faktur
  sale_discount_type: DiscountType | null;
  sale_discount_value: number | null;
  sale_discount_amount: number;
  tax_enabled: boolean;
  tax_amount: number;
  grand_total: number;
}

export interface CustomerSnapshot {
  id: number;
  code: string;
  name: string;
  phone: string | null;
}

export interface Quote {
  quote_id: string; // uuid
  expires_at: ISODateString;
  expires_in_seconds: number; // 120
  shift: { id: number; shift_number: string };
  customer: CustomerSnapshot | null;
  calculation: QuoteCalculation;
}

// ---------- Sale (checkout) ----------
export type PaymentMethod = "cash" | "debit" | "credit" | "qris" | "transfer";

export interface CheckoutSaleRequest {
  quote_id: string;
  payment_method: PaymentMethod;
  tendered_amount?: number | null; // wajib untuk cash
  payment_reference?: string | null; // wajib untuk non-cash manual
  idempotency_key: string; // min 8, max 128
}

export interface SaleItem {
  id: number;
  product_id: number;
  sku: string;
  name: string;
  unit: string;
  quantity: number;
  unit_price: number;
  discount_amount: number;
  line_subtotal: number;
}

export interface SalePayment {
  method: PaymentMethod;
  amount: number;
  tendered_amount: number | null;
  change_amount: number | null;
  reference: string | null;
}

export type SaleStatus = "completed" | "voided";
export type ReturnStatus = "none" | "partial" | "full";

export interface Sale {
  id: number;
  receipt_number: string;
  status: SaleStatus;
  return_status: ReturnStatus;
  completed_at: ISODateString | null;
  cashier_name: string;
  customer: CustomerSnapshot | null;
  items: SaleItem[];
  subtotal: number;
  sale_discount_amount: number;
  tax_amount: number;
  grand_total: number;
  payment: SalePayment;
}

export interface SaleListItem {
  id: number;
  receipt_number: string;
  status: SaleStatus;
  return_status: ReturnStatus;
  completed_at: ISODateString | null;
  cashier_name: string;
  grand_total: number;
  payment_method: PaymentMethod | null;
}

// ---------- Struk (receipt) ----------
export interface ReceiptPayload {
  sale_id: number;
  receipt_number: string;
  printed_at: ISODateString;
  store_name?: string;
  store_address?: string;
  store_phone?: string;
  cashier_name: string;
  customer_name: string | null;
  items: Array<{
    name: string;
    quantity: number;
    unit: string;
    unit_price: number;
    discount_amount: number;
    line_subtotal: number;
  }>;
  subtotal: number;
  sale_discount_amount: number;
  tax_amount: number;
  grand_total: number;
  payment: SalePayment;
  is_copy?: boolean;
  copy_label?: string;
  reprint?: {
    id: number;
    reprinted_by_name: string;
    reason: string | null;
    reprinted_at: ISODateString;
  };
}

// ---------- Shift ----------
export interface Shift {
  id: number;
  shift_number: string;
  user_id: number;
  terminal_id: number;
  terminal_code: string | null;
  status: "open" | "closed";
  opened_at: ISODateString | null;
  starting_cash: number;
}

export interface XReport {
  opening_cash: number;
  cash_sales: number;
  non_cash_sales: number;
  cash_in: number;
  cash_out: number;
  expected_cash: number;
  transaction_count: number;
  gross_sales: number;
  total_discount: number;
  total_tax: number;
  net_sales: number;
  refund_total: number;
  by_payment_method: Array<{ method: PaymentMethod; count: number; total: number }>;
}

export interface ZReport extends XReport {
  actual_cash: number;
  difference: number;
  closed_at: ISODateString;
  explanation: string | null;
}

export interface ShiftCloseResponse {
  shift: {
    id: number;
    shift_number: string;
    status: "closed";
    opened_at: ISODateString | null;
    closed_at: ISODateString | null;
    starting_cash: number;
    expected_cash: number;
    actual_cash: number;
    difference: number;
    explanation: string | null;
  };
  z_report: ZReport;
}

export interface ShiftCurrentResponse {
  shift: Shift;
  x_report: XReport;
}

export interface CashMovement {
  id: number;
  shift_id: number;
  type: "cash_in" | "cash_out";
  amount: number;
  reason: string;
  posted_at: ISODateString | null;
}

// ---------- Terminal ----------
export interface Terminal {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
}

// ---------- Retur ----------
export type ReturnDisposition = "restockable" | "damaged";
export type ReturnRequestStatus =
  | "requested"
  | "approved"
  | "rejected"
  | "cancelled"
  | "completed";

export interface ReturnLine {
  sale_item_id: number;
  quantity: number;
  disposition: ReturnDisposition;
}

export interface ReturnItemPayload {
  id: number;
  sale_item_id: number;
  product_name: string;
  quantity: number;
  disposition: ReturnDisposition;
  unit_price: number;
  refund_amount: number;
}

export interface Refund {
  id: number;
  method: PaymentMethod;
  amount: number;
  reference: string | null;
  processed_at: ISODateString | null;
}

export interface ReturnRequestPayload {
  id: number;
  return_number: string;
  sale_id: number;
  receipt_number: string;
  status: ReturnRequestStatus;
  reason: string;
  override_reason: string | null;
  items: ReturnItemPayload[];
  total_refund: number;
  requested_by_name: string;
  requested_at: ISODateString;
  approved_by_name: string | null;
  approved_at: ISODateString | null;
  completed_by_name: string | null;
  completed_at: ISODateString | null;
  rejected_reason: string | null;
  cancelled_reason: string | null;
  refund: Refund | null;
}

export interface ReturnLookupResult {
  sale: {
    id: number;
    receipt_number: string;
    completed_at: ISODateString | null;
    return_status: ReturnStatus;
  };
  items: Array<{
    sale_item_id: number;
    product_name: string;
    quantity_sold: number;
    quantity_returned: number;
    quantity_returnable: number;
    unit_price: number;
  }>;
}

export interface CompleteReturnRequest {
  shift_id: number;
  confirmed_cash_amount: number; // wajib jika refund tunai
  refund_reference?: string | null; // wajib jika non-tunai
  idempotency_key: string;
}

// ---------- Laporan ----------
export interface SalesReport {
  from: string;
  to: string;
  gross_sales: number;
  total_discount: number;
  total_tax: number;
  net_sales: number;
  cogs: number;
  gross_profit: number;
  transaction_count: number;
  average_transaction: number;
}

export interface TopProductRow {
  product_id: number;
  sku: string;
  name: string;
  quantity_sold: number;
  revenue: number;
  gross_profit?: number; // hanya owner/manager
}

export interface StockReportRow {
  product_id: number;
  sku: string;
  name: string;
  unit: string;
  stock: number;
  min_stock: number;
  stock_value?: number; // hanya owner/manager (average_cost * stock)
}

export interface ShiftDifferenceRow {
  shift_id: number;
  shift_number: string;
  cashier_name: string;
  terminal_code: string;
  opened_at: ISODateString;
  closed_at: ISODateString | null;
  expected_cash: number;
  actual_cash: number | null;
  difference: number | null;
  explanation: string | null;
}

export interface ReceivingReportRow {
  receiving_id: number;
  receiving_number: string;
  supplier_name: string;
  posted_at: ISODateString | null;
  line_count: number;
  total_quantity: number;
  total_cost?: number; // owner/manager only
}

export interface ReturnsReportRow {
  return_id: number;
  return_number: string;
  receipt_number: string;
  status: ReturnRequestStatus;
  total_refund: number;
  restockable_value: number;
  damaged_value: number;
  completed_at: ISODateString | null;
}

// ---------- Ekspor ----------
export type ExportFormat = "xlsx" | "pdf";
export type ExportType =
  | "sales"
  | "top-products"
  | "stock"
  | "shift-differences"
  | "receiving"
  | "returns";

export type ExportStatus = "queued" | "processing" | "completed" | "failed";

export interface ExportJob {
  id: number;
  type: ExportType;
  format: ExportFormat;
  status: ExportStatus;
  filters: Record<string, unknown>;
  artifact_name: string | null;
  artifact_size_bytes: number | null;
  error_message: string | null;
  requested_by_name: string;
  created_at: ISODateString;
  completed_at: ISODateString | null;
  expires_at: ISODateString | null;
}

// ---------- Audit ----------
export interface AuditEvent {
  id: number;
  log_name: string;
  description: string;
  subject_type: string | null;
  subject_id: number | null;
  causer_type: string | null;
  causer_id: number | null;
  causer_name: string | null;
  properties: Record<string, unknown>;
  ip_address: string | null;
  created_at: ISODateString;
}

// ---------- Master data ringkas ----------
export interface Category {
  id: number;
  name: string;
  is_active: boolean;
}

export interface Customer {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  is_active: boolean;
}

export interface Supplier {
  id: number;
  code: string;
  name: string;
  is_active: boolean;
}
