"use client";

import { useCallback, useEffect, useState } from "react";
import { auditApi } from "@/lib/api/reports";
import type { AuditEvent } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

const PER_PAGE = 25;

const LOG_CATEGORIES: Array<{ value: string; label: string }> = [
  { value: "sales", label: "Penjualan & Kasir (Sales)" },
  { value: "returns", label: "Retur & Refund" },
  { value: "shifts", label: "Sesi Shift Kasir" },
  { value: "cash", label: "Mutasi Kas (Cash In/Out)" },
  { value: "receiving", label: "Penerimaan Stok" },
  { value: "stocktake", label: "Opname Stok (Freeze)" },
  { value: "inventory_adjustments", label: "Penyesuaian Stok & HPP" },
  { value: "products", label: "Master Produk" },
  { value: "exports", label: "Pekerjaan Ekspor" },
  { value: "auth", label: "Autentikasi & Sesi" },
  { value: "users", label: "Manajemen Pengguna" },
];

function formatDateTime(iso: string | null | undefined): string {
  if (!iso) return "-";
  try {
    const d = new Date(iso);
    return d.toLocaleString("id-ID", {
      dateStyle: "medium",
      timeStyle: "medium",
      timeZone: "Asia/Makassar",
    });
  } catch {
    return iso;
  }
}

export default function AuditPage() {
  const { user } = useAuth();
  const isOwner = user !== null && user.roles.includes("owner");
  const isManager = user !== null && user.roles.includes("manager");

  if (!isOwner && !isManager) {
    return (
      <div className="flex h-full items-center justify-center p-6 text-sm text-muted-foreground">
        Audit log domain hanya dapat diakses oleh Owner atau Manajer.
      </div>
    );
  }

  return <AuditContent isOwner={Boolean(isOwner)} />;
}

