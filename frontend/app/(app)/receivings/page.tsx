"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { receivingApi } from "@/lib/api/receivings";
import { supplierApi } from "@/lib/api/suppliers";
import { productApi } from "@/lib/api/products";
import type {
  ProductDetail,
  ReceivingDetail,
  ReceivingRow,
  ReceivingStatus,
  ReceivingType,
  Supplier,
} from "@/lib/types";
import { ApiError } from "@/lib/api";
import { formatRupiah } from "@/lib/format";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

interface LineFormItem {
  product_id: number;
  quantity: number;
  unit_cost: number;
}

const STATUS_BADGE: Record<
  ReceivingStatus,
  { label: string; variant: "default" | "success" | "warning" | "info" | "danger" }
> = {
  draft: { label: "Draft", variant: "warning" },
  approved: { label: "Disetujui", variant: "info" },
  posted: { label: "Diposting", variant: "success" },
  cancelled: { label: "Dibatalkan", variant: "default" },
};

export default function ReceivingsPage() {
  const { user } = useAuth();
  const [receivings, setReceivings] = useState<ReceivingRow[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [products, setProducts] = useState<ProductDetail[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filter States
  const [statusFilter, setStatusFilter] = useState<string>("");
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);

  // Detail Modal State
  const [detailModalOpen, setDetailModalOpen] = useState(false);
  const [selectedReceiving, setSelectedReceiving] = useState<ReceivingDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionInProgress, setActionInProgress] = useState(false);

  // Create Modal State
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [formType, setFormType] = useState<ReceivingType>("purchase");
  const [formSupplierId, setFormSupplierId] = useState<string>("");
  const [formExternalRef, setFormExternalRef] = useState("");
  const [formReceivedAt, setFormReceivedAt] = useState(() =>
    new Date().toISOString().split("T")[0]
  );
  const [formLines, setFormLines] = useState<LineFormItem[]>([]);
  const [createSubmitting, setCreateSubmitting] = useState(false);
  const [createError, setCreateError] = useState<string | null>(null);

  // Cancel Dialog State
  const [cancelDialogOpen, setCancelDialogOpen] = useState(false);
  const [cancelNotes, setCancelNotes] = useState("");

  const isSupervisor =
    user?.roles.includes("owner") || user?.roles.includes("manager");

  // Load suppliers and active products for the forms
  useEffect(() => {
    if (!isSupervisor) return;
    supplierApi
      .list({ active_only: true })
      .then((res) => setSuppliers(res.data))
      .catch(() => {});
    productApi
      .list({ active_only: true, per_page: 100 })
      .then((res) => setProducts(res.data))
      .catch(() => {});
  }, [isSupervisor]);

  const loadReceivings = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await receivingApi.list({
        status: statusFilter || undefined,
        page: currentPage,
        per_page: 20,
      });
      setReceivings(res.data);
      if (res.meta?.pagination) {
        setTotalPages(res.meta.pagination.last_page);
        setTotalItems(res.meta.pagination.total);
      }
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : "Gagal memuat dokumen penerimaan."
      );
    } finally {
      setLoading(false);
    }
  }, [statusFilter, currentPage]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadReceivings();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadReceivings]);

  // Open Document Detail
  const handleOpenDetail = async (id: number) => {
    setDetailLoading(true);
    setActionError(null);
    setDetailModalOpen(true);
    try {
      const detail = await receivingApi.get(id);
      setSelectedReceiving(detail);
    } catch (err) {
      setActionError(
        err instanceof ApiError ? err.message : "Gagal memuat rincian dokumen."
      );
    } finally {
      setDetailLoading(false);
    }
  };

  // Open Create Modal
  const handleOpenCreate = () => {
    setFormType("purchase");
    setFormSupplierId(suppliers[0]?.id ? String(suppliers[0].id) : "");
    setFormExternalRef("");
    setFormReceivedAt(new Date().toISOString().split("T")[0]);
    setFormLines([
      {
        product_id: products[0]?.id ?? 0,
        quantity: 1,
        unit_cost: 0,
      },
    ]);
    setCreateError(null);
    setCreateModalOpen(true);
  };

  const handleAddLine = () => {
    const unusedProduct = products.find(
      (p) => !formLines.some((line) => line.product_id === p.id)
    );
    setFormLines((prev) => [
      ...prev,
      {
        product_id: unusedProduct?.id ?? (products[0]?.id ?? 0),
        quantity: 1,
        unit_cost: 0,
      },
    ]);
  };

  const handleRemoveLine = (index: number) => {
    setFormLines((prev) => prev.filter((_, i) => i !== index));
  };

  const handleLineChange = (
    index: number,
    field: keyof LineFormItem,
    value: number
  ) => {
    setFormLines((prev) =>
      prev.map((line, i) => (i === index ? { ...line, [field]: value } : line))
    );
  };

  const handleSaveCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (formType === "purchase" && !formSupplierId) {
      setCreateError("Supplier wajib dipilih untuk penerimaan jenis pembelian.");
      return;
    }
    if (formLines.length === 0) {
      setCreateError("Minimal tambahkan 1 baris item penerimaan.");
      return;
    }
    for (let i = 0; i < formLines.length; i++) {
      if (formLines[i].quantity <= 0) {
        setCreateError(`Kuantitas baris ke-${i + 1} harus lebih besar dari 0.`);
        return;
      }
      if (formLines[i].unit_cost < 0) {
        setCreateError(`Harga modal baris ke-${i + 1} tidak boleh negatif.`);
        return;
      }
    }

    setCreateSubmitting(true);
    setCreateError(null);

    try {
      const created = await receivingApi.create({
        type: formType,
        supplier_id: formType === "purchase" ? Number(formSupplierId) : null,
        external_reference: formExternalRef.trim() || null,
        received_at: formReceivedAt || null,
        items: formLines.map((l) => ({
          product_id: l.product_id,
          quantity: l.quantity,
          unit_cost: l.unit_cost,
        })),
      });

      setCreateModalOpen(false);
      await loadReceivings();
      setSelectedReceiving(created);
      setDetailModalOpen(true);
    } catch (err) {
      setCreateError(
        err instanceof ApiError
          ? err.message
          : "Gagal membuat dokumen penerimaan."
      );
    } finally {
      setCreateSubmitting(false);
    }
  };

  // Actions on Document: Approve, Post, Cancel
  const handleApprove = async () => {
    if (!selectedReceiving) return;
    setActionInProgress(true);
    setActionError(null);
    try {
      const updated = await receivingApi.approve(selectedReceiving.id);
      setSelectedReceiving(updated);
      await loadReceivings();
    } catch (err) {
      setActionError(
        err instanceof ApiError ? err.message : "Gagal menyetujui dokumen."
      );
    } finally {
      setActionInProgress(false);
    }
  };

  const handlePost = async () => {
    if (!selectedReceiving) return;
    if (
      !confirm(
        "Posting dokumen penerimaan ini akan MENAMBAH STOK FISIK secara permanen dan merevisi HARGA POKOK RATA-RATA (Moving Average Cost) di buku besar mutasi. Lanjutkan?"
      )
    ) {
      return;
    }
    setActionInProgress(true);
    setActionError(null);
    try {
      const updated = await receivingApi.post(selectedReceiving.id);
      setSelectedReceiving(updated);
      await loadReceivings();
    } catch (err) {
      setActionError(
        err instanceof ApiError ? err.message : "Gagal memposting dokumen."
      );
    } finally {
      setActionInProgress(false);
    }
  };

  const handleConfirmCancel = async () => {
    if (!selectedReceiving) return;
    setActionInProgress(true);
    setActionError(null);
    try {
      const updated = await receivingApi.cancel(
        selectedReceiving.id,
        cancelNotes.trim()
      );
      setSelectedReceiving(updated);
      setCancelDialogOpen(false);
      setCancelNotes("");
      await loadReceivings();
    } catch (err) {
      setActionError(
        err instanceof ApiError ? err.message : "Gagal membatalkan dokumen."
      );
    } finally {
      setActionInProgress(false);
    }
  };

  if (!isSupervisor) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <div className="max-w-md rounded-xl border border-border bg-surface p-6 text-center">
          <h2 className="text-lg font-semibold text-danger">Akses Terbatas</h2>
          <p className="mt-2 text-sm text-muted-foreground">
            Halaman operasional penerimaan barang inventaris hanya dapat diakses oleh peran Pemilik (Owner) atau Manajer.
          </p>
        </div>
      </div>
    );
  }

  const createTotalQty = formLines.reduce((acc, l) => acc + (l.quantity || 0), 0);
  const createTotalCost = formLines.reduce(
    (acc, l) => acc + (l.quantity || 0) * (l.unit_cost || 0),
    0
  );

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto p-4 md:p-6">
      {/* Header Halaman */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Penerimaan Stok (Receiving)</h1>
          <p className="text-xs text-muted-foreground">
            Dokumen pengadaan barang masuk, mutasi buku besar & rekalkulasi harga modal rata-rata (PRD FR-INV-01)
          </p>
        </div>
        <Button onClick={handleOpenCreate} variant="primary">
          + Buat Penerimaan Baru
        </Button>
      </div>

      {/* Filter Status Bar */}
      <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface p-2 text-xs">
        <span className="px-2 font-semibold text-muted-foreground">Status:</span>
        {[
          { key: "", label: "Semua Dokumen" },
          { key: "draft", label: "Draft" },
          { key: "approved", label: "Disetujui" },
          { key: "posted", label: "Diposting (Stok Bertambah)" },
          { key: "cancelled", label: "Dibatalkan" },
        ].map((tab) => (
          <button
            key={tab.key}
            type="button"
            onClick={() => {
              setStatusFilter(tab.key);
              setCurrentPage(1);
            }}
            className={`rounded-lg px-3 py-1.5 font-medium transition-colors ${
              statusFilter === tab.key
                ? "bg-primary text-white"
                : "text-muted-foreground hover:bg-slate-100"
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Tabel Penerimaan */}
      <div className="flex flex-1 flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-xs">
        {loading ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Memuat daftar dokumen penerimaan...
          </div>
        ) : error ? (
          <div className="p-8 text-center">
            <p className="text-sm text-danger">{error}</p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={loadReceivings}
            >
              Coba Lagi
            </Button>
          </div>
        ) : receivings.length === 0 ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada dokumen penerimaan yang ditemukan.
          </div>
        ) : (
          <div className="flex-1 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">No. Dokumen</th>
                  <th className="px-4 py-3">Tipe</th>
                  <th className="px-4 py-3">Supplier / Pemasok</th>
                  <th className="px-4 py-3">Ref. Eksternal</th>
                  <th className="px-4 py-3">Tgl Diterima</th>
                  <th className="px-4 py-3">Pembuat</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {receivings.map((r) => (
                  <tr key={r.id} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-mono text-xs font-bold text-primary">
                      {r.receiving_number}
                    </td>
                    <td className="px-4 py-3 text-xs capitalize">
                      {r.type === "purchase" ? "Pembelian" : "Saldo Awal"}
                    </td>
                    <td className="px-4 py-3 font-medium">
                      {r.supplier_name ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground">
                      {r.external_reference ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground">
                      {r.received_at
                        ? new Date(r.received_at).toLocaleDateString("id-ID")
                        : "—"}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground">
                      {r.created_by ?? "—"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={STATUS_BADGE[r.status]?.variant ?? "default"}>
                        {STATUS_BADGE[r.status]?.label ?? r.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => handleOpenDetail(r.id)}
                      >
                        Rincian
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Footer Pagination */}
        {!loading && totalPages > 1 && (
          <div className="flex items-center justify-between border-t border-border px-4 py-3 text-xs text-muted-foreground">
            <div>
              Total <span className="font-semibold">{totalItems}</span> dokumen
              (Halaman {currentPage} dari {totalPages})
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              >
                Sebelumnya
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= totalPages}
                onClick={() =>
                  setCurrentPage((p) => Math.min(totalPages, p + 1))
                }
              >
                Selanjutnya
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Modal Buat Dokumen Penerimaan Baru */}
      <Modal
        open={createModalOpen}
        title="Buat Dokumen Penerimaan Barang"
        onClose={() => setCreateModalOpen(false)}
        maxWidthClass="max-w-3xl"
      >
        <form onSubmit={handleSaveCreate} className="flex flex-col gap-4 p-5">
          {createError && (
            <div className="rounded-lg bg-red-50 p-3 text-xs text-danger">
              {createError}
            </div>
          )}

          {/* Form Header Dokumen */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Tipe Penerimaan
              </label>
              <select
                aria-label="Pilih Tipe Penerimaan"
                value={formType}
                onChange={(e) => setFormType(e.target.value as ReceivingType)}
                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-xs focus:border-primary focus:outline-hidden"
              >
                <option value="purchase">Pembelian dari Supplier (purchase)</option>
                <option value="opening">Saldo Awal Toko (opening)</option>
              </select>
            </div>

            {formType === "purchase" && (
              <div>
                <label className="mb-1 block text-xs font-semibold text-foreground">
                  Supplier Pemasok <span className="text-danger">*</span>
                </label>
                <select
                  aria-label="Pilih Supplier"
                  value={formSupplierId}
                  onChange={(e) => setFormSupplierId(e.target.value)}
                  required
                  className="h-10 w-full rounded-lg border border-border bg-background px-3 text-xs focus:border-primary focus:outline-hidden"
                >
                  <option value="">-- Pilih Supplier --</option>
                  {suppliers.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.code} - {s.name}
                    </option>
                  ))}
                </select>
              </div>
            )}

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                No. Referensi Eksternal (Surat Jalan / Faktur)
              </label>
              <Input
                type="text"
                placeholder="Contoh: SJ-2026/X/089"
                value={formExternalRef}
                onChange={(e) => setFormExternalRef(e.target.value)}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Tanggal Barang Diterima
              </label>
              <Input
                type="date"
                value={formReceivedAt}
                onChange={(e) => setFormReceivedAt(e.target.value)}
              />
            </div>
          </div>

          {/* Baris Barang Masuk */}
          <div className="mt-2 border-t border-border pt-3">
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-xs font-bold text-foreground uppercase tracking-wider">
                Daftar Barang Masuk (Lines)
              </h3>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleAddLine}
              >
                + Tambah Baris
              </Button>
            </div>

            <div className="max-h-60 overflow-y-auto rounded-lg border border-border">
              <table className="w-full text-left text-xs">
                <thead className="border-b border-border bg-slate-50 font-semibold text-muted-foreground">
                  <tr>
                    <th className="p-2.5">Produk</th>
                    <th className="p-2.5 w-24">Jumlah Unit</th>
                    <th className="p-2.5 w-32">Harga Modal Satuan (Rp)</th>
                    <th className="p-2.5 w-32 text-right">Subtotal</th>
                    <th className="p-2.5 w-12 text-center">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {formLines.map((line, idx) => {
                    const subtotal = (line.quantity || 0) * (line.unit_cost || 0);
                    return (
                      <tr key={idx}>
                        <td className="p-2">
                          <select
                            aria-label="Pilih Produk"
                            value={line.product_id}
                            onChange={(e) =>
                              handleLineChange(
                                idx,
                                "product_id",
                                Number(e.target.value)
                              )
                            }
                            className="h-8 w-full rounded border border-border bg-background px-2 text-xs"
                          >
                            {products.map((p) => (
                              <option key={p.id} value={p.id}>
                                {p.name} ({p.sku})
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className="p-2">
                          <input
                            type="number"
                            min="1"
                            step="1"
                            value={line.quantity}
                            onChange={(e) =>
                              handleLineChange(
                                idx,
                                "quantity",
                                parseInt(e.target.value, 10) || 0
                              )
                            }
                            className="h-8 w-full rounded border border-border bg-background px-2 text-xs font-bold text-center"
                          />
                        </td>
                        <td className="p-2">
                          <input
                            type="number"
                            min="0"
                            step="1"
                            value={line.unit_cost}
                            onChange={(e) =>
                              handleLineChange(
                                idx,
                                "unit_cost",
                                parseInt(e.target.value, 10) || 0
                              )
                            }
                            className="h-8 w-full rounded border border-border bg-background px-2 text-xs text-right font-mono"
                          />
                        </td>
                        <td className="p-2 text-right font-mono font-semibold">
                          {formatRupiah(subtotal)}
                        </td>
                        <td className="p-2 text-center">
                          {formLines.length > 1 && (
                            <button
                              type="button"
                              onClick={() => handleRemoveLine(idx)}
                              className="text-danger hover:font-bold"
                              title="Hapus baris"
                            >
                              ✕
                            </button>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {/* Total Ringkasan Formulir */}
            <div className="mt-3 flex items-center justify-between rounded-lg bg-slate-50 p-3 text-xs">
              <span className="font-semibold text-muted-foreground">
                Total Estimasi: {createTotalQty} unit barang
              </span>
              <span className="text-sm font-bold text-primary">
                Total Nilai: {formatRupiah(createTotalCost)}
              </span>
            </div>
          </div>

          <div className="mt-2 flex justify-end gap-2 border-t border-border pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setCreateModalOpen(false)}
              disabled={createSubmitting}
            >
              Batal
            </Button>
            <Button type="submit" variant="primary" disabled={createSubmitting}>
              {createSubmitting ? "Menyimpan..." : "Simpan sebagai Draft"}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Detail Dokumen Penerimaan */}
      <Modal
        open={detailModalOpen}
        title={
          selectedReceiving
            ? `Dokumen: ${selectedReceiving.receiving_number}`
            : "Rincian Dokumen"
        }
        onClose={() => setDetailModalOpen(false)}
        maxWidthClass="max-w-3xl"
      >
        <div className="p-5">
          {detailLoading ? (
            <div className="p-8 text-center text-sm text-muted-foreground">
              Memuat detail dokumen...
            </div>
          ) : selectedReceiving ? (
            <div className="flex flex-col gap-4">
              {actionError && (
                <div className="rounded-lg bg-red-50 p-3 text-xs text-danger">
                  {actionError}
                </div>
              )}

              {/* Ringkasan Header Dokumen */}
              <div className="grid grid-cols-2 gap-3 rounded-lg border border-border bg-slate-50/50 p-3 text-xs sm:grid-cols-4">
                <div>
                  <span className="text-muted-foreground">Status:</span>
                  <div className="mt-1">
                    <Badge
                      variant={
                        STATUS_BADGE[selectedReceiving.status]?.variant ??
                        "default"
                      }
                    >
                      {STATUS_BADGE[selectedReceiving.status]?.label ??
                        selectedReceiving.status}
                    </Badge>
                  </div>
                </div>
                <div>
                  <span className="text-muted-foreground">Tipe:</span>
                  <p className="mt-1 font-semibold capitalize">
                    {selectedReceiving.type === "purchase"
                      ? "Pembelian Supplier"
                      : "Saldo Awal"}
                  </p>
                </div>
                <div>
                  <span className="text-muted-foreground">Supplier:</span>
                  <p className="mt-1 font-semibold">
                    {selectedReceiving.supplier?.name ?? "—"}
                  </p>
                </div>
                <div>
                  <span className="text-muted-foreground">Ref. Eksternal:</span>
                  <p className="mt-1 font-mono">
                    {selectedReceiving.external_reference ?? "—"}
                  </p>
                </div>
                <div>
                  <span className="text-muted-foreground">Tgl Terima:</span>
                  <p className="mt-1">
                    {selectedReceiving.received_at
                      ? new Date(
                          selectedReceiving.received_at
                        ).toLocaleDateString("id-ID")
                      : "—"}
                  </p>
                </div>
                <div>
                  <span className="text-muted-foreground">Dibuat Oleh:</span>
                  <p className="mt-1">
                    {selectedReceiving.created_by.name ?? "—"}
                  </p>
                </div>
                {selectedReceiving.posted_by && (
                  <div>
                    <span className="text-muted-foreground">Diposting Oleh:</span>
                    <p className="mt-1 font-semibold text-success">
                      {selectedReceiving.posted_by.name}
                    </p>
                  </div>
                )}
                {selectedReceiving.correction_notes && (
                  <div className="col-span-2">
                    <span className="text-muted-foreground">Catatan Batal:</span>
                    <p className="mt-1 text-danger">
                      {selectedReceiving.correction_notes}
                    </p>
                  </div>
                )}
              </div>

              {/* Tabel Rincian Item */}
              <div>
                <h4 className="mb-2 text-xs font-bold uppercase tracking-wider text-muted-foreground">
                  Rincian Barang yang Diterima
                </h4>
                <div className="overflow-hidden rounded-lg border border-border">
                  <table className="w-full text-left text-xs">
                    <thead className="border-b border-border bg-slate-50 font-semibold text-muted-foreground">
                      <tr>
                        <th className="p-3">Nama Produk & SKU</th>
                        <th className="p-3 text-center">Kuantitas</th>
                        <th className="p-3 text-right">Modal per Unit</th>
                        <th className="p-3 text-right">Total Biaya</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border">
                      {selectedReceiving.items.map((item) => (
                        <tr key={item.id} className="hover:bg-slate-50/50">
                          <td className="p-3 font-medium">
                            {item.product_name ?? `Produk #${item.product_id}`}
                            <div className="font-mono text-[11px] text-muted-foreground">
                              {item.product_sku ?? "—"}
                            </div>
                          </td>
                          <td className="p-3 text-center font-bold">
                            {item.quantity}
                          </td>
                          <td className="p-3 text-right font-mono">
                            {formatRupiah(item.unit_cost)}
                          </td>
                          <td className="p-3 text-right font-mono font-bold text-primary">
                            {formatRupiah(item.extended_cost)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot className="border-t border-border bg-slate-50 font-bold">
                      <tr>
                        <td className="p-3">TOTAL KESELURUHAN</td>
                        <td className="p-3 text-center">
                          {selectedReceiving.totals.total_quantity} unit
                        </td>
                        <td className="p-3 text-right"></td>
                        <td className="p-3 text-right font-mono text-sm text-primary">
                          {formatRupiah(
                            selectedReceiving.totals.total_extended_cost
                          )}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              </div>

              {/* Tombol Aksi Berdasarkan Status Dokumen */}
              <div className="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-border pt-4">
                <Button
                  variant="outline"
                  onClick={() => setDetailModalOpen(false)}
                >
                  Tutup
                </Button>

                <div className="flex items-center gap-2">
                  {selectedReceiving.status === "draft" && (
                    <>
                      <Button
                        variant="ghost"
                        className="text-danger hover:bg-red-50 hover:text-danger"
                        disabled={actionInProgress}
                        onClick={() => setCancelDialogOpen(true)}
                      >
                        Batalkan Dokumen
                      </Button>
                      <Button
                        variant="primary"
                        disabled={actionInProgress}
                        onClick={handleApprove}
                      >
                        {actionInProgress ? "Memproses..." : "Setujui Dokumen (Approve)"}
                      </Button>
                    </>
                  )}

                  {selectedReceiving.status === "approved" && (
                    <>
                      <Button
                        variant="ghost"
                        className="text-danger hover:bg-red-50 hover:text-danger"
                        disabled={actionInProgress}
                        onClick={() => setCancelDialogOpen(true)}
                      >
                        Batalkan Dokumen
                      </Button>
                      <Button
                        variant="primary"
                        disabled={actionInProgress}
                        onClick={handlePost}
                      >
                        {actionInProgress
                          ? "Memposting..."
                          : "Posting ke Stok Fisik (Post)"}
                      </Button>
                    </>
                  )}

                  {selectedReceiving.status === "posted" && (
                    <span className="text-xs font-semibold text-success">
                      ✓ Dokumen telah diposting permanen ke buku besar stok.
                    </span>
                  )}

                  {selectedReceiving.status === "cancelled" && (
                    <span className="text-xs font-semibold text-muted-foreground">
                      Dokumen ini telah dibatalkan.
                    </span>
                  )}
                </div>
              </div>
            </div>
          ) : null}
        </div>
      </Modal>

      {/* Modal Dialog Pembatalan Dokumen */}
      <Modal
        open={cancelDialogOpen}
        title="Batalkan Dokumen Penerimaan"
        onClose={() => setCancelDialogOpen(false)}
        maxWidthClass="max-w-md"
      >
        <div className="p-5">
          <p className="text-xs text-muted-foreground">
            Masukkan catatan atau alasan pembatalan dokumen penerimaan ini:
          </p>
          <textarea
            rows={3}
            placeholder="Contoh: Kesalahan input supplier / pesanan ditolak pengirim..."
            value={cancelNotes}
            onChange={(e) => setCancelNotes(e.target.value)}
            className="mt-3 w-full rounded-lg border border-border bg-background p-2.5 text-xs focus:border-primary focus:outline-hidden"
          />
          <div className="mt-4 flex justify-end gap-2 border-t border-border pt-3">
            <Button
              variant="outline"
              onClick={() => setCancelDialogOpen(false)}
              disabled={actionInProgress}
            >
              Kembali
            </Button>
            <Button
              variant="danger"
              onClick={handleConfirmCancel}
              disabled={actionInProgress}
            >
              {actionInProgress ? "Membatalkan..." : "Ya, Batalkan Dokumen"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
