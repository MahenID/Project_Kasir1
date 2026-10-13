import { apiRequest, apiRequestWithMeta } from "@/lib/api";
import type {
  ApiMeta,
  StocktakeDetail,
  StocktakeRow,
  StocktakeStatus,
} from "@/lib/types";

export interface StocktakeListParams {
  page?: number;
  per_page?: number;
  status?: StocktakeStatus;
}

export interface StocktakeListResult {
  data: StocktakeRow[];
  meta: ApiMeta;
}

export const stocktakesApi = {
  /**
   * Mengambil daftar dokumen stocktake (paginasi).
   */
  async list(params?: StocktakeListParams): Promise<StocktakeListResult> {
    const res = await apiRequestWithMeta<StocktakeRow[]>("/stocktakes", {
      query: {
        page: params?.page,
        per_page: params?.per_page ?? 25,
        status: params?.status,
      },
    });
    return { data: res.data, meta: res.meta };
  },

  /**
   * Mengambil data stocktake yang saat ini sedang aktif (status counting).
   * Mengembalikan null jika toko tidak dalam status audit fisik.
   */
  async active(): Promise<StocktakeDetail | null> {
    return apiRequest<StocktakeDetail | null>("/stocktakes/active");
  },

  /**
   * Mengambil detail lengkap dokumen stocktake beserta item & selisih.
   */
  async get(id: number): Promise<StocktakeDetail> {
    return apiRequest<StocktakeDetail>(`/stocktakes/${id}`);
  },

  /**
   * Memulai audit fisik baru (freeze toko & snapshot seluruh produk aktif).
   */
  async start(data: { notes?: string }): Promise<StocktakeDetail> {
    return apiRequest<StocktakeDetail>("/stocktakes", {
      method: "POST",
      body: data,
    });
  },

  /**
   * Mencatat hitungan fisik untuk 1 produk.
   */
  async count(
    stocktakeId: number,
    productId: number,
    countedStock: number
  ): Promise<{
    id: number;
    product_id: number;
    counted_stock: number;
    difference: number;
  }> {
    return apiRequest<{
      id: number;
      product_id: number;
      counted_stock: number;
      difference: number;
    }>(`/stocktakes/${stocktakeId}/count/${productId}`, {
      method: "POST",
      body: { counted_stock: countedStock },
    });
  },

  /**
   * Mencatat hitungan fisik banyak produk sekaligus (bulk count).
   */
  async bulkCount(
    stocktakeId: number,
    counts: Array<{ product_id: number; counted_stock: number }>
  ): Promise<StocktakeDetail> {
    return apiRequest<StocktakeDetail>(`/stocktakes/${stocktakeId}/counts`, {
      method: "POST",
      body: { counts },
    });
  },

  /**
   * Memposting hasil hitungan fisik, menyesuaikan stok fisik via ledger, dan membuka freeze toko.
   */
  async post(stocktakeId: number): Promise<StocktakeDetail> {
    return apiRequest<StocktakeDetail>(`/stocktakes/${stocktakeId}/post`, {
      method: "POST",
    });
  },

  /**
   * Membatalkan stocktake aktif tanpa mengubah stok fisik, dan membuka freeze toko.
   */
  async cancel(stocktakeId: number): Promise<StocktakeDetail> {
    return apiRequest<StocktakeDetail>(`/stocktakes/${stocktakeId}/cancel`, {
      method: "POST",
    });
  },
};
