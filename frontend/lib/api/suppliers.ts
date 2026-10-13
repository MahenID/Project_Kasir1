import { apiRequest } from "@/lib/api";
import type { Supplier } from "@/lib/types";

export interface SupplierListParams {
  search?: string;
  active_only?: boolean;
  per_page?: number;
}

export const supplierApi = {
  list(params?: SupplierListParams): Promise<Supplier[]> {
    return apiRequest<Supplier[]>("/suppliers", {
      query: {
        search: params?.search,
        active_only: params?.active_only ?? true,
        per_page: params?.per_page ?? 100,
      },
    });
  },

  get(id: number): Promise<Supplier> {
    return apiRequest<Supplier>(`/suppliers/${id}`);
  },
};
