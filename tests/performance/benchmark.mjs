#!/usr/bin/env node
/**
 * benchmark.mjs — DragonMart POS Fase 5 performance benchmark.
 *
 * Mengukur NFR-PERF-01 (pencarian produk & exact scan ≤300 ms p95) dan
 * NFR-PERF-02 (quote checkout ≤1.500 ms p95 untuk 5 operator konkuren).
 *
 * Karena endpoint berada di belakang auth:sanctum (cookie sesi first-party),
 * skrip login via /auth/login untuk mendapatkan cookie sesi Laravel, lalu
 * memakainya untuk request terautentikasi.
 *
 * Pemakaian:
 *   node tests/performance/benchmark.mjs --base http://localhost:8000 \
 *     --email owner@dragonmart.local --password 'Password123!' --samples 1000
 */
import { performance } from "node:perf_hooks";

function parseArgs(argv) {
  const out = { base: "http://localhost:8000", samples: 1000, concurrency: 5 };
  for (let i = 2; i < argv.length; i += 2) {
    const key = argv[i].replace(/^--/, "");
    const val = argv[i + 1];
    if (key === "samples" || key === "concurrency") out[key] = Number(val);
    else out[key] = val;
  }
  return out;
}

const args = parseArgs(process.argv);
const BASE = args.base.replace(/\/$/, "");

/** Percentile helper (input sudah terurut menaik). */
function pct(sorted, p) {
  if (sorted.length === 0) return 0;
  const idx = Math.min(sorted.length - 1, Math.ceil((p / 100) * sorted.length) - 1);
  return sorted[idx];
}

function summarize(label, times, errors, wallMs, targetMs) {
  const sorted = [...times].sort((a, b) => a - b);
  const avg = sorted.reduce((s, v) => s + v, 0) / (sorted.length || 1);
  return {
    label,
    target: `p95 <= ${targetMs} ms`,
    passed: pct(sorted, 95) <= targetMs,
    samples: sorted.length,
    errors,
    wallMs: Math.round(wallMs),
    rps: Number((sorted.length / (wallMs / 1000)).toFixed(1)),
    avgMs: Number(avg.toFixed(1)),
    p50Ms: Number(pct(sorted, 50).toFixed(1)),
    p95Ms: Number(pct(sorted, 95).toFixed(1)),
    p99Ms: Number(pct(sorted, 99).toFixed(1)),
    maxMs: Number((sorted[sorted.length - 1] || 0).toFixed(1)),
  };
}

/** Ekstrak daftar "name=value" dari header Set-Cookie. */
function extractCookies(res) {
  const raw = res.headers.getSetCookie ? res.headers.getSetCookie() : [];
  return raw.map((c) => c.split(";")[0]).filter(Boolean);
}

/** Gabungkan beberapa daftar cookie; entri terbaru menimpa yang lama. */
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

async function login(email, password) {
  const csrfRes = await fetch(`${BASE}/sanctum/csrf-cookie`, { method: "GET" });
  const csrfCookies = extractCookies(csrfRes);
  const xsrfMatch = csrfCookies
    .find((c) => c.startsWith("XSRF-TOKEN="));
  const xsrf = xsrfMatch ? decodeURIComponent(xsrfMatch.slice("XSRF-TOKEN=".length)) : "";

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
    body: JSON.stringify({ email, password }),
  });

  const loginCookies = extractCookies(loginRes);
  const cookie = mergeCookies(csrfCookies, loginCookies);

  if (!loginRes.ok) {
    throw new Error(`Login gagal (${loginRes.status}): ${await loginRes.text()}`);
  }
  return cookie;
}

/** Jalankan `total` request dengan `concurrency` paralel memakai fungsi `fn`. */
async function load(total, concurrency, fn) {
  const times = [];
  const errors = [];
  let issued = 0;
  const started = performance.now();

  async function worker() {
    while (issued < total) {
      issued += 1;
      const t0 = performance.now();
      try {
        await fn();
        times.push(performance.now() - t0);
      } catch (err) {
        errors.push(String(err && err.message ? err.message : err));
      }
    }
  }

  await Promise.all(Array.from({ length: concurrency }, worker));
  return { times, errors, wallMs: performance.now() - started };
}

