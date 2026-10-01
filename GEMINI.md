<!-- antislop:start -->
## antislop
For UI, copy, people, mobile layout, or code comments work, read these installed skill files directly (use these paths even if a same-named global skill exists):
- Core filter, always on: `antislop`: `.gemini/skills/antislop/SKILL.md`
- UI / visual: `antislop-ui`: `.gemini/skills/antislop-ui/SKILL.md`
- Copy & text: `antislop-copywriting`: `.gemini/skills/antislop-copywriting/SKILL.md`
- People: `antislop-human`: `.gemini/skills/antislop-human/SKILL.md`
- Mobile / responsive: `antislop-layoutmobile`: `.gemini/skills/antislop-layoutmobile/SKILL.md`
- Code comments: `antislop-code`: `.gemini/skills/antislop-code/SKILL.md`
Before starting, follow the core's "Two Usage Modes" section in strict order: explicit session instruction first, then global preference, then ask. A session instruction always wins. For a resolved mode, say `antislop active: <mode> (session override).` or `antislop active: <mode> (global preference).` once before presenting findings or making edits, using the actual mode and source. Acknowledging the user's request without naming the source does not replace this notice.
Only an explicit choice of antislop during or after selects a session mode. A request to review, audit, or avoid file edits does not select a mode; read the global preference in that case. Another skill's mode does not select antislop's mode.
If the mode is unresolved, ask during/after and end the response; wait for the answer before any UI review, planning, or concept. For read-only tasks, put the active-mode notice only at the start of the final answer, never in progress messages. For editing tasks, announce before the first edit and omit it from the final answer.
To update antislop later: `npx antislop-ai --update`, or run `npx antislop-ai` and pick Overwrite them.
<!-- antislop:end -->

# PROJECT DEVELOPMENT RULES & WORKFLOW GUIDELINES

Project Sistem Kasir (Point of Sale) modern dengan arsitektur terpisah antara Backend (Laravel) dan Frontend (Next.js).

---

## 1. GIT BRANCHING STRATEGY & WORKSPACE RULES

Project ini menggunakan strategi Git branching terstruktur untuk menjaga stabilitas kode:

### Branch Structure
1. **`main`** (PRODUCTION - STRICTLY PROTECTED):
   - **DILARANG KERAS** menyentuh, commit, merge, atau push langsung ke branch `main`.
   - Branch `main` **HANYA BOLEH disentuh** jika ada instruksi eksplisit langsung dari user (contoh: "push ke main" atau "merge ke main").
2. **`development`** (MAIN INTEGRATION BRANCH):
   - Branch utama tempat penggabungan seluruh fitur dan fase yang telah selesai.
   - Semua perubahan dari `fase/backend` dan `fase/frontend` wajib di-merge ke branch ini setelah lolos uji dan di-push ke branch fasenya masing-masing.
3. **`fase/backend`** (BACKEND WORKSPACE):
   - Ruang kerja khusus untuk pengerjaan, perbaikan (fix), revisi, penambahan modul/fitur pada sisi Backend (Laravel).
4. **`fase/frontend`** (FRONTEND WORKSPACE):
   - Ruang kerja khusus untuk pengerjaan, perbaikan (fix), revisi, perubahan UI/UX, styling, komponen pada sisi Frontend (Next.js).

---

## 2. PROTOKOL EKSEKUSI TUGAS (DEVELOPMENT WORKFLOW)

Setiap kali menerima perintah perbaikan, revisi, atau implementasi kode dari user:

### A. Protokol Pengerjaan Backend
1. **Beralih ke Workspace Backend**: Checkout ke branch `fase/backend` (`git checkout fase/backend`).
2. **Implementasi & Perbaikan**: Kerjakan kode sesuai fase arsitektur backend yang dituju.
3. **Commit & Push ke Fase**:
   - Stage & commit perubahan dengan pesan yang jelas.
   - Push langsung ke branch `fase/backend`:
     ```bash
     git push origin fase/backend
     ```
4. **Merge ke Branch Development**:
   - Beralih ke branch `development` (`git checkout development`).
   - Merge perubahan dari `fase/backend`:
     ```bash
     git merge fase/backend
     git push origin development
     ```
5. **Kembali ke Workspace**: Kembali ke branch pengerjaan (`git checkout fase/backend`).
6. **Strict Protection**: Dilarang menyentuh / merge ke `main`!

### B. Protokol Pengerjaan Frontend
1. **Beralih ke Workspace Frontend**: Checkout ke branch `fase/frontend` (`git checkout fase/frontend`).
2. **Implementasi & Perbaikan**: Kerjakan kode sesuai fase arsitektur frontend yang dituju.
3. **Commit & Push ke Fase**:
   - Stage & commit perubahan dengan pesan yang jelas.
   - Push langsung ke branch `fase/frontend`:
     ```bash
     git push origin fase/frontend
     ```
4. **Merge ke Branch Development**:
   - Beralih ke branch `development` (`git checkout development`).
   - Merge perubahan dari `fase/frontend`:
     ```bash
     git merge fase/frontend
     git push origin development
     ```
5. **Kembali ke Workspace**: Kembali ke branch pengerjaan (`git checkout fase/frontend`).
6. **Strict Protection**: Dilarang menyentuh / merge ke `main`!

---

