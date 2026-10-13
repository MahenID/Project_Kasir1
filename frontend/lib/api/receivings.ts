import { apiRequest, apiRequestWithMeta } from "@/lib/api";
import type {
  ApiMeta,
  ReceivingDetail,
  ReceivingRow,
  ReceivingType,
} from "@/lib/types";

export interface ReceivingListParams {
  status?: string;
  page?: number;
  per_page?: number;
}

export interface ReceivingListResult {
  data: ReceivingRow[];
  meta: ApiMeta;
}

export interface CreateReceivingPayload {
  type: ReceivingType;
  supplier_id?: number | null;
  external_reference?: string | null;
  received_at?: string | null;
  items?: Array<{
    product_id: number;
    quantity: number;
    unit_cost: number;
  }>;
}

export interface UpdateLinesPayload {
  items: Array<{
    product_id: number;
    quantity: number;
    unit_cost: number;
  }>;
}

export const receivingApi = {
  async list(params?: ReceivingListParams): Promise<ReceivingListResult> {
    const res = await apiRequestWithMeta<ReceivingRow[]>("/receivings", {
      query: {
        status: params?.status,
        page: params?.page,
        per_page: params?.per_page ?? 20,
      },
    });
    return { data: res.data, meta: res.meta };
  },

  get(id: number): Promise<ReceivingDetail> {
    return apiRequest<ReceivingDetail>(`/receivings/${id}`);
  },

  create(data: CreateReceivingPayload): Promise<ReceivingDetail> {
    return apiRequest<ReceivingDetail>("/receivings", {
      method: "POST",
      body: data,
    });
  },

  updateLines(id: number, data: UpdateLinesPayload): Promise<ReceivingDetail> {
    return apiRequest<ReceivingDetail>(`/receivings/${id}/lines`, {
      method: "PUT",
      body: data,
    });
  },

  approve(id: number): Promise<ReceivingDetail> {
    return apiRequest<ReceivingDetail>(`/receivings/${id}/approve`, {
      method: "POST",
    });
  },

  post(id: number): Promise<ReceivingDetail> {
    return apiRequest<ReceivingDetail>(`/receivings/${id}/post`, {
      method: "POST",
    });
  },

  cancel(id: number, notes?: string): Promise<ReceivingDetail> {
    return apiRequest<ReceivingDetail>(`/receivings/${id}/cancel`, {
      method: "POST",
      body: { notes: notes || null },
    });
  },
};
