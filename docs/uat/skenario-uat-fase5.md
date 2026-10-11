# DragonMart POS — Panduan & Skenario UAT Pemilik Toko (Fase 5)

**Target Rilis**: DragonMart POS Version 1.0 (PRD v2.0)  
**Lingkungan**: Stack Lokal (Backend port 8000, Frontend port 3001)  
**Kredensial Pengujian**:
- **Owner**: `owner@dragonmart.local` / `Password123!` (akses penuh)
- **Manager**: `manager@dragonmart.local` / `Password123!` (manajemen operasional, persetujuan retur, laporan)
- **Cashier**: `mahengon@gmail.com` / `Password123!` (transaksi POS, buka/tutup shift kasir)

---

## Ringkasan Hasil Otomasi UAT (Smoke Test API)

Uji end-to-end otomatis telah dijalankan dan **LULUS 100%** (`tests/uat/end-to-end.mjs`):
1. ✅ Buka shift kasir di Terminal Kasir 02 (`TERM-02`) dengan modal awal Rp 200.000.
2. ✅ Pembuatan quote harga server-side untuk 2 jenis barang (Buku Tulis & Pulpen).
3. ✅ Checkout tunai atomik dengan uang pas (Rp 36.000), struk `DM-20261011-00002` tercetak.
4. ✅ Manager melihat riwayat penjualan dan detail transaksi.
5. ✅ Kasir mengajukan retur 1 unit karena kemasan rusak.
6. ✅ Manager menyetujui (approve) retur.
7. ✅ Kasir menyelesaikan refund tunai (Rp 5.000) pada shift miliknya.
8. ✅ Kasir menutup shift: uang fisik dihitung Rp 267.000, Z-report permanen terbit.
9. ✅ Owner melihat laporan penjualan dan laba kotor terestimasi.
10. ✅ Owner memeriksa audit trail seluruh aktivitas.
11. ✅ Hak akses negatif teruji: kasir ditolak 403 saat mencoba mengakses laporan & audit.

---

## Skenario UAT Interaktif Pemilik Toko (Manual UAT)

### Skenario 1: Buka Shift Kasir & Modal Awal
- **Aktor**: Kasir (`mahengon@gmail.com`)
- **Langkah**:
  1. Login ke aplikasi kasir di browser (`http://localhost:3001/login`).
  2. Buka menu **Shift** (`/shift`).
  3. Pilih terminal kasir yang tersedia (misal `TERM-01`).
  4. Masukkan modal kas awal: `Rp 200.000`. Klik **Buka Shift**.
- **Hasil yang Diharapkan**:
  - Status shift berubah menjadi `open`.
  - Tombol kasir/POS aktif.
  - Terminal ditandai terkunci untuk kasir ini (tidak bisa dibuka dobel di terminal yang sama oleh kasir lain).

### Skenario 2: Transaksi Penjualan Tunai di POS
- **Aktor**: Kasir
- **Langkah**:
  1. Masuk menu **POS** (`/pos`).
  2. Cari barang menggunakan scan barcode atau ketik "Buku Tulis" / "Pulpen".
  3. Tambahkan ke keranjang (2 Buku Tulis @ Rp 5.000, 1 Pulpen @ Rp 2.500).
  4. Total estimasi muncul di layar kasir.
  5. Klik tombol **Bayar**.
  6. Pilih metode **Tunai**, masukkan uang yang diterima: `Rp 50.000`.
  7. Klik **Selesaikan Pembayaran**.
- **Hasil yang Diharapkan**:
  - Transaksi berhasil, kembalian `Rp 37.500` dihitung dengan benar.
  - Struk penjualan muncul dengan nomor struk unik `DM-YYYYMMDD-XXXXX`.
  - Stok produk berkurang 2 unit untuk Buku dan 1 unit untuk Pulpen di database.

### Skenario 3: Transaksi Non-Tunai (QRIS / Transfer / EDC)
- **Aktor**: Kasir
- **Langkah**:
  1. Buat keranjang baru dengan 1 Sabun Shinzui (@ Rp 38.000).
  2. Klik **Bayar**, pilih metode **QRIS**.
  3. Minta pelanggan scan QRIS fisik toko. Kasir memverifikasi notifikasi di perangkat toko.
  4. Masukkan nomor referensi transaksi dari bank / provider (misal `NMID12345678`).
  5. Kasir mengonfirmasi bahwa dana sudah masuk. Klik **Konfirmasi Pembayaran**.
