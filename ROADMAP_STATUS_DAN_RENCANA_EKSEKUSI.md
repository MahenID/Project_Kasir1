# DRAGONMART POS — STATUS ROADMAP, PERUBAHAN & RENCANA EKSEKUSI

Dokumen ini adalah ringkasan status resmi untuk pelacakan, audit, dan panduan kerja bagi **AI Agents** berikutnya. Seluruh acuan produk mengacu pada [PRD_DragonMart_POS_v2.md](file:///home/mahendragon/Project_Mahens/Project_Kasir/PRD_DragonMart_POS_v2.md) dan aturan workflow pada [AGENTS.md](file:///home/mahendragon/Project_Mahens/Project_Kasir/AGENTS.md) / [GEMINI.md](file:///home/mahendragon/Project_Mahens/Project_Kasir/GEMINI.md).

---

## 1. ATURAN KRITIKAL BAGI AI AGENTS (Wajib Diikuti)

1. **Branch Protection**:
   - Dilarang keras melakukan commit atau push langsung ke branch `main`.
   - Workspace Backend: `fase/backend` (Laravel).
   - Workspace Frontend: `fase/frontend` (Next.js).
   - Branch Integrasi: `development`.
   - Alur integrasi: Buat perubahan di phase branch -> jalankan test -> commit & push ke phase branch -> merge ke `development` & push -> kembali ke phase branch.
2. **Integritas Nilai & Keuangan**:
   - Harga jual, diskon, pajak, pembayaran, dan kembalian menggunakan **integer rupiah (`BIGINT`)**, dilarang menggunakan float biner untuk otoritas uang.
   - Harga pokok rata-rata (COGS/average cost) menggunakan presisi **`DECIMAL(20,6)`**.
   - Alokasi diskon faktur wajib menggunakan algoritma **Largest-Remainder (Hamilton-Hare)** agar jumlah rupiah pas tanpa selisih pembulatan.
3. **Integritas Inventaris**:
   - Mutasi stok hanya boleh melalui buku besar mutasi abadi (`stock_movements`). CRUD produk biasa dilarang mengubah kolom stok atau harga modal secara langsung.
   - Penguncian baris produk wajib berurutan menaik (*ascending ID*) untuk mencegah deadlock.
   - Jika stocktake aktif (`inventory_control.active_stocktake_id !== null`), seluruh transaksi yang mengubah stok wajib ditolak (`INVENTORY_FROZEN`).
4. **Otorisasi & Keamanan**:
   - Berbasis sesi Sanctum SPA (cookie HttpOnly + CSRF). Tidak ada token bearer di web client.
   - Kasir tidak boleh menerima data harga pokok (COGS), margin laba, atau nilai inventaris dalam payload API.

---

## 2. YANG SUDAH SELESAI & BERHASIL DIUJI (COMPLETED)

### A. Infrastruktur & Container (Podman/Docker Compose)
- [x] Konfigurasi multi-container aktif dan sehat (`podman-compose up -d`):
  - `kasir-mysql`: MySQL 8.4 LTS dengan InnoDB, UTC event timestamp.
  - `kasir-redis`: Redis 7 alpine untuk cache & queue worker.
  - `kasir-backend`: PHP 8.4.26 / Laravel 13.x dengan ekstensi `pdo_mysql`, `bcmath`, `redis`.
  - `kasir-queue-worker`: Worker antrean background.
  - `kasir-frontend`: Node.js 24 Alpine / Next.js 16.x App Router dengan reverse proxy rewrite (`/api/*`, `/sanctum/*`, `/auth/*`).
- [x] Pengujian konektivitas single-origin:
  - `http://localhost:3000/api/v1/health` -> 200 OK via Next.js proxy.
  - `http://localhost:3000/sanctum/csrf-cookie` -> 204 No Content dengan cookie `XSRF-TOKEN` dan `laravel-session`.

### B. Fase 1 Backend: Foundation, Skema Data & Migrasi Data Lama
- [x] **24 Database Migrations** lengkap dan telah dieksekusi:
  - Users (dengan `is_active`, `must_change_password`, `phone`).
  - Spatie Permission & Activity Log.
  - Store Settings & Terminals (`TERM-01`, `TERM-02`).
  - Kategori (slug), Supplier, Pelanggan, Produk.
  - Shifts (dengan virtual column MySQL & unique constraint `shifts_active_user_unique` dan `shifts_active_terminal_unique`).
  - Cash Movements, Sales, Sale Items, Payments, Checkout Quotes.
  - Receivings & Receiving Items (dokumen penerimaan).
  - Inventory Adjustments & Items (penyesuaian stok & revaluasi).
  - Stocktakes, Stocktake Items, & Inventory Control (jeda audit fisik toko).
  - Return Requests, Return Items, & Refunds (retur & pengembalian dana).
  - Stock Movements (buku besar mutasi stok abadi).
  - Operation Keys (idempotensi persisten) & Export Jobs (antrean ekspor).
- [x] **26 Eloquent Models** lengkap dengan tipe data casts dan relasi antar tabel.
- [x] **Seeders & Migrasi Data Lama dari `projectkasir_old/kasirdragon.sql`**:
  - `RoleAndPermissionSeeder`: 3 role (`owner`, `manager`, `cashier`), 26 permissions.
  - `StoreSettingSeeder`: Pengaturan toko DragonMart POS, Asia/Makassar, PPN 11%.
  - `TerminalSeeder`: Terminal 01 dan Terminal 02.
  - `UserSeeder`: Owner, Manager, dan Kasir (Mahendra, Deadi, Alexandro, Marsel).
  - `LegacyDataSeeder`:
    - 6 Kategori (`Buku Tulis`, `Pulpen`, `Pensil`, `Penghapus`, `Sabun`, `Deterjen`).
    - 5 Supplier (`SUP001`, `SUP002`, `SUP003`, `SUP004`, `SUP006`).
    - 3 Pelanggan (`dewa july`, `Siti Nurhaliza`, `Juan Beti`).
    - 6 Produk ritel.
    - 1 Dokumen penerimaan pembuka (`RCV-OPENING-001`) dan 6 catatan ledger `stock_movements` untuk saldo awal stok (Total fisik stok awal: **669 unit**).

### C. Fase 2 Backend: Core Architecture & Services
- [x] **API Support & Middleware**:
  - `ApiResponse`: Amplop standar respon sukses (`success: true, data, message, meta: {request_id}`) dan error (`success: false, error: {code, message, fields}, meta: {request_id}`).
  - `AssignRequestId`: Injeksi header dan metadata `X-Request-ID`.
  - Format exception terpusat di `bootstrap/app.php` dengan stable domain codes.
- [x] **Authentication Service (`AuthController`)**:
  - `POST /api/v1/auth/login`: Sesi SPA, rate limit 5x gagal per menit, proteksi akun non-aktif.
  - `POST /api/v1/auth/logout`: Invalidate session dan regenerate CSRF token.
  - `GET /api/v1/auth/me`: Data user, peran, izin, dan shift aktif (biaya modal disaring untuk kasir).
  - `PUT /api/v1/auth/profile`: Update nama & nomor telepon.
  - `PUT /api/v1/auth/password`: Verifikasi kata sandi lama, hash bcrypt cost 12.
- [x] **Domain Calculation Service (`CalculationService`)**:
  - Diskon item (persen/bps atau nominal tetap, maksimal harga kotor).
  - Diskon faktur dengan alokasi Largest-Remainder (Hamilton-Hare) dan tie-break produk ID menaik.
  - PPN 11% pembulatan half-up.
  - Harga pokok rata-rata bergerak (*moving weighted-average cost*) presisi 6 desimal.
- [x] **Idempotency Service (`IdempotencyService`)**:
  - Scope `(user_id, operation, key)` dengan canonical SHA-256 payload hash.
  - Deteksi konflik payload (`409 IDEMPOTENCY_CONFLICT`).
  - Cache respon sukses dan deteksi operasi sedang berjalan (`409 OPERATION_IN_PROGRESS`).
  - Recovery endpoints: `/api/v1/checkout/recovery/{key}` dan `/api/v1/operations/{operation}/{key}`.
- [x] **Shift & Cash Reconciliation Service (`ShiftService` & `ShiftController`)**:
  - `POST /api/v1/shifts/open`: Buka shift kasir dengan modal awal (penolakan shift ganda per kasir/terminal).
  - `GET /api/v1/shifts/current`: Pratinjau Laporan X dinamis (uang kas diharapkan = modal awal + kas masuk penjualan - kas keluar retur + kas masuk - kas keluar).
  - `POST /api/v1/shifts/{id}/cash-movements`: Kas masuk / kas keluar berizin manajer (validasi kas keluar tidak melebihi laci).
  - `POST /api/v1/shifts/close`: Penutupan shift dan pembukuan Laporan Z permanen (wajib catatan jika ada selisih kas fisik).
- [x] **Stock Concurrency Service (`StockService`)**:
  - Penguncian baris produk berurutan ascending (`lockForUpdate`).
  - Validasi kecukupan stok (`409 INSUFFICIENT_STOCK`).
  - Penurunan stok atomik dan pencatatan buku besar mutasi abadi.
  - Peningkatan stok dengan rekalkulasi harga pokok rata-rata bergerak.
  - Proteksi jeda stocktake (`409 INVENTORY_FROZEN`).
- [x] **Quote Service (`QuoteService` & `CheckoutQuoteController`)**:
  - `POST /api/v1/checkout/quotes`: Pembuatan kutipan checkout berdurasi 120 detik, validasi wewenang diskon kasir, tanpa reservasi stok fisik.
- [x] **Kontrak OpenAPI 3.1**:
  - Tersedia di [docs/api/openapi-phase2.yaml](file:///home/mahendragon/Project_Mahens/Project_Kasir/docs/api/openapi-phase2.yaml) dan [backend/docs/openapi-phase2.yaml](file:///home/mahendragon/Project_Mahens/Project_Kasir/backend/docs/openapi-phase2.yaml).
- [x] **Automated Test Suite**:
  - 22 tests lulus (115 assertions) di `CalculationServiceTest`, `AuthServiceTest`, `ShiftServiceTest`, `StockAndIdempotencyServiceTest`, dan `Phase1DatabaseTest`.

### D. Fase 3 Backend: First Complete Sale (Commit `194520e` & lanjutan)
- [x] **Atomic Checkout (`CheckoutService`, `SaleController`)**: revalidasi quote (kedaluwarsa 120 detik, payload konflik `409 QUOTE_CHANGED`), lock produk ascending, guard stocktake, validasi tender tunai/non-tunai, snapshot struk permanen.
- [x] **Cetak struk & reprint** (`GET /api/v1/sales/{id}/receipt`, `POST /api/v1/sales/{id}/reprints`): salinan ber-tanda "SALINAN" tercatat audit, tanpa mutasi stok.
- [x] **Riwayat & detail penjualan** dengan filter tanggal/kasir.
- [x] **Test**: `SaleCheckoutTest`, `SaleReprintTest` — checkout sukses, penolakan tender kurang, quote kedaluwarsa, race stok, idempotensi, snapshot struk.

### E. Fase 4 Backend: Master Data, Inventaris & Retur (commit `6b1b58e` s.d. slice retur)
- [x] **Master Data CRUD** (`6b1b58e`): Kategori (slug unik, arsip terkunci saat produk aktif), Produk (SKU unik, upload gambar, komersial versioning), Supplier, Pelanggan; kasir tidak pernah menerima harga modal.
- [x] **Receiving barang** (`fe733de`): Draft → Approve → Post; posting menaikkan stok & menghitung ulang moving average cost.
- [x] **Penyesuaian inventaris** (`c82875a`): kuantitas minus/plus (manager), revaluasi modal (owner-only); semua ber-buku besar mutasi.
- [x] **Stocktake** (`08e0464`): mulai sesi → `INVENTORY_FROZEN`, pencatatan hitungan fisik, posting selisih, pelepasan jeda.
- [x] **Retur & Pengembalian Dana (slice ini)**:
  - `ReturnService`: pengajuan retur dari struk asli (batas waktu 7 hari toko, override owner-only dengan alasan di zona waktu toko).
  - Persetujuan supervisor ber-hash konten (TTL 24 jam), reapproval kedaluwarsa, verifikasi approver/operator masih aktif.
  - Penyelesaian atomik: alokasi kumulatif alokasi split-disposition (`restockable` / `damaged`), restock stok layak jual, item rusak tanpa restock & tanpa pembalikan COGS, refund dari laci shift penangani.
  - Idempotensi `refund-complete` dibungkus transaksi luar + guard `InventoryControl::lockForUpdate()`.
  - Normalisasi data lama `restock→restockable`, `discarded→damaged` via migration `2026_10_11_120000`.
  - Recovery `GET /api/v1/operations/refund-complete/{key}` untuk pemulihan hasil tidak diketahui.
- [x] **Test**: `ReturnRequestApiTest` 8 test (102 assertion) + smoke konkurensi MySQL 14 check (AC-17 race unit terakhir, idempotensi kunci sama, freeze stocktake, rollback tanpa residu) — **134 test / 717 assertion lulus**.
- [x] **Kontrak OpenAPI 3.1 Fase 4**: `docs/api/openapi-phase4.yaml` (termasuk path `/api/v1/return-requests*`).

---

## 3. STATUS SAAT INI (CURRENT SLICE — RETUR & PENGEMBALIAN DANA SELESAI)

Fase 3 & 4 Backend **sudah selesai dan teruji** di branch `fase/backend`:
- Slice retur terakhir (commit baru): endpoint HTTP lengkap, `ReturnService` atomik, migrasi normalisasi `restockable`/`damaged`, test 134 lulus (717 assertion), OpenAPI 4.
- Verifikasi tambahan di MySQL 8.4: 14 check konkurensi/rollback (AC-17 race unit terakhir, idempotensi kunci sama, freeze stocktake, rollback tanpa residu) — **SMOKE PASS**.

Slice berikutnya (lihat Bagian 4) memilih laporan & ekspor sebelum frontend.

## 4. YANG BELUM / AKAN DATANG (UPCOMING PHASES)

### A. Fase 3 Frontend (`fase/frontend`) — Antarmuka Kasir (POS Interface)
- Belum dimulai di luar setup `2a8f78a` (Node 24 + reverse proxy `/api/*`, `/sanctum/*`, `/auth/*`).
- Konfigurasi antarmuka kasir Next.js 16 (layout desktop/tablet landscape, touch target min 44x44px).
- Input pencarian produk & pemindai barcode keyboard/scanner (fokus otomatis, pemindaian berulang menambah kuantitas item).
- Keranjang belanja kasir (cart) dengan estimasi lokal dan sinkronisasi ke server quote.
- Dialog pembayaran:
  - Pembayaran Tunai (input uang diterima & hitung kembalian cepat).
  - Pembayaran Non-Tunai Manual (QRIS, EDC, Transfer) dengan label konfirmasi manual dan input nomor referensi.
- Dialog pratinjau dan cetak struk (layout browser 58mm/80mm).
- Pemulihan transaksi jika koneksi terputus menggunakan `Idempotency-Key` yang tersimpan lokal (non-sensitif).
- Layar buka shift & tutup shift (Laporan X & Laporan Z).
- Antarmuka retur (cari struk, alokasi restockable/damaged, dialog refund dengan `Idempotency-Key`).

### B. Fase 4 Backend — Laporan, Ekspor, & Audit Endpoint (Belum Dimulai)
- **Laporan penjualan**: Net Sales tanpa pajak, PPN terkumpul, HPP (COGS), laba kotor estimasi. Wajib menyaring harga modal dari payload kasir.
- **Laporan operasional**: produk terlaris, sisa stok, pergerakan kas shift, selisih kas.
- **Ekspor XLSX/PDF** via `export_jobs` (antrean DB) dengan proteksi injeksi formula spreadsheet.
- **Audit log endpoint**: tampilan aktivitas operasional & keamanan (owner/manager).
- **Endpoint retur**: tetap merupakan area Fase 4 — slice retur sudah selesai di Backend, tinggal integrasi ke Frontend kasir.

### C. Fase 5 — Verifikasi Rilis & UAT (Release Verification)
- UAT bersama pemilik toko menggunakan alur data riil.
- Pengujian simulasi pemulihan bencana & prosedur backup/restore (RPO <= 24 jam, RTO <= 4 jam).
- Uji beban performa sesuai NFR PRD Section 11.2 (pencarian produk p95 <= 300ms, checkout p95 <= 1500ms).
- Audit keamanan akhir: cookie HttpOnly, proteksi CSRF, header keamanan, parameter binding database.

---

## 5. CARA MELANJUTKAN BAGI AI AGENTS

1. **Periksa branch aktif**:
   ```bash
   git status
   git branch --show-current
   ```
2. **Melanjutkan Backend (Fase 4 sisa: laporan/ekspor/audit)**:
   - Tetap di branch `fase/backend`.
   - Buat `ReportService` / `ExportService` / endpoint audit, daftarkan di `routes/api.php`, dan tambahkan test.
   - Jalankan `podman exec kasir-backend php artisan test` (SQLite) dan smoke MySQL terisolasi untuk kasus konkurensi.
   - Commit & push ke `fase/backend`, merge `--no-ff` ke `development`, push `development`, kembali ke `fase/backend`.
3. **Beralih ke Frontend (Fase 3 frontend)**:
   - Pindah ke `git checkout fase/frontend` (sudah ada setup `2a8f78a`).
   - Bangun antarmuka kasir/retur sesuai Bagian 4A.
4. **Selesai semua**: masuk Fase 5 (UAT, backup/restore, beban, audit keamanan) di Bagian 4C.