async function main() {
  const email = args.email || "owner@dragonmart.local";
  const password = args.password || "Password123!";
  const cookie = await login(email, password);
  const headers = { Accept: "application/json", Cookie: cookie };

  const searchTerms = ["a", "ka", "kop", "mie", "minum", "gula", "beras", "teh"];
  const productIds = (args.productIds || "1,2,3,4,5")
    .split(",")
    .map((v) => Number(v.trim()))
    .filter((v) => Number.isFinite(v) && v > 0);

  // Warmup opcache Laravel & buffer MySQL.
  for (let i = 0; i < 20; i += 1) {
    await fetch(`${BASE}/api/v1/products?per_page=5`, { headers }).catch(() => {});
  }

  // NFR-PERF-01a: pencarian produk (LIKE query).
  const search = await load(args.samples, args.concurrency, async () => {
    const q = searchTerms[Math.floor(Math.random() * searchTerms.length)];
    const res = await fetch(`${BASE}/api/v1/products?search=${encodeURIComponent(q)}&per_page=10`, {
      headers,
    });
    if (!res.ok) throw new Error(`search ${res.status}`);
    await res.arrayBuffer();
  });

  // NFR-PERF-01b: exact scan lookup (GET satu produk).
  const lookup = await load(args.samples, args.concurrency, async () => {
    const id = productIds[Math.floor(Math.random() * productIds.length)];
    const res = await fetch(`${BASE}/api/v1/products/${id}`, { headers });
    if (!res.ok && res.status !== 404) throw new Error(`lookup ${res.status}`);
    await res.arrayBuffer();
  });

  // Setup: buka shift bila belum ada, karena quote menolak SHIFT_REQUIRED.
  const shiftId = await ensureShift(headers, email);

  // NFR-PERF-02: quote checkout (5 operator konkuren).

  const quote = await load(Math.max(100, Math.round(args.samples / 4)), 5, async () => {
    const res = await fetch(`${BASE}/api/v1/checkout/quotes`, {
      method: "POST",
      headers: { ...headers, "Content-Type": "application/json" },
      body: JSON.stringify({
        items: [{ product_id: productIds[0], quantity: 1 }],
      }),
    });
    if (!res.ok && res.status !== 422) throw new Error(`quote ${res.status}`);
    await res.arrayBuffer();
  });

  const report = {
    environment: {
      base: BASE,
      email,
      node: process.version,
      samples: args.samples,
      concurrency: args.concurrency,
      timestamp: new Date().toISOString(),
    },
    results: [
      summarize("NFR-PERF-01 product search", search.times, search.errors.length, search.wallMs, 300),
      summarize("NFR-PERF-01 exact scan lookup", lookup.times, lookup.errors.length, lookup.wallMs, 300),
      summarize("NFR-PERF-02 checkout quote", quote.times, quote.errors.length, quote.wallMs, 1500),
    ],
  };

  console.log(JSON.stringify(report, null, 2));
}

/**
 * Pastikan ada shift terbuka untuk user benchmark. Quote/checkout menolak
 * dengan 409 SHIFT_REQUIRED bila kasir belum membuka shift (PRD FR-POS).
 * Mengembalikan shift id yang aktif.
 */
async function ensureShift(headers, email) {
  const current = await fetch(`${BASE}/api/v1/shifts/current`, { headers });
  if (current.ok) {
    const body = await current.json();
    const shiftId = body.data?.id ?? body.data?.shift?.id ?? null;
    if (shiftId) return shiftId;
  }
  const terminalsRes = await fetch(`${BASE}/api/v1/terminals`, { headers });
  if (!terminalsRes.ok) throw new Error(`terminals ${terminalsRes.status}`);
  const terminalsBody = await terminalsRes.json();
  const terminals = terminalsBody.data || [];
  if (terminals.length === 0) throw new Error("Tidak ada terminal aktif untuk buka shift");

  const open = await fetch(`${BASE}/api/v1/shifts/open`, {
    method: "POST",
    headers: { ...headers, "Content-Type": "application/json" },
    body: JSON.stringify({ terminal_id: terminals[0].id, starting_cash: 200000 }),
  });
  if (!open.ok) {
    const text = await open.text();
    throw new Error(`Buka shift gagal (${open.status}) untuk ${email}: ${text}`);
  }
  const openBody = await open.json();
  return openBody.data?.id ?? openBody.data?.shift?.id ?? null;
}

main().catch((err) => {
  console.error("Benchmark gagal:", err.message);
  process.exit(1);
});
