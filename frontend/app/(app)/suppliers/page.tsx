"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { supplierApi } from "@/lib/api/suppliers";
import type { Supplier } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

export default function SuppliersPage() {
  const { user } = useAuth();
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filter States
  const [search, setSearch] = useState("");
  const [activeOnly, setActiveOnly] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);

  // Modal Create / Edit State
  const [modalOpen, setModalOpen] = useState(false);
  const [editingSupplier, setEditingSupplier] = useState<Supplier | null>(null);
  const [formCode, setFormCode] = useState("");
  const [formName, setFormName] = useState("");
  const [formContact, setFormContact] = useState("");
  const [formPhone, setFormPhone] = useState("");
  const [formEmail, setFormEmail] = useState("");
  const [formAddress, setFormAddress] = useState("");
  const [formActive, setFormActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Archive Confirm State
  const [archiveTarget, setArchiveTarget] = useState<Supplier | null>(null);
  const [archiving, setArchiving] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);

  const isSupervisor =
    user?.roles.includes("owner") || user?.roles.includes("manager");

  const loadSuppliers = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await supplierApi.list({
        search: search.trim() || undefined,
        active_only: activeOnly ? true : undefined,
        page: currentPage,
        per_page: 20,
      });
      setSuppliers(res.data);
      if (res.meta?.pagination) {
        setTotalPages(res.meta.pagination.last_page);
        setTotalItems(res.meta.pagination.total);
      }
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : "Gagal memuat data supplier. Periksa koneksi ke server."
      );
    } finally {
      setLoading(false);
    }
  }, [search, activeOnly, currentPage]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadSuppliers();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadSuppliers]);

  const openCreateModal = () => {
    setEditingSupplier(null);
    setFormCode("");
    setFormName("");
    setFormContact("");
    setFormPhone("");
    setFormEmail("");
    setFormAddress("");
    setFormActive(true);
    setFormError(null);
    setModalOpen(true);
  };

  const openEditModal = (s: Supplier) => {
    setEditingSupplier(s);
    setFormCode(s.code);
    setFormName(s.name);
    setFormContact(s.contact_person ?? "");
    setFormPhone(s.phone ?? "");
    setFormEmail(s.email ?? "");
    setFormAddress(s.address ?? "");
    setFormActive(s.is_active);
    setFormError(null);
    setModalOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formCode.trim() || !formName.trim()) {
      setFormError("Kode dan Nama supplier wajib diisi.");
      return;
    }

    setSubmitting(true);
    setFormError(null);

    try {
      const payload = {
        code: formCode.trim().toUpperCase(),
        name: formName.trim(),
        contact_person: formContact.trim() || null,
        phone: formPhone.trim() || null,
        email: formEmail.trim() || null,
        address: formAddress.trim() || null,
        is_active: formActive,
      };

      if (editingSupplier) {
        await supplierApi.update(editingSupplier.id, payload);
      } else {
        await supplierApi.create(payload);
      }

      setModalOpen(false);
      await loadSuppliers();
    } catch (err) {
      setFormError(
        err instanceof ApiError ? err.message : "Gagal menyimpan data supplier."
      );
    } finally {
      setSubmitting(false);
    }
  };

  const handleArchive = async () => {
    if (!archiveTarget) return;
    setArchiving(true);
    setArchiveError(null);
    try {
      await supplierApi.archive(archiveTarget.id);
      setArchiveTarget(null);
      await loadSuppliers();
    } catch (err) {
      setArchiveError(
        err instanceof ApiError
          ? err.message
          : "Gagal mengarsipkan supplier."
      );
    } finally {
      setArchiving(false);
    }
  };

  if (!isSupervisor) {
    return (
      <div className="flex h-full items-center justify-center p-6">
        <div className="max-w-md rounded-xl border border-border bg-surface p-6 text-center">
          <h2 className="text-lg font-semibold text-danger">Akses Terbatas</h2>
          <p className="mt-2 text-sm text-muted-foreground">
            Halaman manajemen pemasok (supplier) hanya dapat diakses oleh peran Pemilik (Owner) atau Manajer.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto p-4 md:p-6">
      {/* Header Halaman */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Pemasok (Supplier)</h1>
          <p className="text-xs text-muted-foreground">
            Mitra pengadaan barang dagangan dan penerimaan stok (PRD FR-SUPP-01)
          </p>
        </div>
        <Button onClick={openCreateModal} variant="primary">
          + Tambah Supplier Baru
        </Button>
      </div>

      {/* Filter & Pencarian */}
      <div className="flex flex-col gap-2 rounded-xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="w-full sm:w-72">
          <Input
            type="search"
            placeholder="Cari kode, nama supplier..."
            value={search}
            onChange={(e) => {
              setSearch(e.target.value);
              setCurrentPage(1);
            }}
          />
        </div>
        <div className="flex items-center gap-2">
          <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-muted-foreground">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => {
                setActiveOnly(e.target.checked);
                setCurrentPage(1);
              }}
              className="h-4 w-4 rounded border-border"
            />
            Hanya tampilkan supplier aktif
          </label>
        </div>
      </div>

      {/* Tabel Supplier */}
      <div className="flex flex-1 flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-xs">
        {loading ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Memuat daftar supplier...
          </div>
        ) : error ? (
          <div className="p-8 text-center">
            <p className="text-sm text-danger">{error}</p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={loadSuppliers}
            >
              Coba Lagi
            </Button>
          </div>
        ) : suppliers.length === 0 ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada data supplier yang ditemukan.
          </div>
        ) : (
          <div className="flex-1 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Kode</th>
                  <th className="px-4 py-3">Nama Supplier</th>
                  <th className="px-4 py-3">Kontak / PIC</th>
                  <th className="px-4 py-3">Telepon & Email</th>
                  <th className="px-4 py-3">Alamat</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {suppliers.map((s) => (
                  <tr key={s.id} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-mono text-xs font-bold text-primary">
                      {s.code}
                    </td>
                    <td className="px-4 py-3 font-medium text-foreground">
                      {s.name}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground">
                      {s.contact_person ?? "—"}
                    </td>
                    <td className="px-4 py-3 text-xs">
                      <div>{s.phone ?? "—"}</div>
                      {s.email && (
                        <div className="text-muted-foreground font-mono">
                          {s.email}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-3 text-xs text-muted-foreground max-w-[200px] truncate">
                      {s.address ?? "—"}
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={s.is_active ? "success" : "default"}>
                        {s.is_active ? "Aktif" : "Diarsipkan"}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => openEditModal(s)}
                        >
                          Ubah
                        </Button>
                        {s.is_active && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-danger hover:bg-red-50 hover:text-danger"
                            onClick={() => {
                              setArchiveTarget(s);
                              setArchiveError(null);
                            }}
                          >
                            Arsipkan
                          </Button>
                        )}
                      </div>
                    </td>
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
              Total <span className="font-semibold">{totalItems}</span> supplier
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

      {/* Modal Tambah / Edit Supplier */}
      <Modal
        open={modalOpen}
        title={editingSupplier ? "Ubah Data Supplier" : "Tambah Supplier Baru"}
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
              Kode Supplier <span className="text-danger">*</span>
            </label>
            <Input
              type="text"
              placeholder="Contoh: SUP001, INDOFOOD"
              value={formCode}
              onChange={(e) => setFormCode(e.target.value)}
              required
              autoFocus
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Nama Supplier / Perusahaan <span className="text-danger">*</span>
            </label>
            <Input
              type="text"
              placeholder="Contoh: PT Sumber Alfaria Makmur"
              value={formName}
              onChange={(e) => setFormName(e.target.value)}
              required
            />
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Nama Kontak / PIC
            </label>
            <Input
              type="text"
              placeholder="Contoh: Budi Santoso"
              value={formContact}
              onChange={(e) => setFormContact(e.target.value)}
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                No. Telepon / WhatsApp
              </label>
              <Input
                type="text"
                placeholder="08123456789"
                value={formPhone}
                onChange={(e) => setFormPhone(e.target.value)}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Email
              </label>
              <Input
                type="email"
                placeholder="sales@supplier.com"
                value={formEmail}
                onChange={(e) => setFormEmail(e.target.value)}
              />
            </div>
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Alamat Lengkap
            </label>
            <textarea
              rows={2}
              placeholder="Alamat kantor atau gudang supplier..."
              value={formAddress}
              onChange={(e) => setFormAddress(e.target.value)}
              className="w-full rounded-lg border border-border bg-background p-2.5 text-xs focus:border-primary focus:outline-hidden"
            />
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="supplierActive"
              checked={formActive}
              onChange={(e) => setFormActive(e.target.checked)}
              className="h-4 w-4 rounded border-border"
            />
            <label htmlFor="supplierActive" className="text-xs font-medium cursor-pointer">
              Supplier Aktif (dapat dipilih untuk penerimaan barang baru)
            </label>
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
              {submitting ? "Menyimpan..." : "Simpan Supplier"}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Konfirmasi Arsip Supplier */}
      <Modal
        open={archiveTarget !== null}
        title="Konfirmasi Pengarsipan Supplier"
        onClose={() => setArchiveTarget(null)}
        maxWidthClass="max-w-md"
      >
        <div className="p-5">
          <p className="text-sm text-foreground">
            Yakin ingin mengarsipkan supplier{" "}
            <span className="font-bold">{archiveTarget?.name}</span> ({archiveTarget?.code})?
          </p>
          <p className="mt-2 text-xs text-muted-foreground">
            Supplier yang diarsipkan tidak dapat dipilih untuk dokumen penerimaan
            barang baru, namun seluruh histori pembelian masa lalu tetap tercatat
            di sistem sesuai PRD AC-38.
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
              {archiving ? "Mengarsipkan..." : "Ya, Arsipkan Supplier"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
