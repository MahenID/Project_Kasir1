"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { stocktakesApi } from "@/lib/api/stocktakes";
import type {
  StocktakeDetail,
  StocktakeItem,
  StocktakeRow,
  StocktakeStatus,
} from "@/lib/types";
import { ApiError } from "@/lib/api";
import { formatRupiah } from "@/lib/format";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

const STATUS_BADGE: Record<
  StocktakeStatus,
  { label: string; variant: "default" | "success" | "warning" | "info" | "danger" }
> = {
  counting: { label: "Sedang Dihitung", variant: "warning" },
  posted: { label: "Selesai (Diposting)", variant: "success" },
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

export default function StocktakePage() {
  const { user } = useAuth();
  const [activeStocktake, setActiveStocktake] = useState<StocktakeDetail | null>(null);
  const [history, setHistory] = useState<StocktakeRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Pagination for History
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);

  // Active Session Inputs & Filters
  const [searchQuery, setSearchQuery] = useState("");
  const [filterMode, setFilterMode] = useState<"all" | "uncounted" | "difference">("all");
  const [inputCounts, setInputCounts] = useState<Record<number, string>>({});
  const [savingRowId, setSavingRowId] = useState<number | null>(null);
  const [bulkSaving, setBulkSaving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);

  // Modals
  const [startModalOpen, setStartModalOpen] = useState(false);
  const [startNotes, setStartNotes] = useState("");
  const [starting, setStarting] = useState(false);

  const [postModalOpen, setPostModalOpen] = useState(false);
  const [posting, setPosting] = useState(false);

  const [cancelModalOpen, setCancelModalOpen] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const [detailModalOpen, setDetailModalOpen] = useState(false);
  const [detailStocktake, setDetailStocktake] = useState<StocktakeDetail | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const isSupervisor =
    user?.roles?.includes("owner") || user?.roles?.includes("manager");

  // Load Active Stocktake & History
  const loadData = useCallback(async () => {
    if (!isSupervisor) return;
    setLoading(true);
    setError(null);
    try {
      const [activeRes, historyRes] = await Promise.all([
        stocktakesApi.active(),
        stocktakesApi.list({ page: currentPage, per_page: 15 }),
      ]);

      setActiveStocktake(activeRes);
      if (activeRes) {
        // Initialize inputCounts with existing counted_stock
        const initialMap: Record<number, string> = {};
        for (const item of activeRes.items) {
          if (item.counted_stock !== null) {
            initialMap[item.product_id] = String(item.counted_stock);
          }
        }
        setInputCounts(initialMap);
      }

      setHistory(historyRes.data);
      if (historyRes.meta.pagination) {
        setTotalPages(historyRes.meta.pagination.last_page);
        setTotalItems(historyRes.meta.pagination.total);
      }
    } catch (err) {
      if (err instanceof ApiError) {
        setError(err.message);
      } else {
        setError("Gagal memuat data stocktake.");
      }
    } finally {
      setLoading(false);
    }
  }, [isSupervisor, currentPage]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadData();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadData]);

  // Handle Input Change for Count
  const handleCountChange = (productId: number, val: string) => {
    setInputCounts((prev) => ({
      ...prev,
      [productId]: val,
    }));
  };

  // Save Single Count
  const handleSaveSingleCount = async (item: StocktakeItem) => {
    if (!activeStocktake) return;
    const rawVal = inputCounts[item.product_id];
    if (rawVal === undefined || rawVal === "") {
      setActionError(`Masukkan jumlah fisik untuk ${item.product_name || "produk"}.`);
      return;
    }
    const parsed = parseInt(rawVal, 10);
    if (isNaN(parsed) || parsed < 0) {
      setActionError("Jumlah fisik harus berupa angka bulat >= 0.");
      return;
    }

    setSavingRowId(item.product_id);
    setActionError(null);
    setActionSuccess(null);
    try {
      const updated = await stocktakesApi.count(
        activeStocktake.id,
        item.product_id,
        parsed
      );
      // Update activeStocktake state locally
      setActiveStocktake((prev) => {
        if (!prev) return prev;
        const newItems = prev.items.map((it) => {
          if (it.product_id === item.product_id) {
            return {
              ...it,
              counted_stock: updated.counted_stock,
              difference: updated.difference,
            };
          }
          return it;
        });

        const countedCount = newItems.filter((i) => i.counted_stock !== null).length;
        const diffCount = newItems.filter(
          (i) => i.difference !== null && i.difference !== 0
        ).length;
        const netDiff = newItems.reduce(
          (acc, cur) => acc + (cur.difference ?? 0),
          0
        );

        return {
          ...prev,
          items: newItems,
          summary: {
            ...prev.summary,
            counted: countedCount,
            with_difference: diffCount,
            net_difference: netDiff,
          },
        };
      });
      setActionSuccess(`Hitungan untuk "${item.product_name}" tersimpan.`);
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal menyimpan hitungan.");
      }
    } finally {
      setSavingRowId(null);
    }
  };

  // Save Bulk Counts
  const handleSaveBulkCounts = async () => {
    if (!activeStocktake) return;
    const countsPayload: Array<{ product_id: number; counted_stock: number }> = [];

    for (const item of activeStocktake.items) {
      const valStr = inputCounts[item.product_id];
      if (valStr !== undefined && valStr !== "") {
        const parsed = parseInt(valStr, 10);
        if (!isNaN(parsed) && parsed >= 0) {
          countsPayload.push({
            product_id: item.product_id,
            counted_stock: parsed,
          });
        }
      }
    }

    if (countsPayload.length === 0) {
      setActionError("Belum ada angka hitungan fisik yang diinput.");
      return;
    }

    setBulkSaving(true);
    setActionError(null);
    setActionSuccess(null);
    try {
      const updated = await stocktakesApi.bulkCount(activeStocktake.id, countsPayload);
      setActiveStocktake(updated);
      setActionSuccess(`Berhasil menyimpan ${countsPayload.length} hitungan fisik.`);
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal menyimpan hitungan serentak.");
      }
    } finally {
      setBulkSaving(false);
    }
  };

  // Fill All Remaining to Match Snapshot (Convenience)
  const handleAutoFillRemaining = () => {
    if (!activeStocktake) return;
    const newMap = { ...inputCounts };
    let filledCount = 0;
    for (const item of activeStocktake.items) {
      if (newMap[item.product_id] === undefined || newMap[item.product_id] === "") {
        newMap[item.product_id] = String(item.snapshot_stock);
        filledCount++;
      }
    }
    setInputCounts(newMap);
    setActionSuccess(
      `${filledCount} produk yang belum dihitung telah diisi sesuai stok sistem. Klik "Simpan Semua Hitungan" untuk mencatat.`
    );
  };

  // Start Stocktake Action
  const handleStartStocktake = async (e: React.FormEvent) => {
    e.preventDefault();
    setStarting(true);
    setActionError(null);
    try {
      const res = await stocktakesApi.start({ notes: startNotes.trim() || undefined });
      setStartModalOpen(false);
      setStartNotes("");
      setActiveStocktake(res);
      const initialMap: Record<number, string> = {};
      for (const item of res.items) {
        if (item.counted_stock !== null) {
          initialMap[item.product_id] = String(item.counted_stock);
        }
      }
      setInputCounts(initialMap);
      setActionSuccess(
        `Audit fisik ${res.stocktake_number} dimulai! Toko saat ini dalam status jeda (frozen).`
      );
      loadData();
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal memulai stocktake.");
      }
    } finally {
      setStarting(false);
    }
  };

  // Post Stocktake Action
  const handlePostStocktake = async () => {
    if (!activeStocktake) return;
    setPosting(true);
    setActionError(null);
    try {
      await stocktakesApi.post(activeStocktake.id);
      setPostModalOpen(false);
      setActiveStocktake(null);
      setInputCounts({});
      setActionSuccess(
        `Stocktake ${activeStocktake.stocktake_number} berhasil diposting! Selisih stok telah disesuaikan dan toko kembali dibuka.`
      );
      loadData();
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal memposting stocktake.");
      }
    } finally {
      setPosting(false);
    }
  };

  // Cancel Stocktake Action
  const handleCancelStocktake = async () => {
    if (!activeStocktake) return;
    setCancelling(true);
    setActionError(null);
    try {
      await stocktakesApi.cancel(activeStocktake.id);
      setCancelModalOpen(false);
      setActiveStocktake(null);
      setInputCounts({});
      setActionSuccess(
        `Stocktake ${activeStocktake.stocktake_number} dibatalkan. Tidak ada perubahan stok fisik dan jeda toko dibuka.`
      );
      loadData();
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal membatalkan stocktake.");
      }
    } finally {
      setCancelling(false);
    }
  };

  // View Detail of Past Stocktake
  const handleOpenDetail = async (id: number) => {
    setDetailLoading(true);
    setDetailStocktake(null);
    setDetailModalOpen(true);
    try {
      const res = await stocktakesApi.get(id);
      setDetailStocktake(res);
    } catch (err) {
      if (err instanceof ApiError) {
        setActionError(err.message);
      } else {
        setActionError("Gagal memuat rincian stocktake.");
      }
    } finally {
      setDetailLoading(false);
    }
  };

  if (!isSupervisor) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <div className="max-w-md text-center">
          <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-amber-100 text-amber-700">
            <svg
              className="h-8 w-8"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                strokeLinecap="round"
                strokeLinejoin="round"
                strokeWidth={2}
                d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
              />
            </svg>
          </div>
          <h2 className="text-xl font-bold">Akses Terbatas Supervisor</h2>
          <p className="mt-2 text-sm text-muted-foreground">
            Halaman Opname Stok (Stocktake) hanya dapat diakses oleh Manajer atau Owner toko.
          </p>
        </div>
      </div>
    );
  }

  // Filter items in active session
  const filteredActiveItems = activeStocktake?.items.filter((item) => {
    const q = searchQuery.toLowerCase().trim();
    const matchQuery =
      !q ||
      item.product_name?.toLowerCase().includes(q) ||
      item.product_sku?.toLowerCase().includes(q);

    if (!matchQuery) return false;

    if (filterMode === "uncounted") {
      return item.counted_stock === null;
    }
    if (filterMode === "difference") {
      return item.difference !== null && item.difference !== 0;
    }
    return true;
  }) ?? [];

  const uncountedCount =
    activeStocktake?.items.filter((i) => i.counted_stock === null).length ?? 0;
  const canPost = activeStocktake && uncountedCount === 0;

  return (
    <div className="flex h-full flex-col bg-slate-50 overflow-y-auto">
      {/* Top Header */}
      <div className="border-b border-border bg-surface px-6 py-4 shadow-2xs">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-xl font-bold tracking-tight text-foreground">
                Audit Stok Fisik (Stocktake)
              </h1>
              {activeStocktake ? (
                <Badge variant="warning">Toko Sedang Dibekukan</Badge>
              ) : (
                <Badge variant="success">Toko Operasional Normal</Badge>
              )}
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Rekonsiliasi stok fisik versus sistem. Selama sesi audit aktif, mutasi kasir &amp; penerimaan barang dijeda secara atomik.
            </p>
          </div>

          <div className="flex items-center gap-2">
            {!activeStocktake ? (
              <Button
                variant="primary"
                onClick={() => {
                  setActionError(null);
                  setActionSuccess(null);
                  setStartModalOpen(true);
                }}
              >
                + Mulai Stocktake Baru
              </Button>
            ) : (
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  onClick={() => setCancelModalOpen(true)}
                  className="text-red-700 hover:bg-red-50 border-red-200"
                >
                  Batalkan Sesi
                </Button>
                <Button
                  variant="primary"
                  onClick={() => setPostModalOpen(true)}
                  className="bg-emerald-600 hover:bg-emerald-700"
                >
                  Selesaikan &amp; Posting
                </Button>
              </div>
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
        {/* ACTIVE STOCKTAKE WORKSPACE */}
        {activeStocktake ? (
          <div className="rounded-xl border border-amber-300 bg-white shadow-sm overflow-hidden">
            {/* Active Header Banner */}
            <div className="bg-gradient-to-r from-amber-500 to-amber-600 px-6 py-4 text-white">
              <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                  <div className="flex items-center gap-2">
                    <span className="flex h-3 w-3 rounded-full bg-white animate-ping" />
                    <h2 className="text-lg font-bold">
                      Sesi Aktif: {activeStocktake.stocktake_number}
                    </h2>
                  </div>
                  <p className="text-xs text-amber-100 mt-1">
                    Dimulai oleh <span className="font-semibold">{activeStocktake.started_by?.name || "Petugas"}</span> pada {formatDateTime(activeStocktake.started_at)}
                    {activeStocktake.notes && ` • Catatan: "${activeStocktake.notes}"`}
                  </p>
                </div>
                <div className="flex items-center gap-2">
                  <span className="rounded-md bg-amber-700/60 px-3 py-1.5 text-xs font-semibold tracking-wide uppercase">
                    Status: Counting
                  </span>
                </div>
              </div>
            </div>

            {/* KPI Cards */}
            <div className="grid grid-cols-2 gap-4 border-b border-border bg-slate-50/50 p-6 md:grid-cols-4">
              <div className="rounded-lg border border-border bg-white p-4 shadow-2xs">
                <p className="text-xs font-medium text-muted-foreground uppercase">Total Produk</p>
                <p className="mt-1 text-2xl font-bold text-foreground">
                  {activeStocktake.summary.total_products}
                </p>
                <p className="text-xs text-muted-foreground mt-0.5">Produk aktif dalam snapshot</p>
              </div>

              <div className="rounded-lg border border-border bg-white p-4 shadow-2xs">
                <p className="text-xs font-medium text-muted-foreground uppercase">Progres Hitung</p>
                <div className="flex items-baseline gap-2 mt-1">
                  <p className="text-2xl font-bold text-foreground">
                    {activeStocktake.summary.counted}
                  </p>
                  <p className="text-xs font-medium text-muted-foreground">
                    / {activeStocktake.summary.total_products}
                  </p>
                </div>
                <div className="mt-2 h-1.5 w-full rounded-full bg-slate-100 overflow-hidden">
                  <div
                    className="h-full bg-emerald-500 transition-all duration-300"
                    style={{
                      width: `${
                        activeStocktake.summary.total_products > 0
                          ? (activeStocktake.summary.counted /
                              activeStocktake.summary.total_products) *
                            100
                          : 0
                      }%`,
                    }}
                  />
                </div>
              </div>

              <div className="rounded-lg border border-border bg-white p-4 shadow-2xs">
                <p className="text-xs font-medium text-muted-foreground uppercase">Ada Selisih</p>
                <p className="mt-1 text-2xl font-bold text-amber-600">
                  {activeStocktake.summary.with_difference}
                </p>
                <p className="text-xs text-muted-foreground mt-0.5">Item tidak cocok dengan sistem</p>
              </div>

              <div className="rounded-lg border border-border bg-white p-4 shadow-2xs">
                <p className="text-xs font-medium text-muted-foreground uppercase">Net Selisih Unit</p>
                <p
                  className={`mt-1 text-2xl font-bold ${
                    activeStocktake.summary.net_difference < 0
                      ? "text-red-600"
                      : activeStocktake.summary.net_difference > 0
                      ? "text-blue-600"
                      : "text-emerald-600"
                  }`}
                >
                  {activeStocktake.summary.net_difference > 0 ? "+" : ""}
                  {activeStocktake.summary.net_difference}
                </p>
                <p className="text-xs text-muted-foreground mt-0.5">
                  {activeStocktake.summary.net_difference < 0
                    ? "Defisit fisik (kurang)"
                    : activeStocktake.summary.net_difference > 0
                    ? "Surplus fisik (lebih)"
                    : "Sesuai sempurna"}
                </p>
              </div>
            </div>

            {/* Filter & Counting Toolbar */}
            <div className="flex flex-col gap-3 border-b border-border p-4 md:flex-row md:items-center md:justify-between">
              <div className="flex flex-1 items-center gap-3">
                <div className="relative flex-1 max-w-sm">
                  <Input
                    type="search"
                    placeholder="Cari nama produk atau SKU..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="pl-3"
                  />
                </div>
                <div className="flex items-center gap-1 rounded-lg border border-border bg-slate-100 p-1 text-xs">
                  <button
                    type="button"
                    onClick={() => setFilterMode("all")}
                    className={`rounded-md px-3 py-1 font-medium transition-colors ${
                      filterMode === "all"
                        ? "bg-white text-foreground shadow-2xs"
                        : "text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    Semua ({activeStocktake.items.length})
                  </button>
                  <button
                    type="button"
                    onClick={() => setFilterMode("uncounted")}
                    className={`rounded-md px-3 py-1 font-medium transition-colors ${
                      filterMode === "uncounted"
                        ? "bg-white text-foreground shadow-2xs"
                        : "text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    Belum Dihitung ({uncountedCount})
                  </button>
                  <button
                    type="button"
                    onClick={() => setFilterMode("difference")}
                    className={`rounded-md px-3 py-1 font-medium transition-colors ${
                      filterMode === "difference"
                        ? "bg-white text-foreground shadow-2xs"
                        : "text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    Ada Selisih ({activeStocktake.summary.with_difference})
                  </button>
                </div>
              </div>

              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  onClick={handleAutoFillRemaining}
                  disabled={uncountedCount === 0 || bulkSaving}
                  title="Otomatis isi produk yang belum dihitung sama dengan stok sistem"
                >
                  Set Sesuai Sistem ({uncountedCount})
                </Button>
                <Button
                  variant="primary"
                  onClick={handleSaveBulkCounts}
                  disabled={bulkSaving}
                >
                  {bulkSaving ? "Menyimpan..." : "Simpan Semua Hitungan"}
                </Button>
              </div>
            </div>

            {/* Counting Table */}
            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                  <tr>
                    <th className="px-4 py-3">No</th>
                    <th className="px-4 py-3">SKU</th>
                    <th className="px-4 py-3">Nama Produk</th>
                    <th className="px-4 py-3 text-right">Stok Sistem</th>
                    <th className="px-4 py-3 text-center w-40">Fisik Terhitung</th>
                    <th className="px-4 py-3 text-right">Selisih Unit</th>
                    <th className="px-4 py-3 text-right">HPP Snapshot</th>
                    <th className="px-4 py-3 text-right">Estimasi Nilai Selisih</th>
                    <th className="px-4 py-3 text-center">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {filteredActiveItems.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">
                        Tidak ada produk yang cocok dengan filter.
                      </td>
                    </tr>
                  ) : (
                    filteredActiveItems.map((item, idx) => {
                      const currentVal = inputCounts[item.product_id] ?? "";
                      const isUncounted = item.counted_stock === null;
                      const hasDiff = item.difference !== null && item.difference !== 0;
                      const isSaving = savingRowId === item.product_id;
                      const diffValue =
                        item.difference !== null
                          ? item.difference * item.snapshot_average_cost
                          : 0;

                      return (
                        <tr
                          key={item.id}
                          className={`hover:bg-slate-50/80 transition-colors ${
                            isUncounted
                              ? "bg-amber-50/20"
                              : hasDiff
                              ? "bg-red-50/10"
                              : ""
                          }`}
                        >
                          <td className="px-4 py-3 text-xs text-muted-foreground">
                            {idx + 1}
                          </td>
                          <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                            {item.product_sku || "-"}
                          </td>
                          <td className="px-4 py-3 font-medium text-foreground">
                            {item.product_name}
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-semibold">
                            {item.snapshot_stock}
                          </td>
                          <td className="px-4 py-2 text-center">
                            <div className="flex items-center justify-center gap-1">
                              <input
                                type="number"
                                min={0}
                                step={1}
                                value={currentVal}
                                onChange={(e) =>
                                  handleCountChange(item.product_id, e.target.value)
                                }
                                onKeyDown={(e) => {
                                  if (e.key === "Enter") {
                                    handleSaveSingleCount(item);
                                  }
                                }}
                                placeholder="0"
                                className="w-24 rounded-lg border border-border px-3 py-1.5 text-center font-mono text-sm font-bold focus:border-primary focus:outline-hidden focus:ring-1 focus:ring-primary"
                              />
                            </div>
                          </td>
                          <td className="px-4 py-3 text-right">
                            {isUncounted ? (
                              <Badge variant="warning">Belum dihitung</Badge>
                            ) : item.difference === 0 ? (
                              <Badge variant="success">Pas (0)</Badge>
                            ) : item.difference! > 0 ? (
                              <span className="inline-flex items-center rounded-md bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-700 border border-blue-200">
                                +{item.difference}
                              </span>
                            ) : (
                              <span className="inline-flex items-center rounded-md bg-red-50 px-2 py-0.5 text-xs font-bold text-red-700 border border-red-200">
                                {item.difference}
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-xs text-muted-foreground">
                            {formatRupiah(item.snapshot_average_cost)}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-xs">
                            {isUncounted ? (
                              "-"
                            ) : (
                              <span
                                className={`font-semibold ${
                                  diffValue < 0
                                    ? "text-red-600"
                                    : diffValue > 0
                                    ? "text-blue-600"
                                    : "text-muted-foreground"
                                }`}
                              >
                                {diffValue > 0 ? "+" : ""}
                                {formatRupiah(diffValue)}
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-2 text-center">
                            <Button
                              variant="outline"
                              size="sm"
                              disabled={isSaving || currentVal === ""}
                              onClick={() => handleSaveSingleCount(item)}
                            >
                              {isSaving ? "..." : "Simpan"}
                            </Button>
                          </td>
                        </tr>
                      );
                    })
                  )}
                </tbody>
              </table>
            </div>

            {/* Bottom Actions Notice */}
            <div className="flex flex-col gap-2 border-t border-border bg-slate-50 p-4 text-xs text-muted-foreground md:flex-row md:items-center md:justify-between">
              <div>
                <span>Tekan <kbd className="rounded border bg-white px-1">Enter</kbd> pada kolom hitungan untuk menyimpan baris secara cepat.</span>
              </div>
              <div className="flex items-center gap-3">
                {uncountedCount > 0 ? (
                  <span className="font-semibold text-amber-700">
                    ⚠️ Masih ada {uncountedCount} produk belum dihitung sebelum dapat diposting.
                  </span>
                ) : (
                  <span className="font-semibold text-emerald-700">
                    ✓ Semua produk ({activeStocktake.items.length}) telah selesai dihitung. Siap diposting!
                  </span>
                )}
              </div>
            </div>
          </div>
        ) : null}

        {/* HISTORICAL STOCKTAKES LIST */}
        <div className="rounded-xl border border-border bg-surface shadow-2xs overflow-hidden">
          <div className="border-b border-border px-6 py-4">
            <h2 className="text-base font-bold text-foreground">
              Riwayat Audit Stok Fisik
            </h2>
            <p className="text-xs text-muted-foreground mt-0.5">
              Daftar dokumen opname stok fisik yang telah selesai diposting atau dibatalkan.
            </p>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                <tr>
                  <th className="px-4 py-3">No. Dokumen</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Dimulai Oleh</th>
                  <th className="px-4 py-3">Waktu Mulai</th>
                  <th className="px-4 py-3">Diposting Oleh</th>
                  <th className="px-4 py-3">Waktu Selesai</th>
                  <th className="px-4 py-3 text-center">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                      Memuat data stocktake...
                    </td>
                  </tr>
                ) : history.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                      Belum ada riwayat stocktake yang tersimpan.
                    </td>
                  </tr>
                ) : (
                  history.map((row) => {
                    const badge = STATUS_BADGE[row.status] || {
                      label: row.status,
                      variant: "default",
                    };
                    return (
                      <tr key={row.id} className="hover:bg-slate-50/80 transition-colors">
                        <td className="px-4 py-3 font-mono font-bold text-foreground">
                          {row.stocktake_number}
                        </td>
                        <td className="px-4 py-3">
                          <Badge variant={badge.variant}>{badge.label}</Badge>
                        </td>
                        <td className="px-4 py-3 text-xs text-foreground">
                          {row.started_by || "-"}
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {formatDateTime(row.started_at)}
                        </td>
                        <td className="px-4 py-3 text-xs text-foreground">
                          {row.posted_by || "-"}
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {formatDateTime(row.posted_at || row.cancelled_at)}
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
                Menampilkan halaman {currentPage} dari {totalPages} ({totalItems} dokumen)
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
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* MODAL: START STOCKTAKE */}
      <Modal
        open={startModalOpen}
        onClose={() => !starting && setStartModalOpen(false)}
        title="Mulai Audit Stok Fisik Baru (Stocktake)"
      >
        <form onSubmit={handleStartStocktake} className="space-y-4">
          <div className="rounded-lg border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900 space-y-2">
            <div className="flex items-center gap-2 font-bold text-amber-800">
              <svg className="h-5 w-5 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path
                  fillRule="evenodd"
                  d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                  clipRule="evenodd"
                />
              </svg>
              <span>PERHATIAN: OPERASIONAL STOK TOKO AKAN DIBEKUKAN</span>
            </div>
            <p>
              Memulai proses audit fisik akan mengambil snapshot seluruh produk aktif dan mengaktifkan <strong>jeda toko (inventory freeze)</strong>.
            </p>
            <ul className="list-disc pl-5 space-y-1">
              <li>Kasir tidak dapat melakukan transaksi penjualan di POS.</li>
              <li>Penerimaan barang dan penyesuaian stok manual akan ditolak.</li>
              <li>Status beku akan berlangsung sampai Anda menyelesaikan posting atau membatalkan sesi ini.</li>
            </ul>
          </div>

          <div>
            <label className="block text-xs font-medium text-foreground mb-1">
              Catatan Sesi Audit (Opsional)
            </label>
            <Input
              type="text"
              placeholder="Contoh: Audit Fisik Akhir Bulan / Pengecekan Rak Makanan"
              value={startNotes}
              onChange={(e) => setStartNotes(e.target.value)}
              disabled={starting}
            />
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              disabled={starting}
              onClick={() => setStartModalOpen(false)}
            >
              Batal
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={starting}
              className="bg-amber-600 hover:bg-amber-700 text-white"
            >
              {starting ? "Memulai..." : "Mulai Audit Fisik & Bekukan Toko"}
            </Button>
          </div>
        </form>
      </Modal>

      {/* MODAL: POST CONFIRMATION */}
      <Modal
        open={postModalOpen}
        onClose={() => !posting && setPostModalOpen(false)}
        title="Konfirmasi Selesaikan & Posting Opname"
      >
        <div className="space-y-4">
          <p className="text-sm text-foreground">
            Apakah Anda yakin ingin memposting hasil audit fisik untuk{" "}
            <strong>{activeStocktake?.stocktake_number}</strong>?
          </p>

          <div className="rounded-lg border border-border bg-slate-50 p-4 text-xs space-y-2">
            <div className="flex justify-between">
              <span className="text-muted-foreground">Total Produk:</span>
              <span className="font-semibold">{activeStocktake?.summary.total_products} produk</span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Produk Ada Selisih:</span>
              <span className="font-semibold text-amber-600">
                {activeStocktake?.summary.with_difference} produk
              </span>
            </div>
            <div className="flex justify-between">
              <span className="text-muted-foreground">Net Selisih Unit:</span>
              <span
                className={`font-semibold ${
                  (activeStocktake?.summary.net_difference ?? 0) < 0
                    ? "text-red-600"
                    : (activeStocktake?.summary.net_difference ?? 0) > 0
                    ? "text-blue-600"
                    : "text-emerald-600"
                }`}
              >
                {(activeStocktake?.summary.net_difference ?? 0) > 0 ? "+" : ""}
                {activeStocktake?.summary.net_difference} unit
              </span>
            </div>
          </div>

          <div className="rounded-lg bg-emerald-50 p-3 text-xs text-emerald-800 border border-emerald-200">
            ✓ Setelah diposting, kartu stok dan fisik toko akan diperbarui otomatis, dan status beku toko akan dibuka kembali.
          </div>

          {!canPost && (
            <div className="rounded-lg bg-red-50 p-3 text-xs text-red-800 border border-red-200">
              ⚠️ Terdapat <strong>{uncountedCount}</strong> produk yang belum dihitung fisiknya. Sistem menolak posting jika ada produk yang belum diisi.
            </div>
          )}

          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              disabled={posting}
              onClick={() => setPostModalOpen(false)}
            >
              Kembali
            </Button>
            <Button
              type="button"
              variant="primary"
              disabled={posting || !canPost}
              onClick={handlePostStocktake}
              className="bg-emerald-600 hover:bg-emerald-700 text-white"
            >
              {posting ? "Memposting..." : "Posting & Buka Jeda Toko"}
            </Button>
          </div>
        </div>
      </Modal>

      {/* MODAL: CANCEL CONFIRMATION */}
      <Modal
        open={cancelModalOpen}
        onClose={() => !cancelling && setCancelModalOpen(false)}
        title="Batalkan Sesi Opname Stok"
      >
        <div className="space-y-4">
          <div className="rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-900 space-y-2">
            <p className="font-bold">PERHATIAN: PEMBATALAN TIDAK DAPAT DIURUNGKAN</p>
            <p>
              Membatalkan stocktake <strong>{activeStocktake?.stocktake_number}</strong> akan membuang semua angka hitungan fisik yang telah diinput.
            </p>
            <p>
              Stok fisik toko <strong>TIDAK AKAN BERUBAH</strong>, dan jeda pembekuan toko akan segera dibuka kembali untuk operasional kasir normal.
            </p>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              disabled={cancelling}
              onClick={() => setCancelModalOpen(false)}
            >
              Kembali
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={cancelling}
              onClick={handleCancelStocktake}
              className="bg-red-600 hover:bg-red-700 text-white border-transparent"
            >
              {cancelling ? "Membatalkan..." : "Ya, Batalkan Stocktake"}
            </Button>
          </div>
        </div>
      </Modal>

      {/* MODAL: DETAIL PAST STOCKTAKE */}
      <Modal
        open={detailModalOpen}
        onClose={() => setDetailModalOpen(false)}
        title={
          detailStocktake
            ? `Detail Stocktake: ${detailStocktake.stocktake_number}`
            : "Rincian Dokumen Stocktake"
        }
      >
        {detailLoading ? (
          <div className="py-8 text-center text-sm text-muted-foreground">
            Memuat rincian dokumen...
          </div>
        ) : detailStocktake ? (
          <div className="space-y-4 max-h-[75vh] overflow-y-auto pr-1">
            {/* Header Details */}
            <div className="grid grid-cols-2 gap-3 rounded-lg border border-border bg-slate-50 p-3 text-xs md:grid-cols-4">
              <div>
                <span className="text-muted-foreground block">Status:</span>
                <span className="font-semibold capitalize">
                  <Badge
                    variant={
                      STATUS_BADGE[detailStocktake.status]?.variant || "default"
                    }
                  >
                    {STATUS_BADGE[detailStocktake.status]?.label ||
                      detailStocktake.status}
                  </Badge>
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Dimulai Oleh:</span>
                <span className="font-semibold text-foreground">
                  {detailStocktake.started_by?.name || "-"}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Diposting Oleh:</span>
                <span className="font-semibold text-foreground">
                  {detailStocktake.posted_by?.name || "-"}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Waktu Selesai:</span>
                <span className="font-semibold text-foreground">
                  {formatDateTime(
                    detailStocktake.posted_at || detailStocktake.cancelled_at
                  )}
                </span>
              </div>
            </div>

            {detailStocktake.notes && (
              <div className="text-xs text-muted-foreground">
                <span className="font-semibold">Catatan:</span> {detailStocktake.notes}
              </div>
            )}

            {/* Summary KPI */}
            <div className="grid grid-cols-3 gap-2 rounded-lg border border-border p-3 text-center text-xs">
              <div>
                <span className="text-muted-foreground block">Total Produk:</span>
                <span className="font-bold text-sm">
                  {detailStocktake.summary.total_products}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Produk Selisih:</span>
                <span className="font-bold text-sm text-amber-600">
                  {detailStocktake.summary.with_difference}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Net Selisih:</span>
                <span
                  className={`font-bold text-sm ${
                    detailStocktake.summary.net_difference < 0
                      ? "text-red-600"
                      : detailStocktake.summary.net_difference > 0
                      ? "text-blue-600"
                      : "text-emerald-600"
                  }`}
                >
                  {detailStocktake.summary.net_difference > 0 ? "+" : ""}
                  {detailStocktake.summary.net_difference} unit
                </span>
              </div>
            </div>

            {/* Items Table */}
            <div className="rounded-lg border border-border overflow-hidden">
              <table className="w-full text-left text-xs">
                <thead className="border-b border-border bg-slate-50 text-muted-foreground font-semibold">
                  <tr>
                    <th className="px-3 py-2">No</th>
                    <th className="px-3 py-2">Produk</th>
                    <th className="px-3 py-2 text-right">Stok Sistem</th>
                    <th className="px-3 py-2 text-right">Fisik</th>
                    <th className="px-3 py-2 text-right">Selisih</th>
                    <th className="px-3 py-2 text-right">HPP</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {detailStocktake.items.map((it, idx) => (
                    <tr
                      key={it.id}
                      className={
                        it.difference !== 0 && it.difference !== null
                          ? "bg-amber-50/20"
                          : ""
                      }
                    >
                      <td className="px-3 py-2 text-muted-foreground">{idx + 1}</td>
                      <td className="px-3 py-2 font-medium">
                        <div>{it.product_name}</div>
                        <div className="text-[10px] text-muted-foreground font-mono">
                          {it.product_sku || "-"}
                        </div>
                      </td>
                      <td className="px-3 py-2 text-right font-mono">
                        {it.snapshot_stock}
                      </td>
                      <td className="px-3 py-2 text-right font-mono font-bold">
                        {it.counted_stock ?? "-"}
                      </td>
                      <td className="px-3 py-2 text-right font-mono">
                        {it.difference === null ? (
                          "-"
                        ) : it.difference === 0 ? (
                          <span className="text-emerald-600 font-semibold">0</span>
                        ) : it.difference > 0 ? (
                          <span className="text-blue-600 font-semibold">
                            +{it.difference}
                          </span>
                        ) : (
                          <span className="text-red-600 font-semibold">
                            {it.difference}
                          </span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-right font-mono text-muted-foreground">
                        {formatRupiah(it.snapshot_average_cost)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="flex justify-end pt-2">
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
    </div>
  );
}
