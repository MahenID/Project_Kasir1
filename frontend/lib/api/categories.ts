import { apiRequest } from "@/lib/api";
import type { Category } from "@/lib/types";

export interface CategoryListParams {
  search?: string;
  active_only?: boolean;
}

export interface CategoryPayload {
  name: string;
  is_active?: boolean;
}

export const categoryApi = {
  list(params?: CategoryListParams): Promise<Category[]> {
    return apiRequest<Category[]>("/categories", {
      query: {
        search: params?.search,
        active_only: params?.active_only,
      },
    });
  },

  get(id: number): Promise<Category> {
    return apiRequest<Category>(`/categories/${id}`);
  },

  create(data: CategoryPayload): Promise<Category> {
    return apiRequest<Category>("/categories", {
      method: "POST",
      body: data,
    });
  },

  update(id: number, data: Partial<CategoryPayload>): Promise<Category> {
    return apiRequest<Category>(`/categories/${id}`, {
      method: "PUT",
      body: data,
    });
  },

  archive(id: number): Promise<Category> {
    return apiRequest<Category>(`/categories/${id}/archive`, {
      method: "POST",
    });
  },
};
