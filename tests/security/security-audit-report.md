# DragonMart POS — Laporan Audit Keamanan Akhir (Fase 5 / NFR-SEC)

**Tanggal**: 2026-10-11  
**Lingkungan**: Production-like container stack lokal (MySQL 8.4, Redis 7, PHP 8.4.26, Next.js 16)  
**Referensi**: `PRD_DragonMart_POS_v2.md` Section 11.2 (NFR-SEC-01–03, NFR-OPS-01)

---

## 1. Ringkasan Eksekutif

| Aspek | Standar PRD | Status Verifikasi | Catatan |
|---|---|---|---|
| **Sesi & Cookie** | First-party HttpOnly, SameSite=Lax, 60m lifetime | ✅ **LULUS** | `laravel-session` httponly, samesite=lax, max-age 3600s terverifikasi runtime. |
| **Proteksi CSRF** | Cookie `XSRF-TOKEN` + validasi Sanctum | ✅ **LULUS** | Request POST tanpa token / cookie ditolak 401/419. |
| **Rate Limiting Login** | Maks 5 upaya gagal per menit per IP/akun | ✅ **LULUS** | Diuji aktif: return 429 `RATE_LIMIT_EXCEEDED` saat melampaui batas; auto-clear pada login sukses. |
| **Otorisasi Granular** | Role & permission via Spatie; batasan role kasir | ✅ **LULUS** | Terverifikasi di 150 test + smoke test UAT: kasir dilarang (403) pada laporan & audit. |
| **Injeksi SQL** | Parameter binding ketat; tidak ada user input di `DB::raw` | ✅ **LULUS** | Seluruh 19 pemakaian `DB::raw`/`selectRaw` hanya ekspresi statis (`SUM`, `COUNT`, `MAX`). |
| **Mass Assignment** | Eloquent guarded / `$fillable` eksplisit | ✅ **LULUS** | Seluruh model memakai `$fillable` eksplisit dan FormRequest validasi ketat. |
| **Response Filtering** | Biaya/HPP & laba tidak bocor ke kasir | ✅ **LULUS** | Kasir response `SaleController` & `ReturnService` menyembunyikan `restored_cost_snapshot` dan margins. |
| **Header Keamanan HTTP** | HSTS, X-Frame-Options, X-Content-Type-Options | ⚠️ **CATATAN RILIS** | Belum ada middleware security headers di level aplikasi; direkomendasikan ditangani pada reverse proxy (Nginx/Caddy) atau middleware tambahan sebelum deploy publik. |

---

## 2. Bukti Pengujian Runtime

### A. Cookie & Sesi
Header `Set-Cookie` dari respons `POST /auth/login`:
```http
Set-Cookie: XSRF-TOKEN=...; expires=Sun, 11 Oct 2026 10:40:26 GMT; Max-Age=3600; path=/; domain=localhost; samesite=lax
Set-Cookie: laravel-session=...; expires=Sun, 11 Oct 2026 10:40:26 GMT; Max-Age=3600; path=/; domain=localhost; httponly; samesite=lax
```
- Flag `Secure` saat ini nonaktif di development lokal (HTTP). Untuk deployment produksi (HTTPS), set `SESSION_SECURE_COOKIE=true` di `.env.production`.

### B. Rate Limiting Login (NFR-SEC-02)
- Endpoint: `POST /auth/login`
- Respons saat melampaui 5 percobaan:
```json
{
  "success": false,
  "error": {
    "code": "RATE_LIMIT_EXCEEDED",
    "message": "Terlalu banyak permintaan. Silakan tunggu beberapa saat.",
    "fields": {}
  }
}
```

### C. Penegakan Peran & Pembatasan Data Sensitif (NFR-SEC-01)
Pengujian smoke pada skrip UAT:
- `GET /api/v1/reports/sales` oleh kasir → HTTP **403 FORBIDDEN** (pesan: "Anda tidak memiliki izin untuk melihat laporan ini.").
- `GET /api/v1/audit-events` oleh kasir → HTTP **403 FORBIDDEN**.
- Kasir hanya dapat melihat transaksi miliknya sendiri pada `GET /api/v1/sales`.
- HPP (`restored_cost_snapshot`, `total_cost`) otomatis di-strip dari payload jika pemanggil hanya berstatus kasir.

### D. Parameter Binding & Sanitasi Query (NFR-SEC-03)
Inspeksi kode pada 19 pemakaian raw query:
- `app/Services/ShiftService.php:85`: `DB::raw('SUM(amount_due) as total'), DB::raw('COUNT(*) as count')`
- `app/Services/ReportService.php:53-57`: `selectRaw('COUNT(*) as sale_count')`, `selectRaw('COALESCE(SUM(net_total), 0) as net_total')`, dsb.
- `app/Services/ReturnService.php:778`: `selectRaw('return_items.sale_item_id, SUM(return_items.quantity) as qty')`
Semua parameter ekspresi agregasi adalah string statis tanpa interpolasi variabel pengguna.

---

## 3. Rekomendasi Sebelum Peluncuran Publik

1. **Aktifkan `SESSION_SECURE_COOKIE=true`** pada file `.env.production` ketika SSL/TLS aktif.
2. **Tambahkan Security Headers pada Reverse Proxy** (Nginx/Caddy):
   ```nginx
   add_header X-Frame-Options "DENY" always;
   add_header X-Content-Type-Options "nosniff" always;
   add_header Referrer-Policy "strict-origin-when-cross-origin" always;
   add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
   ```
3. **Rotasi `APP_KEY`** sebelum rilis produksi pertama dan simpan di secret manager yang aman.
