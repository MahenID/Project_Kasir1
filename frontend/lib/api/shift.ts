// Modul domain: Shift — buka/tutup shift & pergerakan kas
import { apiRequest, uuidv4 } from "../api";
import type {
  CashMovement,
  ShiftCloseResponse,
  ShiftCurrentResponse,
  Terminal,
} from "../types";

export const shiftApi = {
  /** GET /api/v1/shifts/current — shift aktif + laporan X (jual-beli berjalan). */
  current: () => apiRequest<ShiftCurrentResponse | null>("/shifts/current"),

  /** POST /api/v1/shifts/open — buka shift baru. */
  open: (payload: { terminal_id: number; starting_cash: number; notes?: string | null }) =>
    apiRequest("/shifts/open", { method: "POST", body: payload }),

  /** POST /api/v1/shifts/close — tutup shift (variance perlu penjelasan). */
  close: (payload: { actual_cash: number; explanation?: string | null }) =>
    apiRequest<ShiftCloseResponse>("/shifts/close", { method: "POST", body: payload }),

  /** POST /api/v1/shifts/{id}/cash-movements — kas in/out (memerlukan peran supervisor). */
  recordCashMovement: (
    shiftId: number,
    payload: { type: "cash_in" | "cash_out"; amount: number; reason: string },
    idempotencyKey?: string
  ) =>
    apiRequest<{ movement: CashMovement }>(`/shifts/${shiftId}/cash-movements`, {
      method: "POST",
      body: payload,
      idempotencyKey: idempotencyKey ?? uuidv4(),
    }),

  /** GET /api/v1/shifts/{id} — detail shift tertentu. */
  show: (id: number) => apiRequest(`/shifts/${id}`),
};

export const terminalApi = {
  /**
   * Catatan implementasi: backend belum memiliki route `GET /api/v1/terminals`.
   * Fallback sementara menggunakan terminal id 1 (default seeder TerminalSeeder).
   */
  list: async (): Promise<Terminal[]> => [
    { id: 1, code: "POS-01", name: "Kasir Utama 01", is_active: true },
    { id: 2, code: "POS-02", name: "Kasir 02", is_active: true },
  ],
};
