"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { customerApi } from "@/lib/api/customers";
import type { Customer } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

export default function CustomersPage() {
  const { user } = useAuth();
  const [customers, setCustomers] = useState<Customer[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filter & Pagination States
  const [search, setSearch] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);

  // Modal Create / Edit State
  const [modalOpen, setModalOpen] = useState(false);
  const [editingCustomer, setEditingCustomer] = useState<Customer | null>(null);
  const [formCode, setFormCode] = useState("");
  const [formName, setFormName] = useState("");
  const [formPhone, setFormPhone] = useState("");
  const [formEmail, setFormEmail] = useState("");
  const [formAddress, setFormAddress] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Archive Confirm State
  const [archiveTarget, setArchiveTarget] = useState<Customer | null>(null);
  const [archiving, setArchiving] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);

  const isSupervisor =
    user?.roles.includes("owner") || user?.roles.includes("manager");

  const loadCustomers = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await customerApi.list({
        search: search.trim() || undefined,
        page: currentPage,
        per_page: 20,
      });
      setCustomers(res.data);
      if (res.meta?.pagination) {
        setTotalPages(res.meta.pagination.last_page);
        setTotalItems(res.meta.pagination.total);
      }
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : "Gagal memuat data pelanggan. Periksa koneksi ke server."
      );
    } finally {
      setLoading(false);
    }
  }, [search, currentPage]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadCustomers();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadCustomers]);

  const openCreateModal = () => {
    setEditingCustomer(null);
    setFormCode("");
    setFormName("");
    setFormPhone("");
    setFormEmail("");
    setFormAddress("");
    setFormError(null);
    setModalOpen(true);
  };

  const openEditModal = (c: Customer) => {
    if (!isSupervisor) return;
    setEditingCustomer(c);
    setFormCode(c.code);
    setFormName(c.name);
    setFormPhone(c.phone ?? "");
    setFormEmail(c.email ?? "");
    setFormAddress(c.address ?? "");
    setFormError(null);
    setModalOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formName.trim()) {
      setFormError("Nama pelanggan wajib diisi.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const payload = {
        code: formCode.trim() || undefined,
        name: formName.trim(),
        phone: formPhone.trim() || null,
        email: formEmail.trim() || null,
        address: formAddress.trim() || null,
      };

      if (editingCustomer) {
        await customerApi.update(editingCustomer.id, payload);
      } else {
        await customerApi.create(payload);
      }

      setModalOpen(false);
      await loadCustomers();
    } catch (err) {
      setFormError(
        err instanceof ApiError ? err.message : "Gagal menyimpan data pelanggan."
      );
    } finally {
      setSubmitting(false);
    }
  };

  const handleArchive = async () => {
    if (!archiveTarget || !isSupervisor) return;
    setArchiving(true);
    setArchiveError(null);
    try {
      await customerApi.archive(archiveTarget.id);
      setArchiveTarget(null);
      await loadCustomers();
    } catch (err) {
      setArchiveError(
        err instanceof ApiError
          ? err.message
          : "Gagal mengarsipkan pelanggan."
      );
    } finally {
      setArchiving(false);
    }
  };

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto p-4 md:p-6">
      {/* Header Halaman */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Pelanggan Toko</h1>
          <p className="text-xs text-muted-foreground">
            Pencarian dan pendaftaran pelanggan tanpa perhitungan poin reward (PRD FR-CUST-01)
          </p>
        </div>
        <Button onClick={openCreateModal} variant="primary">
          + Tambah Pelanggan
        </Button>
      </div>

      {/* Filter & Pencarian */}
      <div className="flex flex-col gap-2 rounded-xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="w-full sm:w-80">
          <Input
            type="search"
            placeholder="Cari kode, nama, atau no. HP..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setCurrentPage(1);
            }}
          />
        </div>
        <div className="text-xs text-muted-foreground">
          Total Pelanggan Aktif: <span className="font-bold text-foreground">{totalItems}</span>
        </div>
      </div>

      {/* Tabel Pelanggan */}
      <div className="flex flex-1 flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-xs">
        {loading ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Memuat daftar pelanggan...
          </div>
        ) : error ? (
          <div className="p-8 text-center">
            <p className="text-sm text-danger">{error}</p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={loadCustomers}
            >
              Coba Lagi
            </Button>
          </div>
        ) : customers.length === 0 ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada data pelanggan yang ditemukan.
          </div>
        ) : (
          <div className="flex-1 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Kode Pelanggan</th>
                  <th className="px-4 py-3">Nama</th>
                  <th className="px-4 py-3">No. HP / Telepon</th>
                  <th className="px-4 py-3">Email</th>
                  <th className="px-4 py-3">Alamat</th>
                  <th className="px-4 py-3">Status</th>
                  {isSupervisor && (
                    <th className="px-4 py-3 text-right">Aksi</th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {customers.map((c) => (
                  <tr key={c.id} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-mono text-xs font-semibold text-primary">
                      {c.code}
                    </td>
                    <td className="px-4 py-3 font-medium text-foreground">
                      {c.name}
                    </td>
                    <td className="px-4 py-3 text-xs font-mono">
                      {c.phone ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground">
                      {c.email ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground max-w-[200px] truncate">
                      {c.address ?? "—"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={c.is_active ? "success" : "default"}>
                        {c.is_active ? "Aktif" : "Diarsipkan"}
                      </Badge>
                    </td>
                    {isSupervisor && (
                      <td className="px-4 py-3 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => openEditModal(c)}
                          >
                            Ubah
                          </Button>
                          {c.is_active && (
                            <Button
                              variant="ghost"
                              size="sm"
                              className="text-danger hover:bg-red-50 hover:text-danger"
                              onClick={() => {
                                setArchiveTarget(c);
                                setArchiveError(null);
                              }}
                            >
                              Arsipkan
                            </Button>
                          )}
                        </div>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Footer Pagination */}
        {!loading && totalPages > 1 && (
          <div className="flex items-center justify-between border-t border-border px-4 py-3 text-xs text-muted-foreground">
            <div>
              Total <span className="font-semibold">{totalItems}</span> pelanggan
              (Halaman {currentPage} dari {totalPages})
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage <= 1}
                onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
              >
                Sebelumnya
              </Button>
              <Button
                variant="outline"
                size="sm"
                disabled={currentPage >= totalPages}
                onClick={() =>
                  setCurrentPage((p) => Math.min(totalPages, p + 1))
                }
              >
                Selanjutnya
              </Button>
            </div>
          </div>
        )}
      </div>

      {/* Modal Tambah / Edit Pelanggan */}
      <Modal
        open={modalOpen}
        title={editingCustomer ? "Ubah Data Pelanggan" : "Tambah Pelanggan Baru"}
        onClose={() => setModalOpen(false)}
        maxWidthClass="max-w-md"
      >
        <form onSubmit={handleSave} className="flex flex-col gap-4 p-5">
          {formError && (
            <div className="rounded-lg bg-red-50 p-3 text-xs text-danger">
              {formError}
            </div>
          )}

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Nama Lengkap <span className="text-danger">*</span>
            </label>
            <Input
              type="text"
              placeholder="Contoh: Siti Nurhaliza"
              value={formName}
              onChange={(e) => setFormName(e.target.value)}
              required
              autoFocus
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Kode Pelanggan (Opsional)
            </label>
            <Input
              type="text"
              placeholder="Kosongkan jika ingin dibuat otomatis (CUST-XXXX)"
              value={formCode}
              onChange={(e) => setFormCode(e.target.value)}
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              No. Handphone / WhatsApp
            </label>
            <Input
              type="text"
              placeholder="Contoh: 081234567890"
              value={formPhone}
              onChange={(e) => setFormPhone(e.target.value)}
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Email (Opsional)
            </label>
            <Input
              type="email"
              placeholder="siti@example.com"
              value={formEmail}
              onChange={(e) => setFormEmail(e.target.value)}
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Alamat
            </label>
            <textarea
              rows={2}
              placeholder="Alamat domisili atau pengiriman..."
              value={formAddress}
              onChange={(e) => setFormAddress(e.target.value)}
              className="w-full rounded-lg border border-border bg-background p-2.5 text-xs focus:border-primary focus:outline-hidden"
            />
          </div>

          <div className="mt-2 flex justify-end gap-2 border-t border-border pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setModalOpen(false)}
              disabled={submitting}
            >
              Batal
            </Button>
            <Button type="submit" variant="primary" disabled={submitting}>
              {submitting ? "Menyimpan..." : "Simpan Pelanggan"}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Konfirmasi Arsip Pelanggan (Khusus Supervisor) */}
      <Modal
        open={archiveTarget !== null}
        title="Konfirmasi Pengarsipan Pelanggan"
        onClose={() => setArchiveTarget(null)}
        maxWidthClass="max-w-md"
      >
        <div className="p-5">
          <p className="text-sm text-foreground">
            Yakin ingin mengarsipkan pelanggan{" "}
            <span className="font-bold">{archiveTarget?.name}</span> ({archiveTarget?.code})?
          </p>
          <p className="mt-2 text-xs text-muted-foreground">
            Pelanggan yang diarsipkan tidak akan muncul di pencarian checkout kasir,
            namun riwayat transaksi terdahulunya tetap tersimpan utuh di sistem.
          </p>

          {archiveError && (
            <div className="mt-3 rounded-lg bg-red-50 p-3 text-xs text-danger">
              {archiveError}
            </div>
          )}

          <div className="mt-5 flex justify-end gap-2 border-t border-border pt-4">
            <Button
              variant="outline"
              onClick={() => setArchiveTarget(null)}
              disabled={archiving}
            >
              Batal
            </Button>
            <Button
              variant="danger"
              onClick={handleArchive}
              disabled={archiving}
            >
              {archiving ? "Mengarsipkan..." : "Ya, Arsipkan Pelanggan"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
