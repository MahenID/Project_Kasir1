#!/usr/bin/env node
/**
 * tests/uat/end-to-end.mjs
 *
 * UAT end-to-end representatif DragonMart POS (PRD §15 operational release):
 *   1. Cashier login & buka shift.
 *   2. Kasir membuat quote / checkout tunai.
 *   3. Kasir mencetak struk (GET receipt).
 *   4. Manager login & melihat riwayat penjualan (sales index).
 *   5. Kasir/pelanggan mengajukan retur (return request).
 *   6. Manager menyetujui retur (approve) dan menyelesaikan refund (complete).
 *   7. Cashier menutup shift (close) dan melihat Z-report.
 *   8. Owner/Manager melihat laporan penjualan & audit log.
 *
 * Skrip ini memakai HTTP API langsung; tidak menggantikan UAT interaktif
 * pemilik toko, tetapi membuktikan alur data bersih dari buka shift sampai
 * laporan.
 */
import { randomUUID } from "node:crypto";

const BASE = (process.argv.find((a) => a.startsWith("--base="))?.slice(7) || "http://localhost:8000").replace(/\/$/, "");

function parseCookies(res) {
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  return raw.map((c) => c.split(";")[0]).filter(Boolean);
}

function mergeCookies(...lists) {
  const jar = new Map();
  for (const list of lists) {
    for (const pair of list) {
      const eq = pair.indexOf("=");
      if (eq > 0) jar.set(pair.slice(0, eq), pair);
    }
  }
  return [...jar.values()].join("; ");
}

class Session {
  constructor(email, password) {
    this.email = email;
    this.password = password;
    this.cookie = "";
  }

  async login() {
    const csrf = await fetch(`${BASE}/sanctum/csrf-cookie`, { method: "GET" });
    const csrfCookies = parseCookies(csrf);
    const xsrf = decodeURIComponent(
      (csrfCookies.find((c) => c.startsWith("XSRF-TOKEN=")) || "").slice("XSRF-TOKEN=".length)
    );

    const loginRes = await fetch(`${BASE}/auth/login`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-XSRF-TOKEN": xsrf,
        Cookie: mergeCookies(csrfCookies),
        Origin: BASE,
        Referer: `${BASE}/`,
      },
      body: JSON.stringify({ email: this.email, password: this.password }),
    });

    if (!loginRes.ok) throw new Error(`Login ${this.email} gagal: ${await loginRes.text()}`);
    this.cookie = mergeCookies(csrfCookies, parseCookies(loginRes));
    return this;
  }

  req(path, opts = {}) {
    return fetch(`${BASE}${path}`, {
      ...opts,
      headers: { Accept: "application/json", Cookie: this.cookie, ...(opts.headers || {}) },
    });
  }

  async json(path, opts = {}) {
    const res = await this.req(path, opts);
    const text = await res.text();
    let body;
    try {
      body = JSON.parse(text);
    } catch {
      body = { raw: text };
    }
    if (!res.ok) {
      throw new Error(`${opts.method || "GET"} ${path} => ${res.status}: ${text.slice(0, 200)}`);
    }
    return body;
  }
}

function assert(condition, message) {
  if (!condition) throw new Error(`ASSERTION FAILED: ${message}`);
}

