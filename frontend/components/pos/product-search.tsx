"use client";

import { useEffect, useRef, useState } from "react";
import { productApi, type ProductSearchParams } from "@/lib/api/pos";
import type { ProductCashier } from "@/lib/types";
import { useDebouncedValue } from "@/lib/hooks/use-debounced-value";
import { formatRupiah } from "@/lib/format";
import { Input } from "@/components/ui/input";
import { ApiError } from "@/lib/api";

interface ProductSearchProps {
  /** Dipanggil saat produk dipilih (klik atau scan). */
  onPick: (product: ProductCashier) => void;
}

export function ProductSearch({ onPick }: ProductSearchProps) {
  const [query, setQuery] = useState("");
  const [results, setResults] = useState<ProductCashier[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const debounced = useDebouncedValue(query, 300);
  const abortRef = useRef<AbortController | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      const trimmed = debounced.trim();
      if (trimmed.length === 0) {
        setResults([]);
        setError(null);
        return;
      }
      setLoading(true);
      setError(null);

      // Batalkan request pencarian sebelumnya (kecepatan ketik kasir).
      abortRef.current?.abort();
      const controller = new AbortController();
      abortRef.current = controller;

      const params: ProductSearchParams = { search: trimmed, per_page: 24 };
      // Input mirip barcode (hanya digit, panjang >= 8) → kirim juga sebagai barcode.
      const barcodeLike = /^\d{8,}$/.test(trimmed);
      if (barcodeLike) params.barcode = trimmed;

      productApi
        .search(params, controller.signal)
        .then(({ data }) => {
          if (controller.signal.aborted) return;
          // Barcode exact match diprioritaskan di atas hasil "like".
          if (barcodeLike) {
            const exact = data.find((p) => p.barcode === trimmed);
            if (exact) {
              onPick(exact);
              setQuery("");
              setResults([]);
              return;
            }
          }
          setResults(data);
        })
        .catch((err) => {
          if (controller.signal.aborted) return;
          if (err instanceof ApiError) setError(err.message);
          else setError("Gagal memuat produk.");
        })
        .finally(() => {
          if (!controller.signal.aborted) setLoading(false);
        });
    }, 0);
    return () => clearTimeout(timer);
  }, [debounced, onPick]);

  return (
    <section aria-label="Pencarian Produk" className="flex h-full flex-col gap-3">
      <Input
        name="product_search"
        label="Cari / Scan Produk"
        placeholder="Nama, SKU, atau scan barcode…"
        value={query}
        onChange={(e) => setQuery(e.target.value)}
        autoFocus
      />

      {loading && <p className="text-sm text-muted-foreground">Mencari…</p>}
      {error && (
        <p role="alert" className="text-sm text-danger">
          {error}
        </p>
      )}
      {!loading && !error && debounced.trim().length > 0 && results.length === 0 && (
        <p className="text-sm text-muted-foreground">
          Tidak ada produk yang cocok dengan “{debounced.trim()}”.
        </p>
      )}

      <div className="grid flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto scroll-thin lg:grid-cols-3 xl:grid-cols-4">
        {results.map((p) => {
          const outOfStock = p.stock <= 0;
          return (
            <button
              key={p.id}
              type="button"
              disabled={outOfStock}
              onClick={() => onPick(p)}
              className={`flex min-h-24 flex-col justify-between rounded-xl border p-3 text-left transition-colors ${
                outOfStock
                  ? "cursor-not-allowed border-border bg-slate-50 opacity-60"
                  : "border-border bg-surface hover:border-primary hover:bg-blue-50 active:scale-[0.98]"
              }`}
            >
              <span className="line-clamp-2 text-sm font-semibold">{p.name}</span>
              <span className="text-xs text-muted-foreground">SKU {p.sku}</span>
              <span className="flex items-baseline justify-between gap-2">
                <span className="text-sm font-bold text-primary">{formatRupiah(p.selling_price)}</span>
                <span
                  className={`text-xs font-medium ${
                    p.stock <= 0 ? "text-danger" : p.stock <= 5 ? "text-warning" : "text-muted-foreground"
                  }`}
                >
                  Stok {p.stock}
                </span>
              </span>
            </button>
          );
        })}
      </div>
    </section>
  );
}
