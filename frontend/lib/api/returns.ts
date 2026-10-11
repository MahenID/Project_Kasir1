// Modul domain: Retur & refund
import { apiRequest, apiRequestWithMeta, uuidv4 } from "../api";
import type {
  CompleteReturnRequest,
  ReturnLine,
  ReturnLookupResult,
  ReturnRequestPayload,
} from "../types";

export interface CreateReturnInput {
  sale_id: number;
  reason: string;
  lines: ReturnLine[];
  override_reason?: string | null;
}

export interface CompleteReturnInput {
  returnId: number;
  shift_id: number;
  confirmed_cash_amount: number;
  refund_reference?: string | null;
  idempotencyKey?: string;
}

export const returnApi = {
  list: (params: { status?: string; per_page?: number; page?: number } = {}) =>
    apiRequestWithMeta<ReturnRequestPayload[]>("/return-requests", { query: params }),

  /** GET /api/v1/return-requests/lookup — cari struk yang dapat diretur. */
  lookup: (receiptNumber: string) =>
    apiRequest<ReturnLookupResult>("/return-requests/lookup", { query: { receipt_number: receiptNumber } }),

  get: (id: number) => apiRequest<ReturnRequestPayload>(`/return-requests/${id}`),

  /** POST /api/v1/return-requests — ajukan retur. */
  create: (payload: CreateReturnInput) =>
    apiRequest<ReturnRequestPayload>("/return-requests", { method: "POST", body: payload }),

  approve: (id: number) => apiRequest<ReturnRequestPayload>(`/return-requests/${id}/approve`, { method: "POST" }),

  reject: (id: number, reason: string) =>
    apiRequest<ReturnRequestPayload>(`/return-requests/${id}/reject`, { method: "POST", body: { reason } }),

  cancel: (id: number, reason: string) =>
    apiRequest<ReturnRequestPayload>(`/return-requests/${id}/cancel`, { method: "POST", body: { reason } }),

  /** POST /api/v1/return-requests/{id}/complete — proses refund idempoten. */
  complete: (input: CompleteReturnInput) => {
    const idempotencyKey = input.idempotencyKey ?? uuidv4();
    const body: CompleteReturnRequest = {
      shift_id: input.shift_id,
      confirmed_cash_amount: input.confirmed_cash_amount,
      refund_reference: input.refund_reference ?? null,
      idempotency_key: idempotencyKey,
    };
    return apiRequest<{ return_request: ReturnRequestPayload; refund: unknown }>(
      `/return-requests/${input.returnId}/complete`,
      { method: "POST", body, idempotencyKey }
    );
  },

  /** GET /api/v1/operations/refund-complete/{key} — pemulihan operasi refund. */
  recoverRefund: (key: string) =>
    apiRequest<{ return_request: ReturnRequestPayload; refund: unknown }>(
      `/operations/refund-complete/${key}`
    ),
};
