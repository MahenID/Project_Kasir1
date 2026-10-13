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
  period: {
    start: string;
    end: string;
    timezone: string;
  };
  currency: string;
  gross_profit_is_estimate: boolean;
  net_sales_excluding_tax: number;
  gross_sales_net_amount: number;
  return_net_amount: number;
  net_tax_collected: number;
  tax_collected: number;
  tax_refunded: number;
  net_cogs: number;
  estimated_gross_profit: number;
  completed_sale_count: number;
  average_transaction_value: number;
  gross_transaction_total: number;
  payments: {
    collected: Record<string, number>;
    refunded: Record<string, number>;
    net: Record<string, number>;
    total_collected: number;
    total_refunded: number;
    total_net: number;
  };
}

export interface TopProductItem {
  rank: number;
  product_id: number;
  sku: string;
  name: string;
  sold_units: number;
  returned_units: number;
  net_product_units: number;
  net_sales_value: number;
}

export interface TopProductsReport {
  period: {
    start: string;
    end: string;
    timezone: string;
  };
  sort: "units" | "value";
  items: TopProductItem[];
}

export interface StockReportItem {
  product_id: number;
  sku: string;
  name: string;
  unit: string;
  quantity_on_hand: number;
  average_cost: string;
  inventory_value: number;
  minimum_stock: number;
  is_low_stock: boolean;
  is_active: boolean;
}

export interface StockReport {
  total_products: number;
  returned_rows: number;
  total_inventory_value: number;
  valuation_basis: string;
  items: StockReportItem[];
}

export interface ShiftDifferenceItem {
  shift_id: number;
  shift_number: string;
  cashier: string | null;
  terminal: string | null;
  opened_at: string | null;
  closed_at: string | null;
  starting_cash: number;
  expected_cash: number;
  actual_cash: number;
  difference: number;
  has_explanation: boolean;
  review_status: string;
}

export interface ShiftDifferencesReport {
  period: {
    start: string;
    end: string;
    timezone: string;
  };
  closed_shift_count: number;
  total_difference: number;
  over_count: number;
  short_count: number;
  balanced_count: number;
  items: ShiftDifferenceItem[];
}

export interface ReceivingReport {
  period: {
    start: string;
    end: string;
    timezone: string;
  };
  posted_document_count: number;
  total_quantity: number;
  total_cost: number;
}

export interface ReturnsReport {
  period: {
    start: string;
    end: string;
    timezone: string;
  };
  completed_return_count: number;
  total_returned_units: number;
  restockable_units: number;
  damaged_units: number;
  total_refund_amount: number;
  refunds_by_method: Record<string, number>;
}

// ---------- Dokumen Penerimaan Barang (Receivings) ----------
export type ReceivingType = "purchase" | "opening";
export type ReceivingStatus = "draft" | "approved" | "posted" | "cancelled";

export interface ReceivingItemDetail {
  id: number;
  product_id: number;
  product_name?: string;
  product_sku?: string;
  quantity: number;
  unit_cost: number;
  extended_cost: number;
}

export interface ReceivingDetail {
  id: number;
  receiving_number: string;
  type: ReceivingType;
  status: ReceivingStatus;
  supplier_id: number | null;
  supplier: {
    id: number;
    code: string;
    name: string;
  } | null;
  external_reference: string | null;
  received_at: string | null;
  created_by: {
    id: number | null;
    name: string | null;
  };
  posted_by: {
    id: number | null;
    name: string | null;
  } | null;
  correction_notes: string | null;
  items: ReceivingItemDetail[];
  totals: {
    total_quantity: number;
    total_extended_cost: number;
  };
  created_at: string;
  updated_at: string;
}

export interface ReceivingRow {
  id: number;
  receiving_number: string;
  type: ReceivingType;
  status: ReceivingStatus;
  supplier_id: number | null;
  supplier_name: string | null;
  external_reference: string | null;
  received_at: string | null;
  created_by: string | null;
  created_at: string | null;
}

// ---------- Stocktake (Audit Stok Fisik) ----------
export type StocktakeStatus = "counting" | "posted" | "cancelled";

export interface StocktakeItem {
  id: number;
  product_id: number;
  product_name: string | null;
  product_sku: string | null;
  snapshot_stock: number;
  snapshot_average_cost: number;
  counted_stock: number | null;
  difference: number | null;
}

