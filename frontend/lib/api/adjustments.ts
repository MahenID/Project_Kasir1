import { apiRequest, apiRequestWithMeta } from "@/lib/api";
import type {
  ApiMeta,
  InventoryAdjustmentDetail,
  InventoryAdjustmentRow,
  InventoryAdjustmentStatus,
  InventoryAdjustmentType,
} from "@/lib/types";

export interface AdjustmentListParams {
  page?: number;
  per_page?: number;
  type?: InventoryAdjustmentType;
  status?: InventoryAdjustmentStatus;
}

export interface AdjustmentListResult {
  data: InventoryAdjustmentRow[];
  meta: ApiMeta;
}

export interface CreateAdjustmentPayload {
  type: InventoryAdjustmentType;
  reason: string;
  evidence_reference?: string | null;
  items: Array<{
    product_id: number;
    quantity_delta?: number;
    new_average_cost?: number;
  }>;
}

export const adjustmentsApi = {
  /**
   * Mengambil daftar dokumen penyesuaian stok / revaluasi.
   */
  async list(params?: AdjustmentListParams): Promise<AdjustmentListResult> {
    const res = await apiRequestWithMeta<InventoryAdjustmentRow[]>(
      "/inventory-adjustments",
      {
        query: {
          page: params?.page,
          per_page: params?.per_page ?? 25,
          type: params?.type,
          status: params?.status,
        },
      }
    );
    return { data: res.data, meta: res.meta };
  },

  /**
   * Mengambil rincian lengkap dokumen penyesuaian.
   */
  async get(id: number): Promise<InventoryAdjustmentDetail> {
    return apiRequest<InventoryAdjustmentDetail>(`/inventory-adjustments/${id}`);
  },

  /**
   * Membuat draft dokumen penyesuaian stok baru.
   */
  async create(data: CreateAdjustmentPayload): Promise<InventoryAdjustmentDetail> {
    return apiRequest<InventoryAdjustmentDetail>("/inventory-adjustments", {
      method: "POST",
      body: data,
    });
  },

  /**
   * Memposting penyesuaian stok secara atomik ke kartu stok/HPP.
   */
  async post(id: number): Promise<InventoryAdjustmentDetail> {
    return apiRequest<InventoryAdjustmentDetail>(
      `/inventory-adjustments/${id}/post`,
      {
        method: "POST",
      }
    );
  },

  /**
   * Membatalkan draft penyesuaian stok.
   */
  async cancel(id: number): Promise<InventoryAdjustmentDetail> {
    return apiRequest<InventoryAdjustmentDetail>(
      `/inventory-adjustments/${id}/cancel`,
      {
        method: "POST",
      }
    );
  },
};
