// ============================================================================
// DragonMart POS — API client terpusat (session cookie Sanctum, first-party)
//
// - Semua request memakai same-origin proxy Next.js (`/api`, `/sanctum`) agar
//   cookie sesi tidak melintasi origin lain (tidak ada masalah SameSite).
// - Fetch bootstrap CSRF: GET /sanctum/csrf-cookie menyetel cookie XSRF-TOKEN;
//   tiap request mutasi mengirim header X-XSRF-TOKEN (decodeURIComponent).
// - Setiap mutasi (POST/PUT/PATCH/DELETE) yang membutuhkan idempotensi
//   menggunakan UUID v4 yang dipersistenkan untuk retry yang aman.
// ============================================================================

import type { ApiErrorBody, ApiEnvelope, ApiMeta } from "./types";

const BASE = "/api/v1";
const MUTATING: ReadonlySet<string> = new Set(["POST", "PUT", "PATCH", "DELETE"]);

export class ApiError extends Error {
  readonly code: string;
  readonly status: number;
  readonly fields: Record<string, string[] | string>;
  readonly requestId: string | null;

  constructor(
    message: string,
    opts: {
      code: string;
      status: number;
      fields?: Record<string, string[] | string>;
      requestId?: string | null;
    }
  ) {
    super(message);
    this.name = "ApiError";
    this.code = opts.code;
    this.status = opts.status;
    this.fields = opts.fields ?? {};
    this.requestId = opts.requestId ?? null;
  }
}

/** UUID v4 tanpa dependensi (crypto.getRandomValues, aman di browser & Node 19+). */
export function uuidv4(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  bytes[6] = (bytes[6] & 0x0f) | 0x40; // versi 4
  bytes[8] = (bytes[8] & 0x3f) | 0x80; // varian RFC 4122
  const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

// ---------- Cookie helper (XSRF-TOKEN tidak terenkripsi) ----------
function readCookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const entry = document.cookie
    .split("; ")
    .find((c) => c.startsWith(`${name}=`));
  return entry ? entry.slice(name.length + 1) : null;
}

let csrfInflight: Promise<void> | null = null;

/** Pastikan cookie XSRF-TOKEN tersedia sebelum request mutasi (single-flight). */
async function ensureCsrf(): Promise<void> {
  if (typeof window === "undefined") return; // SSR: hanya dijalankan di client
  if (readCookie("XSRF-TOKEN")) return;
  if (!csrfInflight) {
    csrfInflight = fetch("/sanctum/csrf-cookie", {
      method: "GET",
      credentials: "include",
      headers: { Accept: "application/json" },
    }).then(() => undefined);
  }
  try {
    await csrfInflight;
  } finally {
    csrfInflight = null;
  }
}

// ---------- Opsi request ----------
export interface RequestOptions {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  query?: Record<string, string | number | boolean | undefined | null>;
  /** UUID untuk retry aman pada endpoint idempotent (checkout, cash movement, refund). */
  idempotencyKey?: string;
  signal?: AbortSignal;
}

function buildUrl(path: string, query?: RequestOptions["query"]): string {
  const url = `${BASE}${path}`;
  if (!query) return url;
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined && value !== null && value !== "") {
      qs.set(key, String(value));
    }
  }
  const s = qs.toString();
  return s ? `${url}?${s}` : url;
}

/**
 * Kirim request ke API backend dan kembalikan `data` dari envelope sukses.
 * Melempar `ApiError` untuk setiap respons gagal.
 */
export async function apiRequest<T>(
  path: string,
  options: RequestOptions = {}
): Promise<T> {
  const method = options.method ?? "GET";

  if (MUTATING.has(method)) {
    await ensureCsrf();
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
  };
  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
  }
  const xsrf = readCookie("XSRF-TOKEN");
  if (xsrf) {
    headers["X-XSRF-TOKEN"] = decodeURIComponent(xsrf);
  }
  if (options.idempotencyKey) {
    headers["Idempotency-Key"] = options.idempotencyKey;
  }

  let response: Response;
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      credentials: "include",
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: options.signal,
    });
  } catch {
    throw new ApiError("Tidak dapat terhubung ke server. Periksa koneksi jaringan.", {
      code: "NETWORK_ERROR",
      status: 0,
    });
  }

  let payload: ApiEnvelope<T>;
  try {
    payload = (await response.json()) as ApiEnvelope<T>;
  } catch {
    throw new ApiError(
      `Respons tidak valid dari server (HTTP ${response.status}).`,
      { code: "INVALID_RESPONSE", status: response.status }
    );
  }

  if (!payload.success) {
    const err = payload as ApiErrorBody;
    throw new ApiError(err.error.message, {
      code: err.error.code,
      status: response.status,
      fields: err.error.fields,
      requestId: err.meta?.request_id ?? null,
    });
  }

  return payload.data;
}

/** Sama dengan apiRequest, tapi juga mengembalikan meta (pagination, dsb). */
export async function apiRequestWithMeta<T>(
  path: string,
  options: RequestOptions = {}
): Promise<{ data: T; meta: ApiMeta }> {
  const method = options.method ?? "GET";
  if (MUTATING.has(method)) {
    await ensureCsrf();
  }
  // Gunakan fetch langsung agar meta tetap tersedia.
  const headers: Record<string, string> = { Accept: "application/json" };
  if (options.body !== undefined) headers["Content-Type"] = "application/json";
  const xsrf = readCookie("XSRF-TOKEN");
  if (xsrf) headers["X-XSRF-TOKEN"] = decodeURIComponent(xsrf);
  if (options.idempotencyKey) headers["Idempotency-Key"] = options.idempotencyKey;

  const response = await fetch(buildUrl(path, options.query), {
    method,
    credentials: "include",
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    signal: options.signal,
  });

  const payload = (await response.json()) as ApiEnvelope<T>;
  if (!payload.success) {
    const err = payload as ApiErrorBody;
    throw new ApiError(err.error.message, {
      code: err.error.code,
      status: response.status,
      fields: err.error.fields,
      requestId: err.meta?.request_id ?? null,
    });
  }
  return { data: payload.data, meta: payload.meta };
}