## 3. ALUR PEMBUATAN BACKEND LARAVEL (5 FASE)
*Dari Migration hingga Production Ready*

### FASE 1 – DATABASE
- **01. Migrations**: Perancangan skema database terstruktur, index kunci, dan integritas data relasional.
- **02. Models & Relations**: Pembuatan Eloquent Models, pendefinisian casts, timestamps, dan relasi antar tabel (HasMany, BelongsTo, BelongsToMany, dll).
- **03. Factories**: Penyediaan model factories untuk mock data dan otomatisasi data dummy testing.
- **04. Seeders**: Pengisian data awal sistem (master roles, akun admin, data kategori barang default).

### FASE 2 – CORE APPLICATION
- **05. Repository Layer**: Abstraksi data layer untuk pemisahan query database dari business logic.
- **06. Service Layer**: Pusat penanganan logika bisnis aplikasi (kalkulasi transaksi, manajemen inventory stok, promo/diskon).
- **07. Form Requests**: Validasi request terisolasi (aturan validasi, kustomisasi pesan error, dan izin request).
- **08. Policies & Permissions**: Pengaturan izin akses aksi data (RBAC - Role-Based Access Control) via Laravel Gate & Policy.

### FASE 3 – REST API
- **09. Controllers**: Thin controllers yang bertindak sebagai penghubung HTTP request menuju Service Layer.
- **10. API Resources**: Transformasi respon data JSON yang konsisten, aman, dan efisien.
- **11. Routes & Middleware**: Definisi rute API (`routes/api.php`), API versioning, rate limiting, dan middleware pipeline.
- **12. Authentication**: Implementasi sistem otentikasi token yang aman (Laravel Sanctum).

### FASE 4 – INTEGRATION
- **13. Spatie Media Library**: Penanganan berkas upload (gambar produk, bukti struk, profil).
- **14. Spatie Activitylog**: Pencatatan riwayat audit transaksi, perubahan data penting, dan log aktivitas user.
- **15. Redis & Queue**: Caching performa tinggi dan pengelolaan asynchronous background jobs (antrean cetak, rekap laporan).
- **16. Events & Notifications**: Event-driven architecture untuk broadcast data transaksi real-time dan notifikasi.

### FASE 5 – QUALITY & RELEASE
- **17. Tests & API Docs**: Automated feature & unit testing, serta dokumentasi endpoint API lengkap.
- **18. Deployment & Monitoring**: Konfigurasi server production, caching optimasi Laravel, monitoring log dan error.
- **-> PRODUCTION READY**

---

## 4. ALUR PEMBUATAN FRONTEND NEXT.JS (5 FASE)
*Dari Setup Project hingga Production Ready*

### FASE 1 – FOUNDATION
- **01. Create Next.js App**: Fondasi arsitektur Next.js berbasis App Router.
- **02. Project Structure**: Tata letak folder rapi (`app/`, `components/`, `lib/`, `hooks/`, `types/`, `services/`).
- **03. Environment Config**: Konfigurasi variabel lingkungan aman (`NEXT_PUBLIC_API_URL`, dll).
- **04. Styles & UI**: Setup tema visual, utility styling, tipografi modern, dan desain sistem antarmuka kasir.

### FASE 2 – CORE ARCHITECTURE
- **05. Types & Utils**: Interface dan tipe TypeScript yang ketat untuk seluruh domain data (Produk, Transaksi, User) serta fungsi utility.
- **06. API Client**: HTTP Client terpusat (Fetch/Axios wrapper) dengan token interceptor dan centralized error handling.
- **07. Auth Service**: Manajemen session login, penyimpanan aman token akses, auto-refresh token, dan logout.
- **08. Proxy & Route Guard**: Middleware proteksi halaman privat dan pengalihan hak akses sesuai peran (Admin vs Kasir).

### FASE 3 – UI DEVELOPMENT
- **09. Root Layout**: Struktur layout induk aplikasi (Sidebar POS, Header kasir, Status bar kasir).
- **10. Shared Components**: Koleksi komponen UI reusable (Button, Input, Modal, Badge, Dropdown, Table).
- **11. Pages & Server Components**: Halaman server-rendered untuk efisiensi loading awal data.
- **12. Client Components**: Komponen interaktif transaksi (katalog barang instan, kalkulator bayar/kembalian, shopping cart).

### FASE 4 – FEATURE INTEGRATION
- **13. Forms & Validation**: Form checkout, input produk baru, dan validasi schema client-side.
- **14. Feature Services**: Abstraksi pemanggilan API per modul fitur kasir.
- **15. Hooks & State**: Custom React hooks untuk manajemen keranjang belanja kasir dan state transaksi aktif.
- **16. Role & Permission UI**: Tampilan antarmuka yang adaptif sesuai kewenangan pengguna.

### FASE 5 – QUALITY & RELEASE
- **17. Media Upload**: Antarmuka upload media gambar barang dengan preview visual instan.
- **18. Loading, Error & Cache**: Penanganan status loading elegan (skeletons), error boundary, dan strategi caching client.
- **19. Tests & Production Build**: Pengujian fungsionalitas komponen dan validasi build produksi bersih (`npm run build`).
- **20. Deployment & Monitoring**: Konfigurasi deployment, optimasi bundle, dan pemantauan performa Web Vitals.
- **-> PRODUCTION READY**
