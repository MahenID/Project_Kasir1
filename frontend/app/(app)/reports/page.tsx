"use client";

import { useCallback, useEffect, useState } from "react";
import { exportApi, reportApi } from "@/lib/api/reports";
import type {
  ExportFormat,
  ExportJob,
  ExportReportType,
  ReceivingReport,
  ReturnsReport,
  SalesReport,
  ShiftDifferencesReport,
  StockReport,
  TopProductsReport,
} from "@/lib/types";
import { ApiError } from "@/lib/api";
import { useAuth } from "@/lib/auth-context";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";
import { formatRupiah } from "@/lib/format";

type TabKey =
  | "sales"
  | "top-products"
  | "stock"
  | "shift-differences"
  | "receiving"
  | "returns";

const TABS: Array<{ key: TabKey; label: string }> = [
  { key: "sales", label: "Penjualan" },
  { key: "top-products", label: "Produk Terlaris" },
  { key: "stock", label: "Stok & Valuasi" },
  { key: "shift-differences", label: "Selisih Shift" },
  { key: "receiving", label: "Penerimaan Barang" },
  { key: "returns", label: "Retur & Refund" },
];

function todayIso(offsetDays = 0): string {
  const d = new Date();
  d.setDate(d.getDate() + offsetDays);
  return d.toISOString().slice(0, 10);
}

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

export default function ReportsPage() {
  const { user } = useAuth();
  const isSupervisor =
    user !== null &&
    (user.roles.includes("owner") || user.roles.includes("manager"));

  if (!isSupervisor) {
    return (
      <div className="flex h-full items-center justify-center p-6 text-sm text-muted-foreground">
        Laporan operasional dan finansial hanya dapat diakses oleh Owner atau Manajer.
      </div>
    );
  }

  return <ReportsContent />;
}

