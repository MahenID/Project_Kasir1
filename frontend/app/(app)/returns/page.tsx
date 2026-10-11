"use client";

import { useCallback, useEffect, useState } from "react";
import { returnApi, type CreateReturnInput } from "@/lib/api/returns";
import { shiftApi } from "@/lib/api/shift";
import type {
  ReturnDisposition,
  ReturnLookupResult,
  ReturnRequestPayload,
  ReturnRequestStatus,
  ShiftCurrentResponse,
} from "@/lib/types";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Input, Textarea } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";
import { formatDate, formatRupiah } from "@/lib/format";

type Tab = "create" | "list";

interface DraftLine {
  sale_item_id: number;
  product_name: string;
  unit_price: number;
  quantity_returnable: number;
  quantity: number;
  disposition: ReturnDisposition;
}

const STATUS_VARIANT: Record<ReturnRequestStatus, "default" | "success" | "warning" | "danger" | "info"> = {
  requested: "warning",
  approved: "info",
  rejected: "danger",
  cancelled: "default",
  completed: "success",
};

export default function ReturnsPage() {
  const { user, refresh } = useAuth();
  const [tab, setTab] = useState<Tab>("create");

  const isSupervisor =
    user !== null && (user.roles.includes("owner") || user.roles.includes("manager"));

  return (
    <div className="h-full overflow-y-auto scroll-thin p-4">
      <header className="mb-4">
        <h1 className="text-xl font-bold">Retur & Refund</h1>
        <p className="text-sm text-muted-foreground">
          Ajukan pengembalian barang dan proses refund dengan persetujuan supervisor.
        </p>
      </header>

      <div className="mb-4 flex gap-1 border-b border-border">
        {(
          [
            ["create", "Ajukan Retur"],
            ["list", "Daftar Permintaan"],
          ] as Array<[Tab, string]>
        ).map(([value, label]) => (
          <button
            key={value}
            type="button"
            onClick={() => setTab(value)}
            className={`touch-target h-11 rounded-t-lg px-4 text-sm font-medium ${
              tab === value
                ? "border-b-2 border-primary text-primary"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === "create" ? (
        <CreateReturnTab onCreated={() => setTab("list")} />
      ) : (
        <ReturnListTab
          isSupervisor={isSupervisor}
          onChanged={() => refresh()}
        />
      )}
    </div>
  );
}

function CreateReturnTab({ onCreated }: { onCreated: () => void }) {
  const [receipt, setReceipt] = useState("");
  const [lookup, setLookup] = useState<ReturnLookupResult | null>(null);
  const [drafts, setDrafts] = useState<DraftLine[]>([]);
  const [reason, setReason] = useState("");
  const [overrideReason, setOverrideReason] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState<ReturnRequestPayload | null>(null);

  const doLookup = async () => {
    const trimmed = receipt.trim();
    if (!trimmed) return;
    setLoading(true);
    setError(null);
    setLookup(null);
    setDrafts([]);
    try {
      const result = await returnApi.lookup(trimmed);
      setLookup(result);
      setDrafts(
        result.items
          .filter((i) => i.quantity_returnable > 0)
          .map((i) => ({
            sale_item_id: i.sale_item_id,
            product_name: i.product_name,
            unit_price: i.unit_price,
            quantity_returnable: i.quantity_returnable,
            quantity: 0,
            disposition: "restockable",
          }))
      );
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal mencari struk.");
    } finally {
      setLoading(false);
    }
  };

  const setDraftQty = (saleItemId: number, qty: number) => {
    setDrafts((prev) =>
      prev.map((d) =>
        d.sale_item_id === saleItemId
          ? { ...d, quantity: Math.min(Math.max(0, qty), d.quantity_returnable) }
          : d
      )
    );
  };

  const setDraftDisposition = (saleItemId: number, disposition: ReturnDisposition) => {
    setDrafts((prev) =>
      prev.map((d) => (d.sale_item_id === saleItemId ? { ...d, disposition } : d))
    );
  };

  const selected = drafts.filter((d) => d.quantity > 0);
  const totalRefund = selected.reduce((sum, d) => sum + d.quantity * d.unit_price, 0);

  const submit = async () => {
    if (!lookup || selected.length === 0 || !reason.trim()) return;
    setSubmitting(true);
    setError(null);
    const payload: CreateReturnInput = {
      sale_id: lookup.sale.id,
      reason: reason.trim(),
      lines: selected.map((d) => ({
        sale_item_id: d.sale_item_id,
        quantity: d.quantity,
        disposition: d.disposition,
      })),
      override_reason: overrideReason.trim() || null,
    };
    try {
      const created = await returnApi.create(payload);
      setSuccess(created);
      setLookup(null);
      setDrafts([]);
      setReceipt("");
      setReason("");
      setOverrideReason("");
      onCreated();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal mengajukan retur.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <section className="mx-auto max-w-3xl">
      <div className="flex items-end gap-2">
        <div className="flex-1">
          <Input
            label="Nomor Struk"
            name="receipt_number"
            placeholder="cth: INV-20261011-0001"
            value={receipt}
            onChange={(e) => setReceipt(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") doLookup();
            }}
          />
        </div>
        <Button onClick={doLookup} loading={loading}>
          Cari
        </Button>
      </div>

      {error && (
        <p role="alert" className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
          {error}
        </p>
      )}

      {lookup && (
        <div className="mt-4 rounded-xl border border-border bg-surface p-4">
          <div className="mb-3 flex items-center justify-between">
            <div>
              <p className="font-mono text-sm font-semibold">{lookup.sale.receipt_number}</p>
              <p className="text-xs text-muted-foreground">
                {lookup.sale.completed_at ? formatDate(lookup.sale.completed_at) : "—"}
              </p>
            </div>
            <Badge variant={lookup.items.some((i) => i.quantity_returnable > 0) ? "success" : "default"}>
              {lookup.items.some((i) => i.quantity_returnable > 0) ? "Dapat diretur" : "Tidak ada item diretur"}
            </Badge>
          </div>

          <div className="overflow-hidden rounded-lg border border-border">
            <table className="w-full text-sm">
              <thead className="bg-surface-muted text-left text-xs uppercase text-muted-foreground">
                <tr>
                  <th className="px-3 py-2">Produk</th>
                  <th className="px-3 py-2 text-right">Terjual</th>
                  <th className="px-3 py-2 text-right">Sudah Retur</th>
                  <th className="px-3 py-2 text-right">Dapat Retur</th>
                  <th className="px-3 py-2 text-center">Qty Retur</th>
                  <th className="px-3 py-2">Kondisi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {drafts.map((d) => (
                  <tr key={d.sale_item_id}>
                    <td className="px-3 py-2">
                      {d.product_name}
                      <div className="text-xs text-muted-foreground">{formatRupiah(d.unit_price)}</div>
                    </td>
                    <td className="px-3 py-2 text-right">—</td>
                    <td className="px-3 py-2 text-right">—</td>
                    <td className="px-3 py-2 text-right">{d.quantity_returnable}</td>
                    <td className="px-3 py-2">
                      <div className="flex items-center justify-center gap-1">
                        <button
                          type="button"
                          aria-label="Kurangi"
                          onClick={() => setDraftQty(d.sale_item_id, d.quantity - 1)}
                          className="touch-target flex h-9 w-9 items-center justify-center rounded border border-border font-bold hover:bg-slate-100"
                        >
                          −
                        </button>
                        <span className="w-8 text-center font-semibold">{d.quantity}</span>
                        <button
                          type="button"
                          aria-label="Tambah"
                          onClick={() => setDraftQty(d.sale_item_id, d.quantity + 1)}
                          className="touch-target flex h-9 w-9 items-center justify-center rounded border border-border font-bold hover:bg-slate-100"
                        >
                          +
                        </button>
                      </div>
                    </td>
                    <td className="px-3 py-2">
                      <select
                        value={d.disposition}
                        onChange={(e) => setDraftDisposition(d.sale_item_id, e.target.value as ReturnDisposition)}
                        className="h-10 rounded-lg border border-border bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
                      >
                        <option value="restockable">Layak Jual</option>
                        <option value="damaged">Rusak</option>
                      </select>
                    </td>
                  </tr>
                ))}
                {drafts.length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-3 py-6 text-center text-sm text-muted-foreground">
                      Tidak ada item yang dapat diretur dari struk ini.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div className="mt-4 flex flex-col gap-3">
            <Textarea
              label="Alasan Retur (wajib)"
              name="reason"
              value={reason}
              onChange={(e) => setReason(e.target.value)}
              placeholder="cth: barang tidak sesuai, rusak saat pengiriman…"
            />
            <Textarea
              label="Catatan Override (opsional)"
              name="override_reason"
              value={overrideReason}
              onChange={(e) => setOverrideReason(e.target.value)}
              placeholder="cth: persetujuan supervisor via telepon…"
            />
          </div>

          <div className="mt-4 flex items-center justify-between border-t border-border pt-4">
            <div>
              <p className="text-xs text-muted-foreground">Estimasi Refund</p>
              <p className="text-xl font-bold text-primary">{formatRupiah(totalRefund)}</p>
            </div>
            <Button
              size="lg"
              onClick={submit}
              loading={submitting}
              disabled={selected.length === 0 || !reason.trim()}
            >
              Ajukan Retur
            </Button>
          </div>
        </div>
      )}

      <Modal
        open={success !== null}
        title="Retur Diajukan"
        onClose={() => setSuccess(null)}
        footer={<Button onClick={() => setSuccess(null)}>Tutup</Button>}
      >
        {success && (
          <div className="flex flex-col gap-2 text-sm">
            <p>
              Nomor retur: <span className="font-mono font-semibold">{success.return_number}</span>
            </p>
            <p>Total refund: <span className="font-bold">{formatRupiah(success.total_refund)}</span></p>
            <p className="text-muted-foreground">
              Menunggu persetujuan supervisor sebelum refund dapat diproses.
            </p>
          </div>
        )}
      </Modal>
    </section>
  );
}

function ReturnListTab({
  isSupervisor,
  onChanged,
}: {
  isSupervisor: boolean;
  onChanged: () => void;
}) {
  const [items, setItems] = useState<ReturnRequestPayload[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const [rejectTarget, setRejectTarget] = useState<ReturnRequestPayload | null>(null);
  const [rejectReason, setRejectReason] = useState("");

  const [completeTarget, setCompleteTarget] = useState<ReturnRequestPayload | null>(null);
  const [completeShift, setCompleteShift] = useState<ShiftCurrentResponse | null>(null);
  const [confirmedCash, setConfirmedCash] = useState("");
  const [refundRef, setRefundRef] = useState("");
  const [completing, setCompleting] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await returnApi.list({ per_page: 25 });
      setItems(res.data);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memuat daftar retur.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      load();
    }, 0);
    return () => clearTimeout(timer);
  }, [load]);

  const onApprove = async (r: ReturnRequestPayload) => {
    setBusyId(r.id);
    setActionError(null);
    try {
      await returnApi.approve(r.id);
      await load();
      onChanged();
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : "Gagal menyetujui retur.");
    } finally {
      setBusyId(null);
    }
  };

  const onReject = async () => {
    if (!rejectTarget) return;
    setBusyId(rejectTarget.id);
    setActionError(null);
    try {
      await returnApi.reject(rejectTarget.id, rejectReason.trim());
      setRejectTarget(null);
      setRejectReason("");
      await load();
      onChanged();
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : "Gagal menolak retur.");
    } finally {
      setBusyId(null);
    }
  };

  const openComplete = async (r: ReturnRequestPayload) => {
    setCompleteTarget(r);
    setConfirmedCash(String(r.total_refund));
    setRefundRef("");
    setActionError(null);
    try {
      setCompleteShift(await shiftApi.current());
    } catch {
      setCompleteShift(null);
    }
  };

  const onComplete = async () => {
    if (!completeTarget || !completeShift) return;
    setCompleting(true);
    setActionError(null);
    try {
      await returnApi.complete({
        returnId: completeTarget.id,
        shift_id: completeShift.shift.id,
        confirmed_cash_amount: Math.max(0, parseInt(confirmedCash, 10) || 0),
        refund_reference: refundRef.trim() || null,
      });
      setCompleteTarget(null);
      await load();
      onChanged();
    } catch (err) {
      setActionError(err instanceof ApiError ? err.message : "Gagal memproses refund.");
    } finally {
      setCompleting(false);
    }
  };

  const canComplete = completeShift !== null && completeShift.shift.status === "open";

  return (
    <section>
      <div className="mb-3 flex justify-end">
        <Button variant="secondary" onClick={load} loading={loading}>
          Segarkan
        </Button>
      </div>

      {(error || actionError) && (
        <p role="alert" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
          {error ?? actionError}
        </p>
      )}

      <div className="overflow-hidden rounded-xl border border-border bg-surface">
        <table className="w-full text-sm">
          <thead className="bg-surface-muted text-left text-xs uppercase text-muted-foreground">
            <tr>
              <th className="px-3 py-3">No. Retur</th>
              <th className="px-3 py-3">Struk</th>
              <th className="px-3 py-3">Pemohon</th>
              <th className="px-3 py-3 text-right">Refund</th>
              <th className="px-3 py-3">Status</th>
              <th className="px-3 py-3 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border">
            {!loading && items.length === 0 && (
              <tr>
                <td colSpan={6} className="px-3 py-6 text-center text-sm text-muted-foreground">
                  Belum ada permintaan retur.
                </td>
              </tr>
            )}
            {items.map((r) => (
              <tr key={r.id} className="hover:bg-slate-50">
                <td className="px-3 py-3 font-mono text-xs">{r.return_number}</td>
                <td className="px-3 py-3 font-mono text-xs">{r.receipt_number}</td>
                <td className="px-3 py-3">
                  {r.requested_by_name}
                  <div className="text-xs text-muted-foreground">{formatDate(r.requested_at)}</div>
                </td>
                <td className="px-3 py-3 text-right font-semibold">{formatRupiah(r.total_refund)}</td>
                <td className="px-3 py-3">
                  <Badge variant={STATUS_VARIANT[r.status]}>{r.status}</Badge>
                </td>
                <td className="px-3 py-3">
                  <div className="flex justify-end gap-2">
                    {isSupervisor && r.status === "requested" && (
                      <>
                        <Button
                          size="sm"
                          onClick={() => onApprove(r)}
                          loading={busyId === r.id}
                        >
                          Setujui
                        </Button>
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => setRejectTarget(r)}
                          disabled={busyId === r.id}
                        >
                          Tolak
                        </Button>
                      </>
                    )}
                    {isSupervisor && r.status === "approved" && (
                      <Button size="sm" variant="outline" onClick={() => openComplete(r)}>
                        Proses Refund
                      </Button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Tolak */}
      <Modal
        open={rejectTarget !== null}
        title="Tolak Permintaan Retur"
        onClose={() => setRejectTarget(null)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setRejectTarget(null)}>
              Batal
            </Button>
            <Button
              variant="danger"
              onClick={onReject}
              loading={busyId !== null && rejectTarget !== null && busyId === rejectTarget.id}
              disabled={!rejectReason.trim()}
            >
              Tolak
            </Button>
          </>
        }
      >
        <Textarea
          label="Alasan Penolakan (wajib)"
          name="reject_reason"
          value={rejectReason}
          onChange={(e) => setRejectReason(e.target.value)}
        />
      </Modal>

      {/* Proses Refund */}
      <Modal
        open={completeTarget !== null}
        title="Proses Refund"
        onClose={() => setCompleteTarget(null)}
        dismissible={!completing}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCompleteTarget(null)} disabled={completing}>
              Batal
            </Button>
            <Button onClick={onComplete} loading={completing} disabled={!canComplete}>
              Proses Refund
            </Button>
          </>
        }
      >
        {completeTarget && (
          <div className="flex flex-col gap-3">
            <p className="text-sm">
              Total refund:{" "}
              <span className="font-bold">{formatRupiah(completeTarget.total_refund)}</span>
            </p>
            {!canComplete && (
              <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-warning">
                Tidak ada shift aktif. Buka shift terlebih dahulu agar refund tunai dapat masuk ke laporan kas.
              </p>
            )}
            <Input
              label="Kas Tunai Dikonfirmasi (Rp)"
              name="confirmed_cash"
              inputMode="numeric"
              value={confirmedCash}
              onChange={(e) => setConfirmedCash(e.target.value.replace(/\D/g, ""))}
            />
            <Input
              label="Referensi Non-Tunai (opsional)"
              name="refund_reference"
              placeholder="No. transfer / QRIS"
              value={refundRef}
              onChange={(e) => setRefundRef(e.target.value)}
            />
          </div>
        )}
      </Modal>
    </section>
  );
}