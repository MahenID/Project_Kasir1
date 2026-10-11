"use client";

import { useCallback, useEffect, useState } from "react";
import { saleApi } from "@/lib/api/pos";
import type { SaleListItem, Sale } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";
import { formatDate, formatRupiah } from "@/lib/format";
import { useAuth } from "@/lib/auth-context";

const PER_PAGE = 15;

export default function SalesHistoryPage() {
  const { user } = useAuth();
  const [items, setItems] = useState<SaleListItem[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [detailSale, setDetailSale] = useState<Sale | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [reprinting, setReprinting] = useState(false);
  const [reprintReason, setReprintReason] = useState("");
  const [reprintOpen, setReprintOpen] = useState(false);

  const canReprint =
    user !== null && (user.roles.includes("owner") || user.roles.includes("manager") || user.roles.includes("cashier"));

  const load = useCallback(async (p: number) => {
    setLoading(true);
    setError(null);
    try {
      const res = await saleApi.list({ per_page: PER_PAGE, page: p });
      setItems(res.data);
      const pag = res.meta.pagination;
      setPage(pag?.current_page ?? 1);
      setLastPage(pag?.last_page ?? 1);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memuat riwayat.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      load(1);
    }, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const onOpenDetail = async (saleId: number) => {
    setDetailError(null);
    setDetailSale(null);
    try {
      setDetailSale(await saleApi.get(saleId));
    } catch (err) {
      setDetailError(err instanceof ApiError ? err.message : "Gagal memuat detail.");
    }
  };

  const onReprint = async () => {
    if (!detailSale) return;
    setReprinting(true);
    try {
      const fresh = await saleApi.reprint(detailSale.id, reprintReason.trim() || undefined);
      setDetailSale(null);
      setReprintOpen(false);
      setReprintReason("");
      window.open(`/receipt/${fresh.sale_id}`, "_blank");
    } catch (err) {
      setDetailError(err instanceof ApiError ? err.message : "Gagal cetak ulang.");
    } finally {
      setReprinting(false);
    }
  };

  const statusVariant = (s: SaleListItem["status"]): "success" | "warning" | "danger" => {
    if (s === "completed") return "success";
    if (s === "voided") return "danger";
    return "warning";
  };

  return (
    <div className="h-full overflow-y-auto scroll-thin p-4">
      <header className="mb-4 flex items-center justify-between">
        <h1 className="text-xl font-bold">Riwayat Transaksi</h1>
        <Button variant="secondary" onClick={() => load(page)}>
          Segarkan
        </Button>
      </header>

      {error && (
        <p role="alert" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
          {error}
        </p>
      )}

      <div className="overflow-hidden rounded-xl border border-border bg-surface">
        <table className="w-full text-sm">
          <thead className="bg-surface-muted text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-4 py-3">Struk</th>
              <th className="px-4 py-3">Tanggal</th>
              <th className="px-4 py-3">Kasir</th>
              <th className="px-4 py-3">Metode</th>
              <th className="px-4 py-3 text-right">Total</th>
              <th className="px-4 py-3">Status</th>
              <th className="px-4 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {loading && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-sm text-muted-foreground">
                  Memuat…
                </td>
              </tr>
            )}
            {!loading && items.length === 0 && (
              <tr>
                <td colSpan={7} className="px-4 py-6 text-center text-sm text-muted-foreground">
                  Belum ada transaksi.
                </td>
              </tr>
            )}
            {items.map((s) => (
              <tr key={s.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-mono text-xs">{s.receipt_number}</td>
                <td className="px-4 py-3 text-xs">{s.completed_at ? formatDate(s.completed_at) : "—"}</td>
                <td className="px-4 py-3">{s.cashier_name}</td>
                <td className="px-4 py-3 uppercase">{s.payment_method ?? "—"}</td>
                <td className="px-4 py-3 text-right font-semibold">{formatRupiah(s.grand_total)}</td>
                <td className="px-4 py-3">
                  <Badge variant={statusVariant(s.status)}>{s.status}</Badge>
                  {s.return_status !== "none" && (
                    <span className="ml-2 text-xs text-muted-foreground">
                      Retur: {s.return_status}
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-right">
                  <Button size="sm" variant="outline" onClick={() => onOpenDetail(s.id)}>
                    Detail
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <nav className="mt-3 flex items-center justify-between" aria-label="Pagination">
        <p className="text-xs text-muted-foreground">
          Halaman {page} dari {lastPage}
        </p>
        <div className="flex gap-2">
          <Button
            variant="outline"
            size="sm"
            disabled={page <= 1 || loading}
            onClick={() => load(page - 1)}
          >
            Sebelumnya
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= lastPage || loading}
            onClick={() => load(page + 1)}
          >
            Berikutnya
          </Button>
        </div>
      </nav>

      {/* Modal Detail Transaksi */}
      <Modal
        open={detailSale !== null || detailError !== null}
        title="Detail Transaksi"
        onClose={() => setDetailSale(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDetailSale(null)}>
              Tutup
            </Button>
            {detailSale && (
              <Button
                onClick={() => window.open(`/receipt/${detailSale.id}`, "_blank")}
              >
                Cetak Struk
              </Button>
            )}
            {detailSale && canReprint && (
              <Button variant="outline" onClick={() => setReprintOpen(true)}>
                Cetak Ulang (SALINAN)
              </Button>
            )}
          </>
        }
      >
        {detailError && <p className="text-sm text-danger">{detailError}</p>}
        {detailSale && (
          <div className="flex flex-col gap-3 text-sm">
            <p>
              <span className="text-muted-foreground">Struk:</span>{" "}
              <span className="font-mono font-semibold">{detailSale.receipt_number}</span>
            </p>
            <p>
              <span className="text-muted-foreground">Tanggal:</span>{" "}
              {detailSale.completed_at ? formatDate(detailSale.completed_at) : "—"}
            </p>
            <p>
              <span className="text-muted-foreground">Kasir:</span> {detailSale.cashier_name}
            </p>
            <p>
              <span className="text-muted-foreground">Status:</span>{" "}
              <Badge variant={statusVariant(detailSale.status)}>{detailSale.status}</Badge>
            </p>

            <div className="rounded-lg border border-border">
              <table className="w-full text-xs">
                <thead className="bg-surface-muted text-left text-muted-foreground">
                  <tr>
                    <th className="px-2 py-2">Item</th>
                    <th className="px-2 py-2 text-right">Qty</th>
                    <th className="px-2 py-2 text-right">Harga</th>
                    <th className="px-2 py-2 text-right">Subtotal</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {detailSale.items.map((it) => (
                    <tr key={it.id}>
                      <td className="px-2 py-2">{it.name}</td>
                      <td className="px-2 py-2 text-right">{it.quantity} {it.unit}</td>
                      <td className="px-2 py-2 text-right">{formatRupiah(it.unit_price)}</td>
                      <td className="px-2 py-2 text-right">{formatRupiah(it.line_subtotal)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <dl className="grid grid-cols-[1fr_auto] gap-y-1 text-sm">
              <dt>Subtotal</dt>
              <dd className="text-right">{formatRupiah(detailSale.subtotal)}</dd>
              {detailSale.sale_discount_amount > 0 && (
                <>
                  <dt>Diskon</dt>
                  <dd className="text-right">- {formatRupiah(detailSale.sale_discount_amount)}</dd>
                </>
              )}
              {detailSale.tax_amount > 0 && (
                <>
                  <dt>Pajak</dt>
                  <dd className="text-right">{formatRupiah(detailSale.tax_amount)}</dd>
                </>
              )}
              <dt className="font-bold">Total</dt>
              <dd className="text-right font-bold">{formatRupiah(detailSale.grand_total)}</dd>
            </dl>
          </div>
        )}
      </Modal>

      {/* Modal alasan cetak ulang */}
      <Modal
        open={reprintOpen}
        title="Cetak Ulang Struk"
        onClose={() => setReprintOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setReprintOpen(false)}>
              Batal
            </Button>
            <Button onClick={onReprint} loading={reprinting}>
              Cetak Ulang
            </Button>
          </>
        }
      >
        <p className="text-sm text-muted-foreground">
          Struk akan dicetak dengan label “SALINAN” dan dicatat dalam log audit.
        </p>
        <div className="mt-3">
          <label className="flex flex-col gap-1">
            <span className="text-sm font-medium">Alasan (opsional)</span>
            <textarea
              rows={3}
              value={reprintReason}
              onChange={(e) => setReprintReason(e.target.value)}
              className="rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
              placeholder="cth: struk robek, pelanggan memerlukan salinan…"
            />
          </label>
        </div>
      </Modal>
    </div>
  );
}