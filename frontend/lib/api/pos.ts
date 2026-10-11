// Modul domain: POS — katalog produk, quote, checkout, struk
import { apiRequest, apiRequestWithMeta, uuidv4 } from "../api";
import type {
  CheckoutSaleRequest,
  PaymentMethod,
  ProductCashier,
  Quote,
  QuoteRequest,
  ReceiptPayload,
  Sale,
  SaleListItem,
} from "../types";

export interface ProductSearchParams {
  search?: string;
  barcode?: string;
  category_id?: number;
  active_only?: boolean;
  low_stock?: boolean;
  per_page?: number;
  page?: number;
}

export const productApi = {
  /** GET /api/v1/products — pencarian SKU/barcode/nama. Kasir tidak melihat harga modal. */
  search: (params: ProductSearchParams, signal?: AbortSignal) =>
    apiRequestWithMeta<ProductCashier[]>("/products", {
      query: { active_only: true, ...params },
      signal,
    }),

  get: (id: number) => apiRequest<ProductCashier>(`/products/${id}`),
};

export const checkoutApi = {
  /** POST /api/v1/checkout/quotes — hitung quote server-side (berlaku 120 detik). */
  createQuote: (payload: QuoteRequest) =>
    apiRequest<Quote>("/checkout/quotes", { method: "POST", body: payload }),

  /** GET /api/v1/checkout/recovery/{key} — pemulihan quote bila jaringan terputus. */
  recoverQuote: (key: string) => apiRequest<Quote>(`/checkout/recovery/${key}`),
};

export interface CheckoutInput {
  quoteId: string;
  paymentMethod: PaymentMethod;
  tenderedAmount?: number | null;
  paymentReference?: string | null;
  /** Kunci idempotensi; bila kosong akan dibuat UUID baru. Simpan untuk retry. */
  idempotencyKey?: string;
}

export const saleApi = {
  /**
   * POST /api/v1/sales — finalisasi checkout dari quote server.
   * Payload idempotent: kirim `Idempotency-Key` header DAN `idempotency_key` body.
   */
  checkout: (input: CheckoutInput) => {
    const idempotencyKey = input.idempotencyKey ?? uuidv4();
    const body: CheckoutSaleRequest = {
      quote_id: input.quoteId,
      payment_method: input.paymentMethod,
      tendered_amount: input.tenderedAmount ?? null,
      payment_reference: input.paymentReference ?? null,
      idempotency_key: idempotencyKey,
    };
    return apiRequest<Sale>("/sales", {
      method: "POST",
      body,
      idempotencyKey,
    });
  },

  list: (params: { per_page?: number; page?: number } = {}) =>
    apiRequestWithMeta<SaleListItem[]>("/sales", { query: params }),

  get: (id: number) => apiRequest<Sale>(`/sales/${id}`),

  /** GET /api/v1/sales/{id}/receipt — data struk siap cetak. */
  receipt: (id: number) => apiRequest<ReceiptPayload>(`/sales/${id}/receipt`),

  /** POST /api/v1/sales/{id}/reprints — catat cetak ulang (ditandai SALINAN). */
  reprint: (id: number, reason?: string) =>
    apiRequest<ReceiptPayload>(`/sales/${id}/reprints`, {
      method: "POST",
      body: { reason: reason ?? null },
    }),
};