async function main() {
  console.log("=== DragonMart POS End-to-End UAT ===");

  // Siapkan 3 peran.
  const cashier = await new Session("mahengon@gmail.com", "Password123!").login();
  const manager = await new Session("manager@dragonmart.local", "Password123!").login();
  const owner = await new Session("owner@dragonmart.local", "Password123!").login();

  // 1. Cashier buka shift.
  const terminals = await cashier.json("/api/v1/terminals");
  let current = await cashier.json("/api/v1/shifts/current");
  let shiftId;
  if (current.data?.shift?.id) {
    shiftId = current.data.shift.id;
    console.log(`[1] Shift sudah terbuka: #${shiftId}`);
  } else {
    // Pilih terminal bebas (terminal yang tidak dipakai shift lain).
    let opened = null;
    for (const t of terminals.data) {
      const res = await cashier.req("/api/v1/shifts/open", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ terminal_id: t.id, starting_cash: 200000 }),
      });
      if (res.ok) {
        opened = await res.json();
        break;
      }
      const err = await res.json().catch(() => ({}));
      if (err?.error?.code !== "TERMINAL_IN_USE") {
        throw new Error(`Buka shift gagal di terminal ${t.code}: ${JSON.stringify(err)}`);
      }
    }
    if (!opened) throw new Error("Semua terminal sedang dipakai kasir lain");
    shiftId = opened.data.id || opened.data.shift.id;
    console.log(`[1] Shift dibuka: #${shiftId}`);
  }

  // 2. Quote + checkout tunai.
  const products = await cashier.json("/api/v1/products?per_page=5");
  assert(products.data.length >= 2, "minimal 2 produk tersedia");

  const quotePayload = {
    items: [
      { product_id: products.data[0].id, quantity: 2 },
      { product_id: products.data[1].id, quantity: 1 },
    ],
  };
  const quote = await cashier.json("/api/v1/checkout/quotes", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(quotePayload),
  });
  const grandTotal = quote.data?.calculation?.grand_total ?? 0;
  assert(quote.data?.quote_id, "quote_id diterima");
  assert(grandTotal > 0, "grand_total positif");
  console.log(`[2] Quote #${quote.data.quote_id}: total Rp ${grandTotal.toLocaleString("id-ID")}`);

  const sale = await cashier.json("/api/v1/sales", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      quote_id: quote.data.quote_id,
      payment_method: "cash",
      tendered_amount: grandTotal,
      idempotency_key: randomUUID(),
    }),
  });
  const saleId = sale.data.id;
  assert(saleId, "sale berhasil dibuat");
  console.log(`[3] Sale #${saleId}: ${sale.data.receipt_number}`);

  // 3. Cetak struk / receipt.
  const receipt = await cashier.json(`/api/v1/sales/${saleId}/receipt`);
  assert(receipt.data?.receipt_number === sale.data.receipt_number, "struk cocok");
  console.log(`[4] Struk OK (${receipt.data.items.length} baris)`);

  // 4. Manager melihat riwayat penjualan.
  const sales = await manager.json("/api/v1/sales?per_page=10");
  const salesList = Array.isArray(sales.data) ? sales.data : sales.data?.data || [];
  assert(salesList.some((s) => s.id === saleId), "sale muncul di riwayat manager");
  console.log(`[5] Manager riwayat penjualan OK (${salesList.length} total)`);

  // 5. Kasir ajukan retur sebagian (1 unit line 1).
  const saleDetail = await manager.json(`/api/v1/sales/${saleId}`);
  const firstItem = saleDetail.data.items[0];
  assert(firstItem, "item penjualan tersedia untuk retur");

  const returnReq = await cashier.json("/api/v1/return-requests", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      sale_id: saleId,
      reason: "Barang rusak / salah ambil",
      lines: [
        {
          sale_item_id: firstItem.id,
          quantity: 1,
          reason: "Kemasan rusak",
          disposition: "damaged",
        },
      ],
    }),
  });
  const returnId = returnReq.data.id;
  assert(returnReq.data.status === "requested", "retur tercatat requested");
  console.log(`[6] Return request #${returnId} dibuat`);

  // 6. Manager approve + complete refund.
  const approved = await manager.json(`/api/v1/return-requests/${returnId}/approve`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ notes: "Disetujui, ganti baru" }),
  });
  assert(approved.data.status === "approved", "retur disetujui");
  // Hitung jumlah refund per unit yang diretur dari baris item transaksi asal.
  const refundAmount = Math.round(Number(firstItem.line_total ?? firstItem.unit_price) / firstItem.quantity);
  console.log(`[7] Return #${returnId} disetujui (refund estimasi Rp ${refundAmount.toLocaleString("id-ID")})`);
  // Complete refund diproses kasir pada shift-nya sendiri (FR-RET-03),
  // bukan oleh manager. Idempotency-Key wajib via header.
  const completed = await cashier.json(`/api/v1/return-requests/${returnId}/complete`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "Idempotency-Key": randomUUID() },
    body: JSON.stringify({
      shift_id: shiftId,
      confirmed_cash_amount: refundAmount,
    }),
  });
  const completedStatus = completed.data?.status ?? completed.data?.return_request?.status;
  assert(completedStatus === "completed", "refund selesai");
  console.log(`[8] Refund selesai: Rp ${refundAmount.toLocaleString("id-ID")}`);

  // 7. Cashier tutup shift. actual_cash = kas awal + penjualan tunai - refund tunai.
  const cashBeforeClose = await cashier.json("/api/v1/shifts/current");
  const expectedCash = cashBeforeClose.data?.x_report?.expected_cash ?? 200000;
  const close = await cashier.json("/api/v1/shifts/close", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ actual_cash: expectedCash, explanation: null }),
  });
  assert(close.data.shift.status === "closed", "shift tertutup");
  assert(close.data.z_report, "Z-report tersedia");
  console.log(`[9] Shift #${shiftId} ditutup (expected cash Rp ${expectedCash.toLocaleString("id-ID")})`);

  // 8. Owner/Manager laporan penjualan.
  const today = new Date().toISOString().split("T")[0];
  const report = await owner.json(
    `/api/v1/reports/sales?start_date=${today}&end_date=${today}`
  );
  assert(typeof report.data.gross_sales_net_amount === "number", "laporan sales memiliki gross_sales_net_amount");
  console.log(`[10] Laporan sales owner OK (gross net Rp ${report.data.gross_sales_net_amount.toLocaleString("id-ID")})`);

  // 9. Audit log.
  const audit = await owner.json("/api/v1/audit-events?per_page=5");
  const auditList = Array.isArray(audit.data) ? audit.data : audit.data?.data || [];
  assert(auditList.length > 0, "audit log tidak kosong");
  console.log(`[11] Audit log OK (${auditList.length} entri terbaru)`);

  // 10. Verifikasi batas peran (role guards negatif).
  // Cashier dilarang membuka laporan manajemen dan audit log.
  const cashierReportRes = await cashier.req(`/api/v1/reports/sales?start_date=${today}&end_date=${today}`);
  assert(cashierReportRes.status === 403, `cashier akses report harus 403, dapat ${cashierReportRes.status}`);

  const cashierAuditRes = await cashier.req("/api/v1/audit-events");
  assert(cashierAuditRes.status === 403, `cashier akses audit log harus 403, dapat ${cashierAuditRes.status}`);
  console.log("[12] Role guards OK: kasir ditolak (403) pada laporan & audit");

  console.log("\n✅ Semua skenario UAT end-to-end lulus.");
}

main().catch((err) => {
  console.error("\n❌ UAT gagal:", err.message);
  process.exit(1);
});
