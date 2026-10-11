"use client";

import { useCallback, useEffect, useState } from "react";
import { shiftApi, terminalApi } from "@/lib/api/shift";
import type { ShiftCloseResponse, ShiftCurrentResponse, Terminal } from "@/lib/types";
import { useAuth } from "@/lib/auth-context";
import { ApiError } from "@/lib/api";
import { formatRupiah, formatDate } from "@/lib/format";
import { Button } from "@/components/ui/button";
import { Input, Textarea } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

export default function ShiftPage() {
  const { user, refresh } = useAuth();
  const [current, setCurrent] = useState<ShiftCurrentResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [closeOpen, setCloseOpen] = useState(false);
  const [actualCash, setActualCash] = useState("");
  const [explanation, setExplanation] = useState("");
  const [closeError, setCloseError] = useState<string | null>(null);
  const [closing, setClosing] = useState(false);
  const [zReport, setZReport] = useState<ShiftCloseResponse | null>(null);

  const loadCurrent = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setCurrent(await shiftApi.current());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memuat data shift.");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadCurrent();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadCurrent]);

  const onClose = async () => {
    setClosing(true);
    setCloseError(null);
    try {
      const result = await shiftApi.close({
        actual_cash: Math.max(0, parseInt(actualCash, 10) || 0),
        explanation: explanation.trim() || null,
      });
      setZReport(result);
      setCloseOpen(false);
      await Promise.all([loadCurrent(), refresh()]);
    } catch (err) {
      setCloseError(err instanceof ApiError ? err.message : "Gagal menutup shift.");
    } finally {
      setClosing(false);
    }
  };

  if (loading) {
    return <div className="p-6 text-sm text-muted-foreground">Memuat data shift…</div>;
  }

  if (error) {
    return <div className="p-6 text-sm text-danger">{error}</div>;
  }

  if (!current) {
    return <OpenShiftForm onOpened={() => Promise.all([loadCurrent(), refresh()])} />;
  }

  const { shift, x_report: x } = current;
  const expected = x.expected_cash;
  const actualParsed = Math.max(0, parseInt(actualCash, 10) || 0);
  const variance = actualCash === "" ? null : actualParsed - expected;

  return (
    <div className="h-full overflow-y-auto scroll-thin p-4">
      <div className="mx-auto max-w-4xl">
        <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h1 className="text-xl font-bold">Shift #{shift.shift_number}</h1>
            <p className="text-sm text-muted-foreground">
              Terminal {shift.terminal_code} · Dibuka {formatDate(shift.opened_at)}
            </p>
          </div>
          <Badge variant="success">TERBUKA</Badge>
        </header>

        {/* Laporan X */}
        <section className="rounded-xl border border-border bg-surface p-4">
          <h2 className="mb-3 text-base font-semibold">
            Laporan X <span className="text-xs font-normal text-muted-foreground">(real-time)</span>
          </h2>
          <dl className="grid grid-cols-2 gap-x-6 gap-y-3 md:grid-cols-4">
            <Metric label="Kas Awal" value={formatRupiah(x.opening_cash)} />
            <Metric label="Penjualan Tunai" value={formatRupiah(x.cash_sales)} />
            <Metric label="Penjualan Non-Tunai" value={formatRupiah(x.non_cash_sales)} />
            <Metric label="Jumlah Transaksi" value={String(x.transaction_count)} />
            <Metric label="Kas Masuk" value={formatRupiah(x.cash_in)} />
            <Metric label="Kas Keluar" value={formatRupiah(x.cash_out)} />
            <Metric label="Total Diskon" value={formatRupiah(x.total_discount)} />
            <Metric label="Total Pajak" value={formatRupiah(x.total_tax)} />
            <Metric
              label="Kas Seharusnya"
              value={formatRupiah(x.expected_cash)}
              emphasis="primary"
            />
            <Metric label="Penjualan Bruto" value={formatRupiah(x.gross_sales)} />
            <Metric label="Penjualan Neto" value={formatRupiah(x.net_sales)} />
            <Metric label="Total Refund" value={formatRupiah(x.refund_total)} />
          </dl>

          {x.by_payment_method.length > 0 && (
            <div className="mt-4">
              <h3 className="mb-2 text-sm font-medium text-muted-foreground">
                Rincian per Metode Pembayaran
              </h3>
              <ul className="flex flex-wrap gap-2">
                {x.by_payment_method.map((m) => (
                  <li
                    key={m.method}
                    className="rounded-lg border border-border px-3 py-1.5 text-sm"
                  >
                    <span className="font-medium uppercase">{m.method}</span>
                    <span className="ml-2 text-muted-foreground">
                      {m.count}× · {formatRupiah(m.total)}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </section>

        <div className="mt-4 flex flex-wrap justify-end gap-3">
          <Button variant="secondary" onClick={loadCurrent}>
            Segarkan
          </Button>
          <Button
            variant="danger"
            size="lg"
            onClick={() => {
              setActualCash("");
              setExplanation("");
              setCloseError(null);
              setCloseOpen(true);
            }}
          >
            Tutup Shift
          </Button>
        </div>
      </div>

      {/* Dialog Tutup Shift */}
      <Modal
        open={closeOpen}
        title="Tutup Shift"
        onClose={() => setCloseOpen(false)}
        dismissible={!closing}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCloseOpen(false)} disabled={closing}>
              Batal
            </Button>
            <Button variant="danger" onClick={onClose} loading={closing}>
              Tutup Shift
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-4">
          <p className="text-sm text-muted-foreground">
            Hitung uang fisik di laci, lalu masukkan jumlah sebenarnya.
          </p>
          <div className="flex items-center justify-between rounded-lg bg-surface-muted px-4 py-3">
            <span className="text-sm text-muted-foreground">Kas Seharusnya</span>
            <span className="text-lg font-bold">{formatRupiah(expected)}</span>
          </div>
          <Input
            label="Kas Aktual (Rp)"
            name="actual_cash"
            inputMode="numeric"
            placeholder="0"
            value={actualCash}
            onChange={(e) => setActualCash(e.target.value.replace(/\D/g, ""))}
            autoFocus
          />
          {variance !== null && (
            <div
              className={`flex items-center justify-between rounded-lg px-4 py-2 text-sm font-semibold ${
                variance === 0
                  ? "bg-green-50 text-green-800"
                  : variance > 0
                    ? "bg-blue-50 text-blue-800"
                    : "bg-red-50 text-red-800"
              }`}
            >
              <span>Selisih</span>
              <span>{formatRupiah(variance)}</span>
            </div>
          )}
          {variance !== null && variance !== 0 && (
            <Textarea
              label="Penjelasan Selisih (wajib)"
              name="explanation"
              placeholder="Jelaskan penyebab selisih kas…"
              value={explanation}
              onChange={(e) => setExplanation(e.target.value)}
            />
          )}
          {closeError && (
            <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
              {closeError}
            </p>
          )}
        </div>
      </Modal>

      {/* Z Report pasca penutupan */}
      <Modal
        open={zReport !== null}
        title="Laporan Z — Shift Ditutup"
        onClose={() => setZReport(null)}
        footer={
          <Button onClick={() => window.print()}>Cetak Laporan Z</Button>
        }
      >
        {zReport && (
          <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <Metric label="Kas Awal" value={formatRupiah(zReport.shift.starting_cash)} />
            <Metric label="Kas Seharusnya" value={formatRupiah(zReport.shift.expected_cash)} />
            <Metric label="Kas Aktual" value={formatRupiah(zReport.shift.actual_cash)} />
            <Metric
              label="Selisih"
              value={formatRupiah(zReport.shift.difference)}
              emphasis={zReport.shift.difference === 0 ? "success" : "danger"}
            />
            <Metric label="Tutup Pada" value={formatDate(zReport.shift.closed_at)} />
          </dl>
        )}
      </Modal>

      {/* Referensi agar tidak ada variable yang tidak terpakai di beberapa build target */}
      <span className="hidden">{user?.id}</span>
    </div>
  );
}

function Metric({
  label,
  value,
  emphasis,
}: {
  label: string;
  value: string;
  emphasis?: "primary" | "success" | "danger";
}) {
  const color =
    emphasis === "primary"
      ? "text-primary"
      : emphasis === "success"
        ? "text-success"
        : emphasis === "danger"
          ? "text-danger"
          : "text-foreground";
  return (
    <div>
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className={`text-base font-semibold ${color}`}>{value}</dd>
    </div>
  );
}

function OpenShiftForm({ onOpened }: { onOpened: () => Promise<unknown> }) {
  const [terminals, setTerminals] = useState<Terminal[]>([]);
  const [terminalId, setTerminalId] = useState<number>(0);
  const [startingCash, setStartingCash] = useState("");
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    terminalApi.list().then((t) => {
      setTerminals(t);
      if (t.length > 0) setTerminalId(t[0].id);
    });
  }, []);

  const submit = async () => {
    setSubmitting(true);
    setError(null);
    try {
      await shiftApi.open({
        terminal_id: terminalId,
        starting_cash: Math.max(0, parseInt(startingCash, 10) || 0),
        notes: notes.trim() || null,
      });
      await onOpened();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal membuka shift.");
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex h-full items-center justify-center p-4">
      <div className="w-full max-w-md rounded-2xl border border-border bg-surface p-6 shadow-sm">
        <h1 className="mb-1 text-xl font-bold">Buka Shift</h1>
        <p className="mb-5 text-sm text-muted-foreground">
          Pilih terminal dan masukkan kas awal di laci untuk memulai.
        </p>

        <div className="flex flex-col gap-4">
          <label className="flex flex-col gap-1">
            <span className="text-sm font-medium">Terminal</span>
            <select
              value={terminalId}
              onChange={(e) => setTerminalId(parseInt(e.target.value, 10))}
              className="h-11 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-primary"
            >
              {terminals.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.code} — {t.name}
                </option>
              ))}
            </select>
          </label>

          <Input
            label="Kas Awal (Rp)"
            name="starting_cash"
            inputMode="numeric"
            placeholder="0"
            value={startingCash}
            onChange={(e) => setStartingCash(e.target.value.replace(/\D/g, ""))}
          />

          <Textarea
            label="Catatan (opsional)"
            name="notes"
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="Catatan serah terima kas…"
          />

          {error && (
            <p role="alert" className="rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
              {error}
            </p>
          )}

          <Button size="xl" onClick={submit} loading={submitting}>
            {submitting ? "Membuka…" : "Buka Shift"}
          </Button>
        </div>
      </div>
    </div>
  );
}