export interface StocktakeSummary {
  total_products: number;
  counted: number;
  with_difference: number;
  net_difference: number;
}

export interface StocktakeDetail {
  id: number;
  stocktake_number: string;
  status: StocktakeStatus;
  notes: string | null;
  started_by: {
    id: number | null;
    name: string | null;
  } | null;
  started_at: string | null;
  posted_by: {
    id: number | null;
    name: string | null;
  } | null;
  posted_at: string | null;
  cancelled_at: string | null;
  items: StocktakeItem[];
  summary: StocktakeSummary;
  created_at?: string | null;
  updated_at?: string | null;
}

export interface StocktakeRow {
  id: number;
  stocktake_number: string;
  status: StocktakeStatus;
  started_by: string | null;
  started_at: string | null;
  posted_by: string | null;
  posted_at: string | null;
  cancelled_at: string | null;
}

// ---------- Penyesuaian Stok & Revaluasi HPP ----------
export type InventoryAdjustmentType = "quantity" | "revaluation";
export type InventoryAdjustmentStatus = "draft" | "posted" | "cancelled";

export interface InventoryAdjustmentItem {
  id: number;
  product_id: number;
  product_name: string | null;
  product_sku: string | null;
  before_stock: number;
  after_stock: number;
  quantity_delta?: number;
  current_average_cost?: number;
  new_average_cost?: number;
}

export interface InventoryAdjustmentDetail {
  id: number;
  adjustment_number: string;
  type: InventoryAdjustmentType;
  status: InventoryAdjustmentStatus;
  reason: string;
  evidence_reference: string | null;
  created_by: {
    id: number | null;
    name: string | null;
  } | null;
  posted_by: {
    id: number | null;
    name: string | null;
  } | null;
  posted_at: string | null;
  items: InventoryAdjustmentItem[];
  created_at?: string | null;
  updated_at?: string | null;
}

export interface InventoryAdjustmentRow {
  id: number;
  adjustment_number: string;
  type: InventoryAdjustmentType;
  status: InventoryAdjustmentStatus;
  reason: string;
  evidence_reference: string | null;
  created_by: string | null;
  created_at: string | null;
  posted_at: string | null;
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
export type ExportReportType =
  | "sales"
  | "top-products"
  | "stock"
  | "receiving"
  | "returns"
  | "shift-differences";

export type ExportStatus = "pending" | "processing" | "completed" | "failed";

export interface ExportJob {
  id: number;
  report_type: ExportReportType;
  filters?: {
    report_type?: string;
    format?: string;
    start_date?: string;
    end_date?: string;
    timezone?: string;
    sort?: string;
    [key: string]: unknown;
  } | null;
  status: ExportStatus;
  row_count?: number | null;
  failure_code?: string | null;
  error_message?: string | null;
  download_url?: string;
  expires_at?: string | null;
  created_at?: string;
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

// ---------- Master data ringkas & detail ----------
export interface Category {
  id: number;
  name: string;
  slug?: string;
  is_active: boolean;
  products_count?: number;
  created_at?: ISODateString;
  updated_at?: ISODateString;
}

export interface Customer {
  id: number;
  code: string;
  name: string;
  phone: string | null;
  email?: string | null;
  address?: string | null;
  is_active: boolean;
  created_at?: ISODateString;
  updated_at?: ISODateString;
}

export interface Supplier {
  id: number;
  code: string;
  name: string;
  contact_person?: string | null;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  is_active: boolean;
  created_at?: ISODateString;
  updated_at?: ISODateString;
}

export interface ProductDetail {
  id: number;
  sku: string;
  barcode: string | null;
  name: string;
  description: string | null;
  unit: string;
  selling_price: number; // integer rupiah
  stock: number;
  min_stock: number;
  is_active: boolean;
  is_low_stock: boolean;
  image_url: string | null;
  category: {
    id: number;
    name: string;
    slug: string;
  } | null;
  preferred_supplier: {
    id: number;
    code: string;
    name: string;
  } | null;
  average_cost?: string; // hanya untuk owner/manager
  commercial_version?: number;
  created_at?: ISODateString;
  updated_at?: ISODateString;
}
