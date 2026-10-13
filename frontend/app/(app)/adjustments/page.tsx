"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { adjustmentsApi } from "@/lib/api/adjustments";
import { productApi } from "@/lib/api/products";
import type {
  InventoryAdjustmentDetail,
  InventoryAdjustmentRow,
  InventoryAdjustmentStatus,
  InventoryAdjustmentType,
  ProductDetail,
} from "@/lib/types";
import { ApiError } from "@/lib/api";
import { formatRupiah } from "@/lib/format";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

const STATUS_BADGE: Record<
  InventoryAdjustmentStatus,
  { label: string; variant: "default" | "success" | "warning" | "info" | "danger" }
> = {
  draft: { label: "Draft", variant: "warning" },
  posted: { label: "Diposting", variant: "success" },
  cancelled: { label: "Dibatalkan", variant: "default" },
};

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "-";
  try {
    const d = new Date(iso);
    return d.toLocaleString("id-ID", {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone: "Asia/Makassar",
    });
  } catch {
    return iso;
  }
}

interface FormLine {
  product_id: number;
  quantity_delta: number;
  new_average_cost: number;
}

export default function AdjustmentsPage() {
  const { user } = useAuth();
  const [adjustments, setAdjustments] = useState<InventoryAdjustmentRow[]>([]);
  const [products, setProducts] = useState<ProductDetail[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filters & Pagination
  const [typeFilter, setTypeFilter] = useState<string>("");
  const [statusFilter, setStatusFilter] = useState<string>("");
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);

  // Detail Modal
  const [detailModalOpen, setDetailModalOpen] = useState(false);
  const [selectedAdjustment, setSelectedAdjustment] =
    useState<InventoryAdjustmentDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [actionInProgress, setActionInProgress] = useState(false);

  // Create Modal
  const [createModalOpen, setCreateModalOpen] = useState(false);
  const [createType, setCreateType] =
    useState<InventoryAdjustmentType>("quantity");
  const [createReason, setCreateReason] = useState("");
  const [createEvidence, setCreateEvidence] = useState("");
  const [createLines, setCreateLines] = useState<FormLine[]>([
    { product_id: 0, quantity_delta: 0, new_average_cost: 0 },
  ]);
  const [creating, setCreating] = useState(false);

  const isOwner = user?.roles?.includes("owner");
  const isManager = user?.roles?.includes("manager");
  const canManage = isOwner || isManager;

  // Load Data
  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const [adjRes, prodRes] = await Promise.all([
        adjustmentsApi.list({
          page: currentPage,
          per_page: 15,
          type: (typeFilter as InventoryAdjustmentType) || undefined,
          status: (statusFilter as InventoryAdjustmentStatus) || undefined,
        }),
        productApi.list({ active_only: true, per_page: 100 }),
      ]);

      setAdjustments(adjRes.data);
      if (adjRes.meta.pagination) {
        setTotalPages(adjRes.meta.pagination.last_page);
        setTotalItems(adjRes.meta.pagination.total);
      }
      setProducts(prodRes.data);
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError("Gagal memuat dokumen penyesuaian.");
      }
    } finally {
      setLoading(false);
    }
  }, [currentPage, typeFilter, statusFilter]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadData();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadData]);

  // Open Detail
  const handleOpenDetail = async (id: number) => {
    setDetailLoading(true);
    setSelectedAdjustment(null);
    setDetailModalOpen(true);
    setActionError(null);
    setActionSuccess(null);
    try {
      const res = await adjustmentsApi.get(id);
      setSelectedAdjustment(res);
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal memuat rincian penyesuaian.");
      }
    } finally {
      setDetailLoading(false);
    }
  };

  // Post Draft
  const handlePost = async () => {
    if (!selectedAdjustment) return;
    if (
      !confirm(
        `Posting penyesuaian ${selectedAdjustment.adjustment_number}? Perubahan stok/modal akan langsung diterapkan ke kartu stok.`
      )
    ) {
      return;
    }

    setActionInProgress(true);
    setActionError(null);
    try {
      const updated = await adjustmentsApi.post(selectedAdjustment.id);
      setSelectedAdjustment(updated);
      setActionSuccess("Dokumen penyesuaian berhasil diposting!");
      loadData();
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal memposting penyesuaian.");
      }
    } finally {
      setActionInProgress(false);
    }
  };

  // Cancel Draft
  const handleCancel = async () => {
    if (!selectedAdjustment) return;
    if (
      !confirm(
        `Batalkan draft penyesuaian ${selectedAdjustment.adjustment_number}?`
      )
    ) {
      return;
    }

    setActionInProgress(true);
    setActionError(null);
    try {
      const updated = await adjustmentsApi.cancel(selectedAdjustment.id);
      setSelectedAdjustment(updated);
      setActionSuccess("Draft penyesuaian dibatalkan.");
      loadData();
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal membatalkan penyesuaian.");
      }
    } finally {
      setActionInProgress(false);
    }
  };

  // Form Line Helpers
  const addLine = () => {
    setCreateLines((prev) => [
      ...prev,
      { product_id: 0, quantity_delta: 0, new_average_cost: 0 },
    ]);
  };

  const removeLine = (idx: number) => {
    setCreateLines((prev) => prev.filter((_, i) => i !== idx));
  };

  const updateLine = (idx: number, patch: Partial<FormLine>) => {
    setCreateLines((prev) =>
      prev.map((line, i) => (i === idx ? { ...line, ...patch } : line))
    );
  };

  // Submit Create Draft
  const handleCreateSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (createReason.trim().length < 5) {
      setActionError("Alasan penyesuaian minimal 5 karakter.");
      return;
    }

    // Validate lines
    const validLines = createLines.filter((l) => l.product_id > 0);
    if (validLines.length === 0) {
      setActionError("Pilih minimal satu produk untuk disesuaikan.");
      return;
    }

    // Check duplicate products
    const pids = validLines.map((l) => l.product_id);
    if (new Set(pids).size !== pids.length) {
      setActionError("Terdapat produk yang dipilih lebih dari satu kali.");
      return;
    }

    // Check line-specific validation
    for (const l of validLines) {
      if (createType === "quantity" && l.quantity_delta === 0) {
        setActionError("Perubahan kuantitas (delta) tidak boleh bernilai nol.");
        return;
      }
      if (createType === "revaluation" && l.new_average_cost < 0) {
        setActionError("Harga modal baru tidak boleh negatif.");
        return;
      }
    }

    setCreating(true);
    setActionError(null);
    try {
      const payloadItems = validLines.map((l) => {
        if (createType === "quantity") {
          return {
            product_id: l.product_id,
            quantity_delta: l.quantity_delta,
          };
        } else {
          return {
            product_id: l.product_id,
            new_average_cost: l.new_average_cost,
          };
        }
      });

      const res = await adjustmentsApi.create({
        type: createType,
        reason: createReason.trim(),
        evidence_reference: createEvidence.trim() || undefined,
        items: payloadItems,
      });

      setCreateModalOpen(false);
      // Reset form
      setCreateReason("");
      setCreateEvidence("");
      setCreateLines([
        { product_id: 0, quantity_delta: 0, new_average_cost: 0 },
      ]);
      setActionSuccess(
        `Draft penyesuaian ${res.adjustment_number} berhasil dibuat!`
      );
      loadData();
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal membuat penyesuaian.");
      }
    } finally {
      setCreating(false);
    }
  };

  return (
    <div className="flex h-full flex-col bg-slate-50 overflow-y-auto">
      {/* Top Header */}
      <div className="border-b border-border bg-surface px-6 py-4 shadow-2xs">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight text-foreground">
              Penyesuaian Stok &amp; Revaluasi HPP
            </h1>
            <p className="text-xs text-muted-foreground mt-0.5">
              Pencatatan penyesuaian fisik (kerusakan, kehilangan, temuan) &amp; revaluasi harga modal dengan jejak audit mutasi stok.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {canManage && (
              <Button
                variant="primary"
                onClick={() => {
                  setActionError(null);
                  setActionSuccess(null);
                  setCreateModalOpen(true);
                }}
              >
                + Buat Penyesuaian Baru
              </Button>
            )}
          </div>
        </div>

        {/* Global Notifications */}
        {(actionError || error) && (
          <div className="mt-3 flex items-center justify-between rounded-lg bg-red-50 p-3 text-xs font-semibold text-red-800 border border-red-200">
            <span>{actionError || error}</span>
            <button
              type="button"
              onClick={() => {
                setActionError(null);
                setError(null);
              }}
              className="text-red-600 hover:text-red-900"
            >
              &times;
            </button>
          </div>
        )}
        {actionSuccess && (
          <div className="mt-3 flex items-center justify-between rounded-lg bg-emerald-50 p-3 text-xs font-semibold text-emerald-800 border border-emerald-200">
            <span>{actionSuccess}</span>
            <button
              type="button"
              onClick={() => setActionSuccess(null)}
              className="text-emerald-600 hover:text-emerald-900"
            >
              &times;
            </button>
          </div>
        )}
      </div>

      <div className="flex-1 p-6 space-y-6">
        {/* Filters Toolbar */}
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-white p-4 shadow-2xs md:flex-row md:items-center md:justify-between">
          <div className="flex flex-wrap items-center gap-3">
            <div>
              <label className="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">
                Tipe Penyesuaian
              </label>
              <select
                value={typeFilter}
                onChange={(e) => {
                  setTypeFilter(e.target.value);
                  setCurrentPage(1);
                }}
                className="h-9 rounded-lg border border-border bg-surface px-3 text-xs focus:border-primary focus:outline-hidden"
              >
                <option value="">Semua Tipe</option>
                <option value="quantity">Penyesuaian Fisik (Kuantitas)</option>
                <option value="revaluation">Revaluasi HPP (Modal)</option>
              </select>
            </div>

            <div>
              <label className="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">
                Status Dokumen
              </label>
              <select
                value={statusFilter}
                onChange={(e) => {
                  setStatusFilter(e.target.value);
                  setCurrentPage(1);
                }}
                className="h-9 rounded-lg border border-border bg-surface px-3 text-xs focus:border-primary focus:outline-hidden"
              >
                <option value="">Semua Status</option>
                <option value="draft">Draft</option>
                <option value="posted">Diposting</option>
                <option value="cancelled">Dibatalkan</option>
              </select>
            </div>
          </div>

          <div className="text-xs text-muted-foreground">
            Total {totalItems} dokumen ditemukan
          </div>
        </div>

        {/* Adjustments Table */}
        <div className="rounded-xl border border-border bg-surface shadow-2xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                <tr>
                  <th className="px-4 py-3">No. Dokumen</th>
                  <th className="px-4 py-3">Tipe</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Alasan</th>
                  <th className="px-4 py-3">Bukti / Ref</th>
                  <th className="px-4 py-3">Dibuat Oleh</th>
                  <th className="px-4 py-3">Waktu Dibuat</th>
                  <th className="px-4 py-3">Diposting Pada</th>
                  <th className="px-4 py-3 text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td
                      colSpan={9}
                      className="px-4 py-8 text-center text-muted-foreground"
                    >
                      Memuat dokumen penyesuaian...
                    </td>
                  </tr>
                ) : adjustments.length === 0 ? (
                  <tr>
                    <td
                      colSpan={9}
                      className="px-4 py-8 text-center text-muted-foreground"
                    >
                      Belum ada dokumen penyesuaian yang sesuai kriteria.
                    </td>
                  </tr>
                ) : (
                  adjustments.map((row) => {
                    const badge = STATUS_BADGE[row.status] || {
                      label: row.status,
                      variant: "default",
                    };
                    return (
                      <tr
                        key={row.id}
                        className="hover:bg-slate-50/80 transition-colors"
                      >
                        <td className="px-4 py-3 font-mono font-bold text-foreground">
                          {row.adjustment_number}
                        </td>
                        <td className="px-4 py-3">
                          {row.type === "quantity" ? (
                            <span className="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-700 border border-blue-200">
                              Kuantitas Fisik
                            </span>
                          ) : (
                            <span className="inline-flex items-center rounded-md bg-purple-50 px-2 py-0.5 text-xs font-bold text-purple-700 border border-purple-200">
                              Revaluasi HPP
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant={badge.variant}>{badge.label}</Badge>
                        </td>
                        <td className="px-4 py-3 text-xs max-w-xs truncate text-foreground font-medium">
                          {row.reason}
                        </td>
                        <td className="px-4 py-3 text-xs font-mono text-muted-foreground">
                          {row.evidence_reference || "-"}
                        </td>
                        <td className="px-4 py-3 text-xs text-foreground">
                          {row.created_by || "-"}
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {formatDateTime(row.created_at)}
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {formatDateTime(row.posted_at)}
                        </td>
                        <td className="px-4 py-3 text-center">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => handleOpenDetail(row.id)}
                          >
                            Lihat Rincian
                          </Button>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between border-t border-border px-6 py-3 text-xs text-muted-foreground">
              <span>
                Halaman {currentPage} dari {totalPages} ({totalItems} dokumen)
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={currentPage <= 1 || loading}
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                >
                  Sebelumnya
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={currentPage >= totalPages || loading}
                  onClick={() =>
                    setCurrentPage((p) => Math.min(totalPages, p + 1))
                  }
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* MODAL: DETAIL PENYESUAIAN */}
      <Modal
        open={detailModalOpen}
        onClose={() => setDetailModalOpen(false)}
        title={
          selectedAdjustment
            ? `Detail Penyesuaian: ${selectedAdjustment.adjustment_number}`
            : "Rincian Dokumen Penyesuaian"
        }
      >
        {detailLoading ? (
          <div className="py-8 text-center text-sm text-muted-foreground">
            Memuat rincian dokumen...
          </div>
        ) : selectedAdjustment ? (
          <div className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
            {/* Header info */}
            <div className="grid grid-cols-2 gap-3 rounded-lg border border-border bg-slate-50 p-3 text-xs md:grid-cols-4">
              <div>
                <span className="text-muted-foreground block">Tipe:</span>
                <span className="font-semibold">
                  {selectedAdjustment.type === "quantity"
                    ? "Kuantitas Fisik"
                    : "Revaluasi HPP"}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Status:</span>
                <span className="font-semibold">
                  <Badge
                    variant={
                      STATUS_BADGE[selectedAdjustment.status]?.variant ||
                      "default"
                    }
                  >
                    {STATUS_BADGE[selectedAdjustment.status]?.label ||
                      selectedAdjustment.status}
                  </Badge>
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Dibuat Oleh:</span>
                <span className="font-semibold text-foreground">
                  {selectedAdjustment.created_by?.name || "-"}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Diposting Oleh:</span>
                <span className="font-semibold text-foreground">
                  {selectedAdjustment.posted_by?.name || "-"}
                </span>
              </div>
            </div>

            <div className="rounded-lg border border-border p-3 text-xs space-y-1 bg-white">
              <p>
                <span className="font-semibold text-muted-foreground">
                  Alasan Penyesuaian:
                </span>{" "}
                <span className="font-medium text-foreground">
                  {selectedAdjustment.reason}
                </span>
              </p>
              {selectedAdjustment.evidence_reference && (
                <p>
                  <span className="font-semibold text-muted-foreground">
                    Bukti / No. Berita Acara:
                  </span>{" "}
                  <span className="font-mono text-foreground">
                    {selectedAdjustment.evidence_reference}
                  </span>
                </p>
              )}
            </div>

            {/* Items table */}
            <div className="rounded-lg border border-border overflow-hidden">
              <table className="w-full text-left text-xs">
                <thead className="border-b border-border bg-slate-50 text-muted-foreground font-semibold">
                  <tr>
                    <th className="px-3 py-2">No</th>
                    <th className="px-3 py-2">Produk</th>
                    {selectedAdjustment.type === "quantity" ? (
                      <>
                        <th className="px-3 py-2 text-right">Stok Sebelum</th>
                        <th className="px-3 py-2 text-right">Perubahan (Delta)</th>
                        <th className="px-3 py-2 text-right">Stok Sesudah</th>
                        <th className="px-3 py-2 text-right">HPP Saat Ini</th>
                      </>
                    ) : (
                      <>
                        <th className="px-3 py-2 text-right">Stok Fisik</th>
                        <th className="px-3 py-2 text-right">HPP Sebelum</th>
                        <th className="px-3 py-2 text-right">HPP Baru</th>
                        <th className="px-3 py-2 text-right">Selisih HPP</th>
                      </>
                    )}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {selectedAdjustment.items.map((it, idx) => {
                    return (
                      <tr key={it.id} className="hover:bg-slate-50/60">
                        <td className="px-3 py-2 text-muted-foreground">
                          {idx + 1}
                        </td>
                        <td className="px-3 py-2 font-medium">
                          <div>{it.product_name}</div>
                          <div className="text-[10px] text-muted-foreground font-mono">
                            {it.product_sku || "-"}
                          </div>
                        </td>
                        {selectedAdjustment.type === "quantity" ? (
                          <>
                            <td className="px-3 py-2 text-right font-mono">
                              {it.before_stock}
                            </td>
                            <td className="px-3 py-2 text-right font-mono font-bold">
                              {(it.quantity_delta ?? 0) > 0 ? (
                                <span className="text-blue-600">
                                  +{it.quantity_delta}
                                </span>
                              ) : (
                                <span className="text-red-600">
                                  {it.quantity_delta}
                                </span>
                              )}
                            </td>
                            <td className="px-3 py-2 text-right font-mono font-semibold">
                              {it.after_stock}
                            </td>
                            <td className="px-3 py-2 text-right font-mono text-muted-foreground">
                              {formatRupiah(it.current_average_cost ?? 0)}
                            </td>
                          </>
                        ) : (
                          <>
                            <td className="px-3 py-2 text-right font-mono">
                              {it.before_stock}
                            </td>
                            <td className="px-3 py-2 text-right font-mono text-muted-foreground">
                              {formatRupiah(it.current_average_cost ?? 0)}
                            </td>
                            <td className="px-3 py-2 text-right font-mono font-bold text-foreground">
                              {formatRupiah(it.new_average_cost ?? 0)}
                            </td>
                            <td className="px-3 py-2 text-right font-mono">
                              {formatRupiah(
                                (it.new_average_cost ?? 0) -
                                  (it.current_average_cost ?? 0)
                              )}
                            </td>
                          </>
                        )}
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {/* Workflow actions */}
            <div className="flex items-center justify-between pt-2 border-t border-border">
              <div>
                {selectedAdjustment.status === "draft" && canManage && (
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      disabled={actionInProgress}
                      onClick={handleCancel}
                      className="text-red-700 hover:bg-red-50 border-red-200"
                    >
                      Batalkan Draft
                    </Button>
                    <Button
                      variant="primary"
                      disabled={
                        actionInProgress ||
                        (selectedAdjustment.type === "revaluation" && !isOwner)
                      }
                      onClick={handlePost}
                      className="bg-emerald-600 hover:bg-emerald-700 text-white"
                    >
                      {actionInProgress ? "Memposting..." : "Posting Penyesuaian"}
                    </Button>
                  </div>
                )}
              </div>
              <Button
                type="button"
                variant="outline"
                onClick={() => setDetailModalOpen(false)}
              >
                Tutup
              </Button>
            </div>
          </div>
        ) : null}
      </Modal>

      {/* MODAL: CREATE NEW ADJUSTMENT */}
      <Modal
        open={createModalOpen}
        onClose={() => !creating && setCreateModalOpen(false)}
        title="Buat Dokumen Penyesuaian Baru"
      >
        <form onSubmit={handleCreateSubmit} className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
          {/* Type Selector */}
          <div>
            <label className="block text-xs font-semibold text-foreground mb-1">
              Tipe Penyesuaian
            </label>
            <div className="grid grid-cols-2 gap-3">
              <label
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-xs transition-colors ${
                  createType === "quantity"
                    ? "border-primary bg-primary/5 text-foreground"
                    : "border-border hover:bg-slate-50"
                }`}
              >
                <input
                  type="radio"
                  name="adjType"
                  value="quantity"
                  checked={createType === "quantity"}
                  onChange={() => setCreateType("quantity")}
                  className="mt-0.5"
                />
                <div>
                  <span className="font-bold block">Penyesuaian Kuantitas Fisik</span>
                  <span className="text-muted-foreground text-[11px]">
                    Koreksi stok fisik toko karena selisih rusak, kedaluwarsa, atau temuan.
                  </span>
                </div>
              </label>

              <label
                className={`flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-xs transition-colors ${
                  createType === "revaluation"
                    ? "border-primary bg-primary/5 text-foreground"
                    : "border-border hover:bg-slate-50"
                } ${!isOwner ? "opacity-60 cursor-not-allowed" : ""}`}
              >
                <input
                  type="radio"
                  name="adjType"
                  value="revaluation"
                  disabled={!isOwner}
                  checked={createType === "revaluation"}
                  onChange={() => setCreateType("revaluation")}
                  className="mt-0.5"
                />
                <div>
                  <div className="flex items-center gap-1.5">
                    <span className="font-bold block">Revaluasi Biaya Modal (HPP)</span>
                    {!isOwner && (
                      <span className="rounded bg-slate-200 px-1 py-0.2 text-[10px] font-semibold text-slate-700">
                        Owner
                      </span>
                    )}
                  </div>
                  <span className="text-muted-foreground text-[11px]">
                    Memperbarui rata-rata bergerak HPP produk tanpa mengubah jumlah unit.
                  </span>
                </div>
              </label>
            </div>
          </div>

          {/* Reason */}
          <div>
            <label className="block text-xs font-semibold text-foreground mb-1">
              Alasan Penyesuaian <span className="text-red-500">*</span>
            </label>
            <Input
              type="text"
              placeholder="Contoh: Barang rusak saat pengiriman / Ditemukan stok selisih opname"
              value={createReason}
              onChange={(e) => setCreateReason(e.target.value)}
              disabled={creating}
              required
            />
          </div>

          {/* Evidence */}
          <div>
            <label className="block text-xs font-semibold text-foreground mb-1">
              No. Bukti / Berita Acara (Opsional)
            </label>
            <Input
              type="text"
              placeholder="Contoh: BA-RUSAK-2026/10/01"
              value={createEvidence}
              onChange={(e) => setCreateEvidence(e.target.value)}
              disabled={creating}
            />
          </div>

          {/* Lines Builder */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <label className="block text-xs font-bold text-foreground uppercase tracking-wider">
                Daftar Produk yang Disesuaikan ({createLines.length})
              </label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={addLine}
                disabled={creating}
              >
                + Tambah Baris
              </Button>
            </div>

            <div className="space-y-2">
              {createLines.map((line, idx) => {
                const selectedProd = products.find(
                  (p) => p.id === line.product_id
                );

                return (
                  <div
                    key={idx}
                    className="flex flex-col gap-2 rounded-lg border border-border bg-slate-50/50 p-3 text-xs md:flex-row md:items-center"
                  >
                    {/* Product Select */}
                    <div className="flex-1">
                      <label className="block text-[10px] text-muted-foreground uppercase font-semibold mb-0.5">
                        Pilih Produk
                      </label>
                      <select
                        value={line.product_id}
                        onChange={(e) => {
                          const pid = parseInt(e.target.value, 10);
                          const prod = products.find((p) => p.id === pid);
                          updateLine(idx, {
                            product_id: pid,
                            new_average_cost: prod
                              ? Number(prod.average_cost || 0)
                              : 0,
                          });
                        }}
                        className="w-full rounded-md border border-border bg-white px-2 py-1.5 text-xs focus:border-primary focus:outline-hidden"
                      >
                        <option value={0}>-- Pilih Produk Aktif --</option>
                        {products.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.sku} — {p.name} (Stok: {p.stock})
                          </option>
                        ))}
                      </select>
                    </div>

                    {/* Quantity Mode Inputs */}
                    {createType === "quantity" ? (
                      <>
                        <div className="w-24">
                          <label className="block text-[10px] text-muted-foreground uppercase font-semibold mb-0.5">
                            Stok Saat Ini
                          </label>
                          <div className="rounded-md border border-border bg-slate-100 px-2 py-1.5 text-center font-mono font-bold">
                            {selectedProd ? selectedProd.stock : "-"}
                          </div>
                        </div>

                        <div className="w-28">
                          <label className="block text-[10px] text-muted-foreground uppercase font-semibold mb-0.5">
                            Perubahan (Delta)
                          </label>
                          <input
                            type="number"
                            step={1}
                            placeholder="-1 atau +2"
                            value={line.quantity_delta || ""}
                            onChange={(e) =>
                              updateLine(idx, {
                                quantity_delta: parseInt(e.target.value, 10) || 0,
                              })
                            }
                            className="w-full rounded-md border border-border bg-white px-2 py-1.5 text-center font-mono font-bold focus:border-primary focus:outline-hidden"
                          />
                        </div>

                        <div className="w-24">
                          <label className="block text-[10px] text-muted-foreground uppercase font-semibold mb-0.5">
                            Proyeksi Akhir
                          </label>
                          <div
                            className={`rounded-md border border-border px-2 py-1.5 text-center font-mono font-bold ${
                              selectedProd &&
                              selectedProd.stock + line.quantity_delta < 0
                                ? "bg-red-100 text-red-700"
                                : "bg-slate-100 text-foreground"
                            }`}
                          >
                            {selectedProd
                              ? selectedProd.stock + line.quantity_delta
                              : "-"}
                          </div>
                        </div>
                      </>
                    ) : (
                      <>
                        <div className="w-32">
                          <label className="block text-[10px] text-muted-foreground uppercase font-semibold mb-0.5">
                            HPP Saat Ini
                          </label>
                          <div className="rounded-md border border-border bg-slate-100 px-2 py-1.5 text-right font-mono text-xs">
                            {selectedProd
                              ? formatRupiah(Number(selectedProd.average_cost || 0))
                              : "-"}
                          </div>
                        </div>

                        <div className="w-36">
                          <label className="block text-[10px] text-muted-foreground uppercase font-semibold mb-0.5">
                            HPP Baru (IDR)
                          </label>
                          <input
                            type="number"
                            min={0}
                            step={1}
                            placeholder="Contoh: 15000"
                            value={line.new_average_cost || ""}
                            onChange={(e) =>
                              updateLine(idx, {
                                new_average_cost:
                                  parseInt(e.target.value, 10) || 0,
                              })
                            }
                            className="w-full rounded-md border border-border bg-white px-2 py-1.5 text-right font-mono font-bold focus:border-primary focus:outline-hidden"
                          />
                        </div>
                      </>
                    )}

                    {/* Delete Line */}
                    <div className="pt-4 md:pt-3">
                      <button
                        type="button"
                        onClick={() => removeLine(idx)}
                        disabled={createLines.length === 1}
                        className="rounded p-1.5 text-muted-foreground hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
                        title="Hapus baris"
                      >
                        <svg
                          className="h-4 w-4"
                          fill="none"
                          stroke="currentColor"
                          viewBox="0 0 24 24"
                        >
                          <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth={2}
                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                          />
                        </svg>
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          </div>

          <div className="flex justify-end gap-2 pt-3 border-t border-border">
            <Button
              type="button"
              variant="outline"
              disabled={creating}
              onClick={() => setCreateModalOpen(false)}
            >
              Batal
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={creating}
            >
              {creating ? "Menyimpan Draft..." : "Simpan sebagai Draft"}
            </Button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
