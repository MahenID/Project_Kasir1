"use client";

import { useCallback, useEffect, useState } from "react";
import { auditApi } from "@/lib/api/reports";
import type { AuditEvent } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Modal } from "@/components/ui/modal";
import { formatDate } from "@/lib/format";

const PER_PAGE = 20;

const LOG_NAMES: string[] = [
  "auth",
  "sales",
  "returns",
  "shifts",
  "products",
  "exports",
];

export default function AuditPage() {
  const { user } = useAuth();
  const isOwner = user !== null && user.roles.includes("owner");
  const isManager = user !== null && user.roles.includes("manager");

  if (!isOwner && !isManager) {
    return (
      <div className="flex h-full items-center justify-center p-4 text-sm text-muted-foreground">
        Audit log hanya dapat diakses oleh owner atau manager.
      </div>
    );
  }

  return <AuditContent />;
}

function AuditContent() {
  const [items, setItems] = useState<AuditEvent[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [logName, setLogName] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [detail, setDetail] = useState<AuditEvent | null>(null);

  const load = useCallback(
    async (p: number, log?: string) => {
      setLoading(true);
      setError(null);
      try {
        const params: { per_page: number; page: number; log_name?: string } = {
          per_page: PER_PAGE,
          page: p,
        };
        if (log) params.log_name = log;
        const res = await auditApi.list(params);
        setItems(res.data);
        const pag = res.meta.pagination;
        setPage(pag?.current_page ?? 1);
        setLastPage(pag?.last_page ?? 1);
      } catch (err) {
        setError(err instanceof ApiError ? err.message : "Gagal memuat audit log.");
      } finally {
        setLoading(false);
      }
    },
    []
  );

  useEffect(() => {
    const timer = setTimeout(() => {
      load(1, logName || undefined);
    }, 0);
    return () => clearTimeout(timer);
  }, [load, logName]);

  const changeLog = (value: string) => {
    setLogName(value);
    setPage(1);
  };

  const jsonPreview = (obj: Record<string, unknown>): string =>
    JSON.stringify(obj, null, 2);

  return (
    <div className="h-full overflow-y-auto scroll-thin p-4">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold">Audit Log</h1>
          <p className="text-sm text-muted-foreground">
            Jejak aktivitas pengguna sistem.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={logName}
            onChange={(e) => changeLog(e.target.value)}
            className="h-11 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
          >
            <option value="">Semua Log</option>
            {LOG_NAMES.map((l) => (
              <option key={l} value={l}>
                {l}
              </option>
            ))}
          </select>
          <Button variant="secondary" onClick={() => load(page, logName || undefined)} loading={loading}>
            Segarkan
          </Button>
        </div>
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
              <th className="px-3 py-3">Waktu</th>
              <th className="px-3 py-3">Pengguna</th>
              <th className="px-3 py-3">Log</th>
              <th className="px-3 py-3">Deskripsi</th>
              <th className="px-3 py-3">IP</th>
              <th className="px-3 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {!loading && items.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-6 text-center text-sm text-muted-foreground">
                  Tidak ada audit log.
                </td>
              </tr>
            )}
            {items.map((e) => (
              <tr key={e.id} className="hover:bg-slate-50">
                <td className="px-3 py-3 whitespace-nowrap text-xs">
                  {formatDate(e.created_at)}
                </td>
                <td className="px-3 py-3">{e.causer_name ?? "sistem"}</td>
                <td className="px-3 py-3">
                  <span className="rounded bg-surface-muted px-2 py-1 text-xs font-medium">
                    {e.log_name}
                  </span>
                </td>
                <td className="px-3 py-3 max-w-xs truncate">{e.description}</td>
                <td className="px-3 py-3 font-mono text-xs">{e.ip_address ?? "—"}</td>
                <td className="px-3 py-3 text-right">
                  <Button size="sm" variant="outline" onClick={() => setDetail(e)}>
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
            onClick={() => load(page - 1, logName || undefined)}
          >
            Sebelumnya
          </Button>
          <Button
            variant="outline"
            size="sm"
            disabled={page >= lastPage || loading}
            onClick={() => load(page + 1, logName || undefined)}
          >
            Berikutnya
          </Button>
        </div>
      </nav>

      <Modal
        open={detail !== null}
        title="Detail Audit Event"
        onClose={() => setDetail(null)}
        footer={<Button onClick={() => setDetail(null)}>Tutup</Button>}
      >
        {detail && (
          <div className="flex flex-col gap-3 text-sm">
            <dl className="grid grid-cols-[120px_1fr] gap-x-3 gap-y-1">
              <dt className="text-muted-foreground">ID</dt>
              <dd className="font-mono">{detail.id}</dd>
              <dt className="text-muted-foreground">Waktu</dt>
              <dd>{formatDate(detail.created_at)}</dd>
              <dt className="text-muted-foreground">Log Name</dt>
              <dd>{detail.log_name}</dd>
              <dt className="text-muted-foreground">Pengguna</dt>
              <dd>{detail.causer_name ?? "sistem"}</dd>
              <dt className="text-muted-foreground">IP Address</dt>
              <dd className="font-mono">{detail.ip_address ?? "—"}</dd>
              <dt className="text-muted-foreground">Deskripsi</dt>
              <dd>{detail.description}</dd>
            </dl>

            <div>
              <p className="mb-1 text-xs font-medium uppercase text-muted-foreground">
                Properties
              </p>
              <pre className="max-h-72 overflow-auto rounded-lg border border-border bg-slate-50 p-3 text-xs font-mono scroll-thin">
                {jsonPreview(detail.properties)}
              </pre>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
