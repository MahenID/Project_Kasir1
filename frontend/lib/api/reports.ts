// Modul domain: Laporan & Ekspor (owner/manager)
import { apiRequest, apiRequestWithMeta } from "../api";
import type {
  AuditEvent,
  ExportFormat,
  ExportJob,
  ExportReportType,
  ReceivingReport,
  ReturnsReport,
  SalesReport,
  ShiftDifferencesReport,
  StockReport,
  TopProductsReport,
} from "../types";

export interface ReportDateParams {
  start_date?: string; // YYYY-MM-DD
  end_date?: string; // YYYY-MM-DD
  timezone?: string;
  limit?: number;
  sort?: "units" | "value";
  [key: string]: string | number | undefined;
}

export interface CreateExportPayload {
  report_type: ExportReportType;
  format: ExportFormat;
  start_date?: string;
  end_date?: string;
  timezone?: string;
  sort?: "units" | "value";
}

export const reportApi = {
  sales: (params: ReportDateParams = {}) =>
    apiRequest<SalesReport>("/reports/sales", { query: params }),

  topProducts: (params: ReportDateParams = {}) =>
    apiRequest<TopProductsReport>("/reports/top-products", { query: params }),

  stock: (params: { limit?: number } = {}) =>
    apiRequest<StockReport>("/reports/stock", { query: params }),

  shiftDifferences: (params: ReportDateParams = {}) =>
    apiRequest<ShiftDifferencesReport>("/reports/shift-differences", { query: params }),

  receiving: (params: ReportDateParams = {}) =>
    apiRequest<ReceivingReport>("/reports/receiving", { query: params }),

  returns: (params: ReportDateParams = {}) =>
    apiRequest<ReturnsReport>("/reports/returns", { query: params }),
};

export const exportApi = {
  list: (params: { per_page?: number; page?: number; status?: string } = {}) =>
    apiRequestWithMeta<ExportJob[]>("/exports", { query: params }),

  /** POST /api/v1/exports — request ekspor baru (latar belakang / queue). */
  create: (payload: CreateExportPayload) =>
    apiRequest<ExportJob>("/exports", { method: "POST", body: payload }),

  get: (id: number) => apiRequest<ExportJob>(`/exports/${id}`),

  /** URL langsung untuk unduh file artefak (re-autentikasi via cookie). */
  downloadUrl: (id: number) => `/api/v1/exports/${id}/download`,
};

export const auditApi = {
  list: (params: { log_name?: string; event?: string; per_page?: number; page?: number } = {}) =>
    apiRequestWithMeta<AuditEvent[]>("/audit-events", { query: params }),

  get: (id: number) => apiRequest<AuditEvent>(`/audit-events/${id}`),
};