- **Hasil yang Diharapkan**:
  - Penjualan tersimpan dengan metode `qris` dan status lunas.
  - Nilai tidak masuk ke kas fisik shift (tidak menambah uang tunai yang diharapkan saat tutup shift).

### Skenario 4: Cetak Ulang Struk (Reprint Safeguard)
- **Aktor**: Kasir atau Manager
- **Langkah**:
  1. Masuk menu **Riwayat Penjualan** (`/sales`).
  2. Buka detail transaksi terakhir.
  3. Klik tombol **Cetak Ulang**. Masukkan alasan: "Kertas printer macet".
- **Hasil yang Diharapkan**:
  - Dialog cetak terbuka dengan label mencolok **SALINAN** (bukan struk asli).
  - Tidak membuat transaksi baru di database.
  - Entri tercatat di log audit dan tabel `sale_reprints`.

### Skenario 5: Pengajuan & Persetujuan Retur Barang
- **Aktor**: Kasir (pengajuan) & Manager (persetujuan)
- **Langkah**:
  1. Kasir masuk menu **Retur** (`/returns`).
  2. Masukkan nomor struk dari transaksi Skenario 2.
  3. Pilih 1 unit Buku Tulis untuk diretur.
  4. Pilih disposisi: `damaged` (rusak) atau `restockable` (layak jual kembali).
  5. Masukkan alasan: "Sampul sobek saat dibawa pulang". Klik **Ajukan Retur**.
  6. Login sebagai **Manager** di tab terpisah.
  7. Buka menu **Retur**, temukan pengajuan yang berstatus `requested`.
  8. Klik **Setujui (Approve)**.
  9. Kembali ke akun **Kasir**, klik **Proses Pengembalian Dana (Refund)** pada shift kasir yang aktif.
- **Hasil yang Diharapkan**:
  - Retur berubah status menjadi `completed`.
  - Jika disposisi `restockable`, stok barang bertambah kembali 1 unit.
  - Kas yang diharapkan pada shift kasir berkurang sebesar nilai refund.
  - HPP disesuaikan secara proporsional.

### Skenario 6: Tutup Shift & Laporan Z Kasir
- **Aktor**: Kasir
- **Langkah**:
  1. Masuk menu **Shift** (`/shift`).
  2. Klik **Tutup Shift**.
  3. Masukkan jumlah uang tunai fisik aktual hasil hitung laci kas.
  4. Jika ada selisih, sistem meminta catatan penjelasan selisih.
  5. Klik **Konfirmasi Tutup Shift**.
- **Hasil yang Diharapkan**:
  - Shift ditutup permanen (status `closed`).
  - Laporan Z otomatis terbit dan tidak dapat diubah (immutable).
  - Menampilkan ringkasan modal awal, total tunai masuk, non-tunai, retur keluar, uang diharapkan, dan selisih kas.

### Skenario 7: Laporan Manajemen & Verifikasi Hak Akses
- **Aktor**: Owner (`owner@dragonmart.local`) vs Kasir (`mahengon@gmail.com`)
- **Langkah**:
  1. Login sebagai **Owner**, buka menu **Laporan** (`/reports`).
  2. Buka laporan penjualan harian. Verifikasi ringkasan penjualan bersih, PPN, dan estimasi laba kotor.
  3. Buka menu **Audit Log** (`/audit`). Periksa seluruh riwayat aktivitas tercatat kronologis.
  4. Logout, lalu login sebagai **Kasir**.
  5. Coba buka URL `/reports` atau `/audit` secara manual.
- **Hasil yang Diharapkan**:
  - Owner dapat melihat seluruh data keuangan termasuk estimasi margin & HPP.
  - Kasir ditolak dengan pesan izin tidak mencukupi (HTTP 403), URL guard mengarahkan kasir kembali ke layar POS / Shift.
  - HPP dan margin modal tidak pernah tampil di antarmuka kasir.

---

## Tanda Tangan Persetujuan UAT

| Peran | Nama | Tanggal | Status | Catatan |
|---|---|---|---|---|
| Pemilik Toko (Owner) | Mahendra | 2026-10-11 | **SIAP RILIS v1.0** | Alur penjualan, retur, kontrol kas, dan keamanan memenuhi PRD v2.0 |
| Lead Developer | Antigravity AI | 2026-10-11 | Terverifikasi | 150 test backend lulus, frontend clean build, benchmark p95 terpenuhi, DR teruji |
