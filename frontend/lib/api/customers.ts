import { apiRequest, apiRequestWithMeta } from "@/lib/api";
import type { ApiMeta, Customer } from "@/lib/types";

export interface CustomerListParams {
  search?: string;
  page?: number;
  per_page?: number;
}

export interface CustomerListResult {
  data: Customer[];
  meta: ApiMeta;
}

export interface CustomerPayload {
  code?: string;
  name: string;
  phone?: string | null;
  email?: string | null;
  address?: string | null;
  is_active?: boolean;
}

export const customerApi = {
  async list(params?: CustomerListParams): Promise<CustomerListResult> {
    const res = await apiRequestWithMeta<Customer[]>("/customers", {
      query: {
        search: params?.search,
        page: params?.page,
        per_page: params?.per_page ?? 25,
      },
    });
    return { data: res.data, meta: res.meta };
  },

  get(id: number): Promise<Customer> {
    return apiRequest<Customer>(`/customers/${id}`);
  },

  create(data: CustomerPayload): Promise<Customer> {
    return apiRequest<Customer>("/customers", {
      method: "POST",
      body: data,
    });
  },

  update(id: number, data: Partial<CustomerPayload>): Promise<Customer> {
    return apiRequest<Customer>(`/customers/${id}`, {
      method: "PUT",
      body: data,
    });
  },

  archive(id: number): Promise<Customer> {
    return apiRequest<Customer>(`/customers/${id}/archive`, {
      method: "POST",
    });
  },
};
