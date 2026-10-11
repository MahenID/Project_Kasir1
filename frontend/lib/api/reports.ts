// Modul domain: Laporan & Ekspor (owner/manager)
import { apiRequest, apiRequestWithMeta } from "../api";
import type {
  AuditEvent,
  ExportFormat,
  ExportJob,
  ExportType,
  ReceivingReportRow,
  ReturnsReportRow,
  SalesReport,
  ShiftDifferenceRow,
  StockReportRow,
  TopProductRow,
} from "../types";

export interface ReportDateParams {
  from?: string; // YYYY-MM-DD
  to?: string; // YYYY-MM-DD
  limit?: number;
  [key: string]: string | number | undefined;
}

export const reportApi = {
  sales: (params: ReportDateParams = {}) =>
    apiRequest<SalesReport>("/reports/sales", { query: params }),

  topProducts: (params: ReportDateParams = {}) =>
    apiRequest<TopProductRow[]>("/reports/top-products", { query: params }),

  stock: (params: { low_stock_only?: boolean } = {}) =>
    apiRequest<StockReportRow[]>("/reports/stock", { query: params }),

  shiftDifferences: (params: ReportDateParams = {}) =>
    apiRequest<ShiftDifferenceRow[]>("/reports/shift-differences", { query: params }),

  receiving: (params: ReportDateParams = {}) =>
    apiRequest<ReceivingReportRow[]>("/reports/receiving", { query: params }),

  returns: (params: ReportDateParams = {}) =>
    apiRequest<ReturnsReportRow[]>("/reports/returns", { query: params }),
};

export const exportApi = {
  list: (params: { per_page?: number; page?: number } = {}) =>
    apiRequestWithMeta<ExportJob[]>("/exports", { query: params }),

  /** POST /api/v1/exports — request ekspor baru (latar belakang / queue). */
  create: (payload: { type: ExportType; format: ExportFormat; filters?: Record<string, unknown> }) =>
    apiRequest<ExportJob>("/exports", { method: "POST", body: payload }),

  get: (id: number) => apiRequest<ExportJob>(`/exports/${id}`),

  /** URL langsung untuk unduh file artefak (re-autentikasi via cookie). */
  downloadUrl: (id: number) => `/api/v1/exports/${id}/download`,
};

export const auditApi = {
  list: (params: { log_name?: string; per_page?: number; page?: number } = {}) =>
    apiRequestWithMeta<AuditEvent[]>("/audit-events", { query: params }),

  get: (id: number) => apiRequest<AuditEvent>(`/audit-events/${id}`),
};
