"use client";

import { useCallback, useEffect, useState } from "react";
import { exportApi, reportApi } from "@/lib/api/reports";
import type {
  ExportFormat,
  ExportJob,
  ExportType,
  ReceivingReportRow,
  ReturnsReportRow,
  SalesReport,
  ShiftDifferenceRow,
  StockReportRow,
  TopProductRow,
} from "@/lib/types";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";
import { formatDate, formatRupiah } from "@/lib/format";

type TabKey = "sales" | "top-products" | "stock" | "shift-differences" | "receiving" | "returns";

const TABS: Array<{ key: TabKey; label: string }> = [
  { key: "sales", label: "Penjualan" },
  { key: "top-products", label: "Produk Terlaris" },
  { key: "stock", label: "Stok" },
  { key: "shift-differences", label: "Selisih Shift" },
  { key: "receiving", label: "Penerimaan" },
  { key: "returns", label: "Retur" },
];

function todayIso(offsetDays = 0): string {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}

export default function ReportsPage() {
  const { user } = useAuth();
  const isSupervisor = user !== null && (user.roles.includes("owner") || user.roles.includes("manager"));

  if (!isSupervisor) {
    return (
      <div className="flex h-full items-center justify-center p-4 text-sm text-muted-foreground">
        Laporan hanya dapat diakses oleh owner atau manager.
      </div>
    );
  }

  return <ReportsContent />;
}