function ReportsContent() {
  const [tab, setTab] = useState<TabKey>("sales");
  const [startDate, setStartDate] = useState(todayIso(-7));
  const [endDate, setEndDate] = useState(todayIso());
  const [topSort, setTopSort] = useState<"units" | "value">("units");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  // Data States
  const [sales, setSales] = useState<SalesReport | null>(null);
  const [topProducts, setTopProducts] = useState<TopProductsReport | null>(null);
  const [stock, setStock] = useState<StockReport | null>(null);
  const [shiftDiffs, setShiftDiffs] = useState<ShiftDifferencesReport | null>(null);
  const [receiving, setReceiving] = useState<ReceivingReport | null>(null);
  const [returns, setReturns] = useState<ReturnsReport | null>(null);

  // Ekspor States
  const [exportOpen, setExportOpen] = useState(false);
  const [exportType, setExportType] = useState<ExportReportType>("sales");
  const [exportFormat, setExportFormat] = useState<ExportFormat>("xlsx");
  const [exporting, setExporting] = useState(false);
  const [exportSuccess, setExportSuccess] = useState<string | null>(null);
  const [jobs, setJobs] = useState<ExportJob[]>([]);

  const loadJobs = useCallback(async () => {
    try {
      const res = await exportApi.list({ per_page: 10 });
      setJobs(res.data);
    } catch {
      // Abaikan error background polling jobs
    }
  }, []);

  const loadData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params = {
        start_date: startDate,
        end_date: endDate,
      };

      switch (tab) {
        case "sales":
          setSales(await reportApi.sales(params));
          break;
        case "top-products":
          setTopProducts(
            await reportApi.topProducts({ ...params, sort: topSort, limit: 25 })
          );
          break;
        case "stock":
          setStock(await reportApi.stock({ limit: 100 }));
          break;
        case "shift-differences":
          setShiftDiffs(await reportApi.shiftDifferences(params));
          break;
        case "receiving":
          setReceiving(await reportApi.receiving(params));
          break;
        case "returns":
          setReturns(await reportApi.returns(params));
          break;
      }
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : "Gagal memuat data laporan."
      );
    } finally {
      setLoading(false);
    }
  }, [tab, startDate, endDate, topSort]);

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
    setError(null);
    setExportSuccess(null);
    try {
      await exportApi.create({
        report_type: exportType,
        format: exportFormat,
        start_date: startDate,
        end_date: endDate,
        sort: topSort,
      });
      setExportOpen(false);
      setExportSuccess(
        `Permintaan ekspor "${exportType.toUpperCase()}" (${exportFormat.toUpperCase()}) telah masuk antrean proses.`
      );
      await loadJobs();
    } catch (err) {
      setError(
        err instanceof ApiError ? err.message : "Gagal membuat permintaan ekspor."
      );
    } finally {
      setExporting(false);
    }
  };

  // Quick preset dates
  const setPreset = (days: number) => {
    setStartDate(todayIso(-days));
    setEndDate(todayIso());
  };

  return (
    <div className="flex h-full flex-col bg-slate-50 overflow-y-auto">
      {/* Header */}
      <div className="border-b border-border bg-surface px-6 py-4 shadow-2xs">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <h1 className="text-xl font-bold tracking-tight text-foreground">
              Laporan &amp; Ekspor Operasional
            </h1>
            <p className="text-xs text-muted-foreground mt-0.5">
              Semua angka dihitung langsung dari basis data server (integer Rupiah) yang konsisten dengan mutasi POS.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              onClick={() => {
                setExportType(tab as ExportReportType);
                setExportOpen(true);
              }}
            >
              Unduh / Ekspor File
            </Button>
          </div>
        </div>

        {/* Global Notifications */}
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
        {exportSuccess && (
          <div className="mt-3 flex items-center justify-between rounded-lg bg-emerald-50 p-3 text-xs font-semibold text-emerald-800 border border-emerald-200">
            <span>{exportSuccess}</span>
            <button
              type="button"
              onClick={() => setExportSuccess(null)}
              className="text-emerald-600 hover:text-emerald-900"
            >
              &times;
            </button>
          </div>
        )}
      </div>

      <div className="flex-1 p-6 space-y-6">
        {/* Date Filter Bar */}
        <div className="flex flex-col gap-4 rounded-xl border border-border bg-white p-4 shadow-2xs md:flex-row md:items-end md:justify-between">
          <div className="flex flex-wrap items-end gap-3">
            <div>
              <label className="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">
                Dari Tanggal
              </label>
              <Input
                type="date"
                value={startDate}
                onChange={(e) => setStartDate(e.target.value)}
              />
            </div>
            <div>
              <label className="block text-[11px] font-semibold text-muted-foreground uppercase mb-1">
                Sampai Tanggal
              </label>
              <Input
                type="date"
                value={endDate}
                onChange={(e) => setEndDate(e.target.value)}
              />
            </div>
            <Button
              variant="primary"
              onClick={loadData}
              disabled={loading}
            >
              {loading ? "Memuat..." : "Terapkan Filter"}
            </Button>
          </div>

          {/* Quick Presets */}
          <div className="flex items-center gap-1 text-xs">
            <span className="text-muted-foreground mr-1">Rentang Cepat:</span>
            <button
              type="button"
              onClick={() => setPreset(0)}
              className="rounded-md border border-border bg-slate-50 px-2 py-1 font-medium hover:bg-slate-100"
            >
              Hari Ini
            </button>
            <button
              type="button"
              onClick={() => setPreset(7)}
              className="rounded-md border border-border bg-slate-50 px-2 py-1 font-medium hover:bg-slate-100"
            >
              7 Hari
            </button>
            <button
              type="button"
              onClick={() => setPreset(30)}
              className="rounded-md border border-border bg-slate-50 px-2 py-1 font-medium hover:bg-slate-100"
            >
              30 Hari
            </button>
          </div>
        </div>

        {/* Tab Navigation */}
        <div className="flex gap-1 border-b border-border overflow-x-auto">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setTab(t.key)}
              className={`h-11 rounded-t-lg px-4 text-sm font-medium whitespace-nowrap transition-colors ${
                tab === t.key
                  ? "border-b-2 border-primary text-primary font-bold bg-white"
                  : "text-muted-foreground hover:text-foreground hover:bg-slate-100/60"
              }`}
            >
              {t.label}
            </button>
          ))}
        </div>

        {/* TAB 1: SALES */}
        {tab === "sales" && sales && (
          <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <ReportKpiCard
                title="Penjualan Neto (Excl. Tax)"
                value={formatRupiah(sales.net_sales_excluding_tax)}
                subtitle="Bruto dikurangi retur"
              />
              <ReportKpiCard
                title="Transaksi Selesai"
                value={`${sales.completed_sale_count} struk`}
                subtitle={`Rata-rata: ${formatRupiah(sales.average_transaction_value)}`}
              />
              <ReportKpiCard
                title="Estimasi Laba Kotor"
                value={formatRupiah(sales.estimated_gross_profit)}
                subtitle="Net Penjualan - Net HPP"
                highlight="emerald"
              />
              <ReportKpiCard
                title="HPP Modal (Net COGS)"
                value={formatRupiah(sales.net_cogs)}
                subtitle="Total biaya modal terjual"
              />
            </div>

            <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
              {/* Financial Metrics Breakdown */}
              <div className="rounded-xl border border-border bg-white p-5 shadow-2xs">
                <h3 className="text-sm font-bold text-foreground mb-3">
                  Rincian Penjualan &amp; Pajak
                </h3>
                <div className="divide-y divide-border text-xs">
                  <div className="flex justify-between py-2">
                    <span className="text-muted-foreground">Penjualan Bruto (Gross Net Amount)</span>
                    <span className="font-semibold">{formatRupiah(sales.gross_sales_net_amount)}</span>
                  </div>
                  <div className="flex justify-between py-2">
                    <span className="text-muted-foreground">Pengembalian Dana Retur (Return Net)</span>
                    <span className="font-semibold text-red-600">-{formatRupiah(sales.return_net_amount)}</span>
                  </div>
                  <div className="flex justify-between py-2 font-bold text-foreground">
                    <span>Penjualan Neto Bersih</span>
                    <span>{formatRupiah(sales.net_sales_excluding_tax)}</span>
                  </div>
                  <div className="flex justify-between py-2">
                    <span className="text-muted-foreground">PPN Dipungut dari Pelanggan</span>
                    <span className="font-semibold">{formatRupiah(sales.tax_collected)}</span>
                  </div>
                  <div className="flex justify-between py-2">
                    <span className="text-muted-foreground">PPN Dikembalikan via Retur</span>
                    <span className="font-semibold text-red-600">-{formatRupiah(sales.tax_refunded)}</span>
                  </div>
                  <div className="flex justify-between py-2 font-bold text-foreground">
                    <span>PPN Neto Terkumpul</span>
                    <span>{formatRupiah(sales.net_tax_collected)}</span>
                  </div>
                </div>
              </div>

              {/* Payments by Method */}
              <div className="rounded-xl border border-border bg-white p-5 shadow-2xs">
                <h3 className="text-sm font-bold text-foreground mb-3">
                  Rekap Pembayaran Terkumpul per Metode
                </h3>
                <div className="divide-y divide-border text-xs">
                  {Object.entries(sales.payments.collected).map(([method, val]) => {
                    const ref = sales.payments.refunded[method] ?? 0;
                    const net = sales.payments.net[method] ?? 0;
                    return (
                      <div key={method} className="py-2 flex items-center justify-between">
                        <div className="capitalize font-medium text-foreground">
                          {method === "edc" ? "Kartu Debit/Kredit (EDC)" : method === "transfer" ? "Transfer Bank" : method.toUpperCase()}
                        </div>
                        <div className="text-right">
                          <span className="font-bold">{formatRupiah(net)}</span>
                          {ref > 0 && (
                            <span className="text-[10px] text-muted-foreground block">
                              Masuk: {formatRupiah(val)} • Refund: -{formatRupiah(ref)}
                            </span>
                          )}
                        </div>
                      </div>
                    );
                  })}
                  <div className="flex justify-between py-2.5 font-bold text-sm text-foreground pt-3">
                    <span>Total Bersih Pembayaran</span>
                    <span className="text-emerald-700">{formatRupiah(sales.payments.total_net)}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* TAB 2: TOP PRODUCTS */}
        {tab === "top-products" && topProducts && (
          <div className="rounded-xl border border-border bg-white shadow-2xs overflow-hidden">
            <div className="flex items-center justify-between border-b border-border p-4">
              <h3 className="text-sm font-bold text-foreground">
                Peringkat Produk Terlaris ({topProducts.items.length} item)
              </h3>
              <div className="flex items-center gap-2 text-xs">
                <span className="text-muted-foreground">Urutkan:</span>
                <button
                  type="button"
                  onClick={() => setTopSort("units")}
                  className={`rounded-md px-2.5 py-1 font-medium transition-colors ${
                    topSort === "units"
                      ? "bg-primary text-white"
                      : "bg-slate-100 text-foreground hover:bg-slate-200"
                  }`}
                >
                  Unit Terjual
                </button>
                <button
                  type="button"
                  onClick={() => setTopSort("value")}
                  className={`rounded-md px-2.5 py-1 font-medium transition-colors ${
                    topSort === "value"
                      ? "bg-primary text-white"
                      : "bg-slate-100 text-foreground hover:bg-slate-200"
                  }`}
                >
                  Nilai Penjualan
                </button>
              </div>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-left text-sm">
                <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase">
                  <tr>
                    <th className="px-4 py-3">Peringkat</th>
                    <th className="px-4 py-3">SKU</th>
                    <th className="px-4 py-3">Nama Produk</th>
                    <th className="px-4 py-3 text-right">Unit Terjual</th>
                    <th className="px-4 py-3 text-right">Unit Retur</th>
                    <th className="px-4 py-3 text-right">Unit Net</th>
                    <th className="px-4 py-3 text-right">Nilai Penjualan Net</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border">
                  {topProducts.items.map((it) => (
                    <tr key={it.product_id} className="hover:bg-slate-50/80">
                      <td className="px-4 py-3 font-bold text-foreground">
                        #{it.rank}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                        {it.sku}
                      </td>
                      <td className="px-4 py-3 font-medium text-foreground">
                        {it.name}
                      </td>
                      <td className="px-4 py-3 text-right font-mono">
                        {it.sold_units}
                      </td>
                      <td className="px-4 py-3 text-right font-mono text-red-600">
                        {it.returned_units > 0 ? `-${it.returned_units}` : "0"}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-bold text-emerald-700">
                        {it.net_product_units}
                      </td>
                      <td className="px-4 py-3 text-right font-mono font-bold text-foreground">
                        {formatRupiah(it.net_sales_value)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}

        {/* TAB 3: STOCK & VALUATION */}
        {tab === "stock" && stock && (
          <div className="space-y-4">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
              <ReportKpiCard
                title="Total Produk Aktif"
                value={`${stock.total_products} SKU`}
                subtitle="Katalog terdaftar di sistem"
              />
              <ReportKpiCard
                title="Total Valuasi Sisa Stok"
                value={formatRupiah(stock.total_inventory_value)}
                subtitle="Dasar: Fisik × HPP Rata-rata"
                highlight="emerald"
              />
              <ReportKpiCard
                title="Produk Stok Menipis"
                value={`${stock.items.filter((i) => i.is_low_stock).length} produk`}
                subtitle="Di bawah batas minimum stok"
                highlight="amber"
              />
            </div>

            <div className="rounded-xl border border-border bg-white shadow-2xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase">
                    <tr>
                      <th className="px-4 py-3">No</th>
                      <th className="px-4 py-3">SKU</th>
                      <th className="px-4 py-3">Nama Produk</th>
                      <th className="px-4 py-3">Satuan</th>
                      <th className="px-4 py-3 text-right">Stok Fisik</th>
                      <th className="px-4 py-3 text-right">Min</th>
                      <th className="px-4 py-3 text-center">Status</th>
                      <th className="px-4 py-3 text-right">HPP Rata-rata</th>
                      <th className="px-4 py-3 text-right">Nilai Valuasi</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {stock.items.map((it, idx) => (
                      <tr
                        key={it.product_id}
                        className={`hover:bg-slate-50/80 ${
                          it.is_low_stock ? "bg-amber-50/20" : ""
                        }`}
                      >
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {idx + 1}
                        </td>
                        <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                          {it.sku}
                        </td>
                        <td className="px-4 py-3 font-medium text-foreground">
                          {it.name}
                        </td>
                        <td className="px-4 py-3 text-xs text-muted-foreground">
                          {it.unit}
                        </td>
                        <td className="px-4 py-3 text-right font-mono font-bold">
                          {it.quantity_on_hand}
                        </td>
                        <td className="px-4 py-3 text-right font-mono text-xs text-muted-foreground">
                          {it.minimum_stock}
                        </td>
                        <td className="px-4 py-3 text-center">
                          {it.is_low_stock ? (
                            <Badge variant="warning">Menipis</Badge>
                          ) : (
                            <Badge variant="success">Aman</Badge>
                          )}
                        </td>
                        <td className="px-4 py-3 text-right font-mono text-xs text-muted-foreground">
                          {formatRupiah(Number(it.average_cost))}
                        </td>
                        <td className="px-4 py-3 text-right font-mono font-bold text-foreground">
                          {formatRupiah(it.inventory_value)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 4: SHIFT DIFFERENCES */}
        {tab === "shift-differences" && shiftDiffs && (
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <ReportKpiCard
                title="Shift Ditutup"
                value={`${shiftDiffs.closed_shift_count} sesi`}
                subtitle="Total shift dalam periode"
              />
              <ReportKpiCard
                title="Total Selisih Kas"
                value={formatRupiah(shiftDiffs.total_difference)}
                subtitle={
                  shiftDiffs.total_difference < 0
                    ? "Defisit kas fisik"
                    : shiftDiffs.total_difference > 0
                    ? "Surplus kas fisik"
                    : "Seimbang sempurna"
                }
                highlight={
                  shiftDiffs.total_difference < 0
                    ? "amber"
                    : shiftDiffs.total_difference > 0
                    ? "emerald"
                    : undefined
                }
              />
              <ReportKpiCard
                title="Shift Kurang (Short)"
                value={`${shiftDiffs.short_count} shift`}
                subtitle="Fisik < Sistem"
                highlight={shiftDiffs.short_count > 0 ? "amber" : undefined}
              />
              <ReportKpiCard
                title="Shift Lebih (Over)"
                value={`${shiftDiffs.over_count} shift`}
                subtitle="Fisik > Sistem"
              />
            </div>

            <div className="rounded-xl border border-border bg-white shadow-2xs overflow-hidden">
              <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                  <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase">
                    <tr>
                      <th className="px-4 py-3">No. Shift</th>
                      <th className="px-4 py-3">Kasir</th>
                      <th className="px-4 py-3">Terminal</th>
                      <th className="px-4 py-3">Waktu Tutup</th>
                      <th className="px-4 py-3 text-right">Modal Awal</th>
                      <th className="px-4 py-3 text-right">Kas Sistem</th>
                      <th className="px-4 py-3 text-right">Kas Fisik</th>
                      <th className="px-4 py-3 text-right">Selisih</th>
                      <th className="px-4 py-3 text-center">Catatan</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border">
                    {shiftDiffs.items.length === 0 ? (
                      <tr>
                        <td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">
                          Tidak ada shift yang ditutup dalam periode ini.
                        </td>
                      </tr>
                    ) : (
                      shiftDiffs.items.map((it) => (
                        <tr key={it.shift_id} className="hover:bg-slate-50/80">
                          <td className="px-4 py-3 font-mono font-bold text-foreground">
                            {it.shift_number}
                          </td>
                          <td className="px-4 py-3 text-foreground">
                            {it.cashier || "-"}
                          </td>
                          <td className="px-4 py-3 text-xs font-mono text-muted-foreground">
                            {it.terminal || "-"}
                          </td>
                          <td className="px-4 py-3 text-xs text-muted-foreground">
                            {formatDateTime(it.closed_at)}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-xs">
                            {formatRupiah(it.starting_cash)}
                          </td>
                          <td className="px-4 py-3 text-right font-mono text-xs">
                            {formatRupiah(it.expected_cash)}
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-bold">
                            {formatRupiah(it.actual_cash)}
                          </td>
                          <td className="px-4 py-3 text-right font-mono">
                            {it.difference === 0 ? (
                              <Badge variant="success">Pas (0)</Badge>
                            ) : it.difference > 0 ? (
                              <span className="text-blue-700 font-bold">
                                +{formatRupiah(it.difference)}
                              </span>
                            ) : (
                              <span className="text-red-700 font-bold">
                                {formatRupiah(it.difference)}
                              </span>
                            )}
                          </td>
                          <td className="px-4 py-3 text-center text-xs">
                            {it.has_explanation ? (
                              <Badge variant="info">Ada</Badge>
                            ) : (
                              <span className="text-muted-foreground">-</span>
                            )}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        )}

        {/* TAB 5: RECEIVING */}
        {tab === "receiving" && receiving && (
          <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
            <ReportKpiCard
              title="Dokumen Diposting"
              value={`${receiving.posted_document_count} penerimaan`}
              subtitle="Penerimaan pembelian & saldo awal"
            />
            <ReportKpiCard
              title="Total Kuantitas Masuk"
              value={`${receiving.total_quantity} unit`}
              subtitle="Barang fisik masuk ke toko"
            />
            <ReportKpiCard
              title="Total Nilai Biaya Pembelian"
              value={formatRupiah(receiving.total_cost)}
              subtitle="Total pengeluaran pengadaan stok"
              highlight="emerald"
            />
          </div>
        )}

        {/* TAB 6: RETURNS */}
        {tab === "returns" && returns && (
          <div className="space-y-6">
            <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
              <ReportKpiCard
                title="Retur Selesai"
                value={`${returns.completed_return_count} klaim`}
                subtitle="Permintaan retur disetujui"
              />
              <ReportKpiCard
                title="Total Unit Diretur"
                value={`${returns.total_returned_units} unit`}
                subtitle={`Layak jual: ${returns.restockable_units} • Rusak: ${returns.damaged_units}`}
              />
              <ReportKpiCard
                title="Unit Rusak (Damaged)"
                value={`${returns.damaged_units} unit`}
                subtitle="Dikeluarkan dari stok jual"
                highlight={returns.damaged_units > 0 ? "amber" : undefined}
              />
              <ReportKpiCard
                title="Total Dana Dikembalikan"
                value={formatRupiah(returns.total_refund_amount)}
                subtitle="Refund tunai & non-tunai"
              />
            </div>

            <div className="rounded-xl border border-border bg-white p-5 shadow-2xs">
              <h3 className="text-sm font-bold text-foreground mb-3">
                Rincian Pengembalian Dana per Metode Pembayaran
              </h3>
              <div className="grid grid-cols-2 gap-3 md:grid-cols-4 text-xs">
                <div className="rounded-lg border border-border bg-slate-50 p-3">
                  <span className="text-muted-foreground block">Refund Tunai</span>
                  <span className="text-base font-bold text-foreground">
                    {formatRupiah(returns.refunds_by_method.cash ?? 0)}
                  </span>
                </div>
                <div className="rounded-lg border border-border bg-slate-50 p-3">
                  <span className="text-muted-foreground block">Refund QRIS</span>
                  <span className="text-base font-bold text-foreground">
                    {formatRupiah(returns.refunds_by_method.qris ?? 0)}
                  </span>
                </div>
                <div className="rounded-lg border border-border bg-slate-50 p-3">
                  <span className="text-muted-foreground block">Refund Kartu (EDC)</span>
                  <span className="text-base font-bold text-foreground">
                    {formatRupiah(returns.refunds_by_method.edc ?? 0)}
                  </span>
                </div>
                <div className="rounded-lg border border-border bg-slate-50 p-3">
                  <span className="text-muted-foreground block">Refund Transfer Bank</span>
                  <span className="text-base font-bold text-foreground">
                    {formatRupiah(returns.refunds_by_method.transfer ?? 0)}
                  </span>
                </div>
              </div>
            </div>
          </div>
        )}

        {/* RECENT EXPORTS TABLE */}
        <div className="rounded-xl border border-border bg-white shadow-2xs overflow-hidden mt-8">
          <div className="flex items-center justify-between border-b border-border p-4">
            <div>
              <h3 className="text-sm font-bold text-foreground">
                Berkas Ekspor Dokumen Terakhir
              </h3>
              <p className="text-xs text-muted-foreground">
                File diekspor secara latar belakang, diamankan dengan otorisasi unduh, dan berlaku selama 24 jam.
              </p>
            </div>
            <Button variant="outline" size="sm" onClick={loadJobs}>
              Segarkan Antrean
            </Button>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground uppercase">
                <tr>
                  <th className="px-4 py-3">Jenis Laporan</th>
                  <th className="px-4 py-3">Format</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3">Baris Data</th>
                  <th className="px-4 py-3">Dibuat Pada</th>
                  <th className="px-4 py-3">Kedaluwarsa</th>
                  <th className="px-4 py-3 text-right">Aksi Unduh</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {jobs.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-muted-foreground">
                      Belum ada permintaan ekspor file.
                    </td>
                  </tr>
                ) : (
                  jobs.map((j) => (
                    <tr key={j.id} className="hover:bg-slate-50/80">
                      <td className="px-4 py-3 font-semibold text-foreground capitalize">
                        {j.report_type.replace("-", " ")}
                      </td>
                      <td className="px-4 py-3 font-mono text-xs uppercase font-bold">
                        {j.filters?.format || "XLSX"}
                      </td>
                      <td className="px-4 py-3">
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
                      <td className="px-4 py-3 text-xs font-mono">
                        {j.row_count !== null && j.row_count !== undefined
                          ? `${j.row_count} baris`
                          : "-"}
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {formatDateTime(j.created_at)}
                      </td>
                      <td className="px-4 py-3 text-xs text-muted-foreground">
                        {formatDateTime(j.expires_at)}
                      </td>
                      <td className="px-4 py-3 text-right">
                        {j.status === "completed" ? (
                          <a
                            href={exportApi.downloadUrl(j.id)}
                            className="inline-flex items-center gap-1.5 rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-white hover:bg-primary/90"
                            download
                          >
                            Unduh File
                          </a>
                        ) : j.status === "failed" ? (
                          <span className="text-xs text-red-600 font-medium">
                            {j.error_message || "Gagal"}
                          </span>
                        ) : (
                          <span className="text-xs text-amber-600 font-medium animate-pulse">
                            Memproses...
                          </span>
                        )}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      </div>

      {/* MODAL: EKSPOR LAPORAN */}
      <Modal
        open={exportOpen}
        title="Buat File Ekspor Laporan"
        onClose={() => !exporting && setExportOpen(false)}
      >
        <div className="space-y-4">
          <div>
            <label className="block text-xs font-semibold text-foreground mb-1">
              Jenis Laporan
            </label>
            <select
              value={exportType}
              onChange={(e) => setExportType(e.target.value as ExportReportType)}
              className="w-full rounded-md border border-border bg-white px-3 py-2 text-xs focus:border-primary focus:outline-hidden"
            >
              <option value="sales">Ringkasan Penjualan &amp; Laba Kotor</option>
              <option value="top-products">Peringkat Produk Terlaris</option>
              <option value="stock">Sisa Stok Fisik &amp; Valuasi HPP</option>
              <option value="shift-differences">Rekap Selisih Kas Shift</option>
              <option value="receiving">Rekap Penerimaan Barang Masuk</option>
              <option value="returns">Rekap Retur &amp; Refund</option>
            </select>
          </div>

          <div>
            <label className="block text-xs font-semibold text-foreground mb-1">
              Format Berkas
            </label>
            <div className="grid grid-cols-2 gap-3">
              <button
                type="button"
                onClick={() => setExportFormat("xlsx")}
                className={`rounded-lg border p-3 text-xs font-bold uppercase transition-colors ${
                  exportFormat === "xlsx"
                    ? "border-primary bg-primary/10 text-primary"
                    : "border-border hover:bg-slate-50"
                }`}
              >
                📊 Spreadsheet Excel (.xlsx)
              </button>
              <button
                type="button"
                onClick={() => setExportFormat("pdf")}
                className={`rounded-lg border p-3 text-xs font-bold uppercase transition-colors ${
                  exportFormat === "pdf"
                    ? "border-primary bg-primary/10 text-primary"
                    : "border-border hover:bg-slate-50"
                }`}
              >
                📄 Dokumen PDF (.pdf)
              </button>
            </div>
          </div>

          <div className="rounded-lg border border-border bg-slate-50 p-3 text-xs space-y-1">
            <p className="text-muted-foreground">
              Periode Ekspor: <span className="font-semibold text-foreground">{startDate} s/d {endDate}</span>
            </p>
            <p className="text-[11px] text-muted-foreground">
              🛡️ Semua ekspor Excel dilindungi dari celah formula injection (<kbd className="rounded border bg-white px-1">CSV/Formula Injection Guard</kbd>).
            </p>
          </div>

          <div className="flex justify-end gap-2 pt-2 border-t border-border">
            <Button
              type="button"
              variant="outline"
              disabled={exporting}
              onClick={() => setExportOpen(false)}
            >
              Batal
            </Button>
            <Button
              type="button"
              variant="primary"
              disabled={exporting}
              onClick={onExport}
            >
              {exporting ? "Membuat Antrean..." : "Proses Ekspor Sekarang"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

function ReportKpiCard({
  title,
  value,
  subtitle,
  highlight,
}: {
  title: string;
  value: string;
  subtitle?: string;
  highlight?: "emerald" | "amber";
}) {
  return (
    <div className="rounded-xl border border-border bg-white p-4 shadow-2xs">
      <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider">
        {title}
      </p>
      <p
        className={`mt-1 text-2xl font-bold ${
          highlight === "emerald"
            ? "text-emerald-700"
            : highlight === "amber"
            ? "text-amber-700"
            : "text-foreground"
        }`}
      >
        {value}
      </p>
      {subtitle && (
        <p className="text-xs text-muted-foreground mt-0.5">{subtitle}</p>
      )}
    </div>
  );
}