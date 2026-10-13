import { apiRequest, apiRequestWithMeta } from "@/lib/api";
import type { ApiMeta, ProductDetail } from "@/lib/types";

export interface ProductListParams {
  search?: string;
  category_id?: number;
  barcode?: string;
  active_only?: boolean;
  low_stock?: boolean;
  page?: number;
  per_page?: number;
}

export interface ProductListResult {
  data: ProductDetail[];
  meta: ApiMeta;
}

export const productApi = {
  async list(params?: ProductListParams): Promise<ProductListResult> {
    const res = await apiRequestWithMeta<ProductDetail[]>("/products", {
      query: {
        search: params?.search,
        category_id: params?.category_id,
        barcode: params?.barcode,
        active_only: params?.active_only,
        low_stock: params?.low_stock,
        page: params?.page,
        per_page: params?.per_page ?? 25,
      },
    });
    return { data: res.data, meta: res.meta };
  },

  get(id: number): Promise<ProductDetail> {
    return apiRequest<ProductDetail>(`/products/${id}`);
  },

  create(body: FormData | Record<string, unknown>): Promise<ProductDetail> {
    return apiRequest<ProductDetail>("/products", {
      method: "POST",
      body,
    });
  },

  update(id: number, body: FormData | Record<string, unknown>): Promise<ProductDetail> {
    // Jika FormData, Laravel memerlukan POST dengan _method=PUT agar multipart/form-data terbaca
    if (typeof FormData !== "undefined" && body instanceof FormData) {
      if (!body.has("_method")) {
        body.append("_method", "PUT");
      }
      return apiRequest<ProductDetail>(`/products/${id}`, {
        method: "POST",
        body,
      });
    }

    return apiRequest<ProductDetail>(`/products/${id}`, {
      method: "PUT",
      body,
    });
  },

  archive(id: number): Promise<ProductDetail> {
    return apiRequest<ProductDetail>(`/products/${id}/archive`, {
      method: "POST",
    });
  },
};
