import { apiRequest, apiRequestWithMeta } from "@/lib/api";
import type { ApiMeta, Supplier } from "@/lib/types";

export interface SupplierListParams {
  search?: string;
  active_only?: boolean;
  page?: number;
  per_page?: number;
}

export interface SupplierListResult {
  data: Supplier[];
  meta: ApiMeta;
}

export interface SupplierPayload {
  code: string;
  name: string;
  contact_person?: string | null;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  is_active?: boolean;
}

export const supplierApi = {
  async list(params?: SupplierListParams): Promise<SupplierListResult> {
    const res = await apiRequestWithMeta<Supplier[]>("/suppliers", {
      query: {
        search: params?.search,
        active_only: params?.active_only,
        page: params?.page,
        per_page: params?.per_page ?? 25,
      },
    });
    return { data: res.data, meta: res.meta };
  },

  get(id: number): Promise<Supplier> {
    return apiRequest<Supplier>(`/suppliers/${id}`);
  },

  create(data: SupplierPayload): Promise<Supplier> {
    return apiRequest<Supplier>("/suppliers", {
      method: "POST",
      body: data,
    });
  },

  update(id: number, data: Partial<SupplierPayload>): Promise<Supplier> {
    return apiRequest<Supplier>(`/suppliers/${id}`, {
      method: "PUT",
      body: data,
    });
  },

  archive(id: number): Promise<Supplier> {
    return apiRequest<Supplier>(`/suppliers/${id}/archive`, {
      method: "POST",
    });
  },
};