function ReportsContent() {
  const [tab, setTab] = useState<TabKey>("sales");
  const [from, setFrom] = useState(todayIso(-7));
  const [to, setTo] = useState(todayIso());
  const [error, setError] = useState<string | null>(null);

  // Data
  const [sales, setSales] = useState<SalesReport | null>(null);
  const [topProducts, setTopProducts] = useState<TopProductRow[]>([]);
  const [stock, setStock] = useState<StockReportRow[]>([]);
  const [shiftDiffs, setShiftDiffs] = useState<ShiftDifferenceRow[]>([]);
  const [receivings, setReceivings] = useState<ReceivingReportRow[]>([]);
  const [returns, setReturns] = useState<ReturnsReportRow[]>([]);
  const [loading, setLoading] = useState(false);

  // Ekspor
  const [exportOpen, setExportOpen] = useState(false);
  const [exportFormat, setExportFormat] = useState<ExportFormat>("xlsx");
  const [exporting, setExporting] = useState(false);
  const [jobs, setJobs] = useState<ExportJob[]>([]);

  const loadJobs = useCallback(async () => {
    try {
      const res = await exportApi.list({ per_page: 10 });
      setJobs(res.data);
    } catch {
      // abaikan kegagalan refresh jobs
    }
  }, []);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = { from, to };
      switch (tab) {
        case "sales":
          setSales(await reportApi.sales(params));
          break;
        case "top-products":
          setTopProducts(await reportApi.topProducts(params));
          break;
        case "stock":
          setStock(await reportApi.stock({}));
          break;
        case "shift-differences":
          setShiftDiffs(await reportApi.shiftDifferences(params));
          break;
        case "receiving":
          setReceivings(await reportApi.receiving(params));
          break;
        case "returns":
          setReturns(await reportApi.returns(params));
          break;
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal memuat laporan.");
    } finally {
      setLoading(false);
    }
  }, [tab, from, to]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadData();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadData]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadJobs();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadJobs]);

  const onExport = async () => {
    setExporting(true);
    try {
      await exportApi.create({
        type: tab as ExportType,
        format: exportFormat,
        filters: { from, to },
      });
      setExportOpen(false);
      await loadJobs();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Gagal membuat ekspor.");
    } finally {
      setExporting(false);
    }
  };

  return (
    <div className="h-full overflow-y-auto scroll-thin p-4">
      <header className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold">Laporan & Ekspor</h1>
          <p className="text-sm text-muted-foreground">
            Data dihitung ulang dari server — jumlah akan konsisten dengan POS.
          </p>
        </div>
        <Button onClick={() => setExportOpen(true)}>Ekspor Laporan</Button>
      </header>

      {/* Filter tanggal */}
      <div className="mb-4 flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-3">
        <Input
          label="Dari Tanggal"
          type="date"
          name="from"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
        />
        <Input
          label="Sampai Tanggal"
          type="date"
          name="to"
          value={to}
          onChange={(e) => setTo(e.target.value)}
        />
        <Button variant="secondary" onClick={loadData} loading={loading}>
          Muat Ulang
        </Button>
      </div>

      {error && (
        <p role="alert" className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-danger">
          {error}
        </p>
      )}

      {/* Tab */}
      <div className="mb-4 flex gap-1 border-b border-border">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`touch-target h-11 rounded-t-lg px-4 text-sm font-medium ${
              tab === t.key
                ? "border-b-2 border-primary text-primary"
                : "text-muted-foreground hover:text-foreground"
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === "sales" && sales && (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          <ReportCard title="Penjualan Bruto" value={formatRupiah(sales.gross_sales)} />
          <ReportCard title="Penjualan Neto" value={formatRupiah(sales.net_sales)} />
          <ReportCard title="Total Diskon" value={formatRupiah(sales.total_discount)} />
          <ReportCard title="Total Pajak" value={formatRupiah(sales.total_tax)} />
          <ReportCard title="COGS" value={formatRupiah(sales.cogs)} />
          <ReportCard title="Laba Kotor" value={formatRupiah(sales.gross_profit)} />
          <ReportCard title="Jumlah Transaksi" value={String(sales.transaction_count)} />
          <ReportCard title="Rata-rata per Transaksi" value={formatRupiah(sales.average_transaction)} />
          <ReportCard title="Rentang" value={`${sales.from} — ${sales.to}`} />
        </section>
      )}

      {tab === "top-products" && (
        <ReportTable
          columns={["SKU", "Nama", "Qty Terjual", "Pendapatan", "Laba Kotor"]}
          rows={topProducts.map((p) => [
            p.sku,
            p.name,
            String(p.quantity_sold),
            formatRupiah(p.revenue),
            p.gross_profit !== undefined ? formatRupiah(p.gross_profit) : "—",
          ])}
        />
      )}

      {tab === "stock" && (
        <ReportTable
          columns={["SKU", "Nama", "Satuan", "Stok", "Min", "Nilai Stok"]}
          rows={stock.map((s) => [
            s.sku,
            s.name,
            s.unit,
            String(s.stock),
            String(s.min_stock),
            s.stock_value !== undefined ? formatRupiah(s.stock_value) : "—",
          ])}
        />
      )}

      {tab === "shift-differences" && (
        <ReportTable
          columns={["Shift", "Kasir", "Terminal", "Kas Seharusnya", "Kas Aktual", "Selisih", "Ditutup"]}
          rows={shiftDiffs.map((d) => [
            d.shift_number,
            d.cashier_name,
            d.terminal_code,
            formatRupiah(d.expected_cash),
            d.actual_cash !== null ? formatRupiah(d.actual_cash) : "—",
            d.difference !== null ? formatRupiah(d.difference) : "—",
            d.closed_at ? formatDate(d.closed_at) : "—",
          ])}
        />
      )}

      {tab === "receiving" && (
        <ReportTable
          columns={["No. Penerimaan", "Supplier", "Tanggal", "Item", "Qty", "Total Biaya"]}
          rows={receivings.map((r) => [
            r.receiving_number,
            r.supplier_name,
            r.posted_at ? formatDate(r.posted_at) : "—",
            String(r.line_count),
            String(r.total_quantity),
            r.total_cost !== undefined ? formatRupiah(r.total_cost) : "—",
          ])}
        />
      )}

      {tab === "returns" && (
        <ReportTable
          columns={["No. Retur", "Struk", "Status", "Refund", "Layak Jual", "Rusak", "Selesai"]}
          rows={returns.map((r) => [
            r.return_number,
            r.receipt_number,
            r.status,
            formatRupiah(r.total_refund),
            formatRupiah(r.restockable_value),
            formatRupiah(r.damaged_value),
            r.completed_at ? formatDate(r.completed_at) : "—",
          ])}
        />
      )}

      {/* Modal Ekspor */}
      <Modal
        open={exportOpen}
        title="Ekspor Laporan"
        onClose={() => setExportOpen(false)}
        footer={
          <>
            <Button variant="secondary" onClick={() => setExportOpen(false)}>
              Batal
            </Button>
            <Button onClick={onExport} loading={exporting}>
              Buat Ekspor
            </Button>
          </>
        }
      >
        <div className="flex flex-col gap-3">
          <p className="text-sm text-muted-foreground">
            Laporan <span className="font-semibold">{TABS.find((t) => t.key === tab)?.label}</span>{" "}
            periode {from} — {to}
          </p>
          <div className="flex gap-2">
            {(["xlsx", "pdf"] as ExportFormat[]).map((f) => (
              <button
                key={f}
                type="button"
                onClick={() => setExportFormat(f)}
                className={`touch-target flex-1 rounded-lg border-2 py-2 text-sm font-medium uppercase ${
                  exportFormat === f
                    ? "border-primary bg-blue-50 text-primary"
                    : "border-border hover:bg-slate-50"
                }`}
              >
                {f}
              </button>
            ))}
          </div>
          <p className="text-xs text-muted-foreground">
            File disiapkan di latar belakang. Unduh dari daftar di bawah begitu selesai.
          </p>
        </div>
      </Modal>

      {/* Daftar Ekspor */}
      <section className="mt-6">
        <h2 className="mb-2 text-base font-semibold">File Ekspor Terakhir</h2>
        <div className="overflow-hidden rounded-xl border border-border bg-surface">
          <table className="w-full text-sm">
            <thead className="bg-surface-muted text-left text-xs uppercase text-muted-foreground">
              <tr>
                <th className="px-3 py-2">Jenis</th>
                <th className="px-3 py-2">Format</th>
                <th className="px-3 py-2">Status</th>
                <th className="px-3 py-2">Dibuat</th>
                <th className="px-3 py-2 text-right">Aksi</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {jobs.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-3 py-4 text-center text-sm text-muted-foreground">
                    Belum ada file ekspor.
                  </td>
                </tr>
              )}
              {jobs.map((j) => (
                <tr key={j.id} className="hover:bg-slate-50">
                  <td className="px-3 py-2 capitalize">{j.type.replace("-", " ")}</td>
                  <td className="px-3 py-2 uppercase">{j.format}</td>
                  <td className="px-3 py-2">
                    <Badge
                      variant={
                        j.status === "completed"
                          ? "success"
                          : j.status === "failed"
                            ? "danger"
                            : "warning"
                      }
                    >
                      {j.status}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-xs">{formatDate(j.created_at)}</td>
                  <td className="px-3 py-2 text-right">
                    {j.status === "completed" && (
                      <a
                        href={exportApi.downloadUrl(j.id)}
                        className="text-sm font-medium text-primary hover:underline"
                        download
                      >
                        Unduh
                      </a>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function ReportCard({ title, value }: { title: string; value: string }) {
  return (
    <div className="rounded-xl border border-border bg-surface p-4">
      <p className="text-xs text-muted-foreground">{title}</p>
      <p className="mt-1 text-lg font-semibold">{value}</p>
    </div>
  );
}

function ReportTable({ columns, rows }: { columns: string[]; rows: string[][] }) {
  if (rows.length === 0) {
    return (
      <div className="rounded-xl border border-border bg-surface p-6 text-center text-sm text-muted-foreground">
        Tidak ada data untuk ditampilkan.
      </div>
    );
  }
  return (
    <div className="overflow-x-auto rounded-xl border border-border bg-surface scroll-thin">
      <table className="w-full text-sm">
        <thead className="bg-surface-muted text-left text-xs uppercase text-muted-foreground">
          <tr>
            {columns.map((c) => (
              <th key={c} className="px-3 py-3 whitespace-nowrap">
                {c}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-border">
          {rows.map((r, i) => (
            <tr key={i} className="hover:bg-slate-50">
              {r.map((cell, j) => (
                <td key={j} className="px-3 py-3 whitespace-nowrap">
                  {cell}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}