function AuditContent({ isOwner }: { isOwner: boolean }) {
  const [items, setItems] = useState<AuditEvent[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [totalItems, setTotalItems] = useState(0);
  const [logName, setLogName] = useState("");
  const [eventQuery, setEventQuery] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detail, setDetail] = useState<AuditEvent | null>(null);

  const load = useCallback(
    async (p: number, log?: string, event?: string) => {
      setLoading(true);
      setError(null);
      try {
        const res = await auditApi.list({
          per_page: PER_PAGE,
          page: p,
          log_name: log || undefined,
          event: event?.trim() || undefined,
        });
        setItems(res.data);
        const pag = res.meta.pagination;
        setPage(pag?.current_page ?? 1);
        setLastPage(pag?.last_page ?? 1);
        setTotalItems(pag?.total ?? 0);
      } catch (err) {
        setError(
          err instanceof ApiError ? err.message : "Gagal memuat jejak audit log."
        );
      } finally {
        setLoading(false);
      }
    },
    []
  );

  useEffect(() => {
    const timer = setTimeout(() => {
      load(1, logName, eventQuery);
    }, 0);
    return () => clearTimeout(timer);
  }, [load, logName, eventQuery]);

  const jsonPreview = (obj: Record<string, unknown>): string =>
    JSON.stringify(obj, null, 2);

  return (
    <div className="flex h-full flex-col bg-slate-50 overflow-y-auto">
      {/* Header */}
      <div className="border-b border-border bg-surface px-6 py-4 shadow-2xs">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-xl font-bold tracking-tight text-foreground">
                Audit Trail Explorer
              </h1>
              <Badge variant="info">Read-Only Immutable</Badge>
            </div>
            <p className="text-xs text-muted-foreground mt-0.5">
              Jejak seluruh peristiwa mutasi komersial, operasional shift, dan aksi pengguna yang terekam permanen.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => load(page, logName, eventQuery)}
              disabled={loading}
            >
              {loading ? "Menyegarkan..." : "Segarkan Log"}
            </Button>
          </div>
        </div>

        {error && (
          <div className="mt-3 flex items-center justify-between rounded-lg bg-red-50 p-3 text-xs font-semibold text-red-800 border border-red-200">
            <span>{error}</span>
            <button
              type="button"
              onClick={() => setError(null)}
              className="text-red-600 hover:text-red-900"
            >
              &times;
            </button>
          </div>
        )}
      </div>

      <div className="flex-1 p-6 space-y-6">
        {/* Filters */}
        <div className="flex flex-col gap-3 rounded-xl border border-border bg-white p-4 shadow-2xs md:flex-row md:items-center md:justify-between">
          <div className="flex flex-1 flex-wrap items-center gap-3">
            <div className="w-64">
              <label className="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">
                Kategori Log
              </label>
              <select
                value={logName}
                onChange={(e) => {
                  setLogName(e.target.value);
                  setPage(1);
                }}
                className="w-full h-9 rounded-lg border border-border bg-surface px-3 text-xs focus:border-primary focus:outline-hidden"
              >
                <option value="">Semua Kategori ({isOwner ? "Semua" : "Operasional"})</option>
                {LOG_CATEGORIES.map((c) => (
                  <option key={c.value} value={c.value}>
                    {c.label}
                  </option>
                ))}
              </select>
            </div>

            <div className="flex-1 max-w-sm">
              <label className="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">
                Cari Deskripsi Peristiwa
              </label>
              <Input
                type="search"
                placeholder="Cari deskripsi (contoh: sale, checkout, refund)..."
                value={eventQuery}
                onChange={(e) => {
                  setEventQuery(e.target.value);
                  setPage(1);
                }}
              />
            </div>
          </div>

          <div className="text-xs text-muted-foreground">
            Total {totalItems} aktivitas terekam
          </div>
        </div>

        {/* Audit Log Table */}
        <div className="rounded-xl border border-border bg-surface shadow-2xs overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                <tr>
                  <th className="px-4 py-3">Waktu (WITA)</th>
                  <th className="px-4 py-3">Kategori</th>
                  <th className="px-4 py-3">Pelaku (User)</th>
                  <th className="px-4 py-3">Deskripsi Peristiwa</th>
                  <th className="px-4 py-3">Alamat IP</th>
                  <th className="px-4 py-3 text-center">Rincian</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {loading ? (
                  <tr>
                    <td
                      colSpan={6}
                      className="px-4 py-8 text-center text-muted-foreground"
                    >
                      Memuat data audit log...
                    </td>
                  </tr>
                ) : items.length === 0 ? (
                  <tr>
                    <td
                      colSpan={6}
                      className="px-4 py-8 text-center text-muted-foreground"
                    >
                      Tidak ada jejak audit log yang sesuai dengan filter.
                    </td>
                  </tr>
                ) : (
                  items.map((e) => (
                    <tr key={e.id} className="hover:bg-slate-50/80 transition-colors">
                      <td className="px-4 py-3 whitespace-nowrap text-xs text-muted-foreground font-mono">
                        {formatDateTime(e.created_at)}
                      </td>
                      <td className="px-4 py-3">
                        <span className="inline-flex items-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700 border border-slate-200">
                          {e.log_name}
                        </span>
                      </td>
                      <td className="px-4 py-3 text-xs font-semibold text-foreground">
                        {e.causer_name || "Sistem"}
                      </td>
                      <td className="px-4 py-3 text-xs text-foreground font-medium max-w-md truncate">
                        {e.description}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                        {e.ip_address || "-"}
                      </td>
                      <td className="px-4 py-3 text-center">
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => setDetail(e)}
                        >
                          Lihat Detail
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {lastPage > 1 && (
            <div className="flex items-center justify-between border-t border-border px-6 py-3 text-xs text-muted-foreground">
              <span>
                Halaman {page} dari {lastPage} ({totalItems} aktivitas)
              </span>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page <= 1 || loading}
                  onClick={() => load(page - 1, logName, eventQuery)}
                >
                  Sebelumnya
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={page >= lastPage || loading}
                  onClick={() => load(page + 1, logName, eventQuery)}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </div>
      </div>

      {/* MODAL: DETAIL AUDIT EVENT */}
      <Modal
        open={detail !== null}
        title={detail ? `Audit Event #${detail.id}` : "Detail Audit Event"}
        onClose={() => setDetail(null)}
      >
        {detail && (
          <div className="space-y-4 max-h-[75vh] overflow-y-auto pr-1 text-xs">
            <div className="grid grid-cols-2 gap-3 rounded-lg border border-border bg-slate-50 p-3">
              <div>
                <span className="text-muted-foreground block">Waktu Kejadian:</span>
                <span className="font-semibold text-foreground">
                  {formatDateTime(detail.created_at)}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Kategori Log:</span>
                <span className="font-mono font-bold text-foreground">
                  {detail.log_name}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Pelaku (Causer):</span>
                <span className="font-semibold text-foreground">
                  {detail.causer_name || "Sistem Otomatis"}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block">Alamat IP:</span>
                <span className="font-mono text-foreground">
                  {detail.ip_address || "Internal/Console"}
                </span>
              </div>
            </div>

            <div>
              <span className="text-muted-foreground block font-semibold mb-1">
                Deskripsi Lengkap Peristiwa:
              </span>
              <div className="rounded-lg border border-border bg-white p-3 font-medium text-foreground">
                {detail.description}
              </div>
            </div>

            <div>
              <div className="flex items-center justify-between mb-1">
                <span className="text-muted-foreground font-semibold">
                  Payload &amp; Properties (JSON Snapshot):
                </span>
              </div>
              <pre className="max-h-64 overflow-auto rounded-lg border border-border bg-slate-900 p-3 text-[11px] font-mono text-emerald-400">
                {jsonPreview(detail.properties)}
              </pre>
            </div>

            <div className="flex justify-end pt-2 border-t border-border">
              <Button type="button" variant="outline" onClick={() => setDetail(null)}>
                Tutup
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
