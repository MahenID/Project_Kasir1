"use client";

import { useCallback, useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import { categoryApi } from "@/lib/api/categories";
import type { Category } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

export default function CategoriesPage() {
  const { user } = useAuth();
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [search, setSearch] = useState("");
  const [activeOnly, setActiveOnly] = useState(false);

  // Modal State
  const [modalOpen, setModalOpen] = useState(false);
  const [editingCategory, setEditingCategory] = useState<Category | null>(null);
  const [formName, setFormName] = useState("");
  const [formActive, setFormActive] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Archive Confirm State
  const [archiveTarget, setArchiveTarget] = useState<Category | null>(null);
  const [archiving, setArchiving] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);

  const isSupervisor =
    user?.roles.includes("owner") || user?.roles.includes("manager");

  const loadCategories = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await categoryApi.list({
        search: search.trim() || undefined,
        active_only: activeOnly ? true : undefined,
      });
      setCategories(data);
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : "Gagal memuat daftar kategori. Periksa koneksi ke server."
      );
    } finally {
      setLoading(false);
    }
  }, [search, activeOnly]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadCategories();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadCategories]);

  const openCreateModal = () => {
    setEditingCategory(null);
    setFormName("");
    setFormActive(true);
    setFormError(null);
    setModalOpen(true);
  };

  const openEditModal = (cat: Category) => {
    setEditingCategory(cat);
    setFormName(cat.name);
    setFormActive(cat.is_active);
    setFormError(null);
    setModalOpen(true);
  };

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formName.trim()) {
      setFormError("Nama kategori wajib diisi.");
      return;
    }
    setSubmitting(true);
    setFormError(null);
    try {
      if (editingCategory) {
        await categoryApi.update(editingCategory.id, {
          name: formName.trim(),
          is_active: formActive,
        });
      } else {
        await categoryApi.create({
          name: formName.trim(),
          is_active: formActive,
        });
      }
      setModalOpen(false);
      await loadCategories();
    } catch (err) {
      setFormError(
        err instanceof ApiError
          ? err.message
          : "Gagal menyimpan kategori."
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
      await categoryApi.archive(archiveTarget.id);
      setArchiveTarget(null);
      await loadCategories();
    } catch (err) {
      setArchiveError(
        err instanceof ApiError
          ? err.message
          : "Gagal mengarsipkan kategori."
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
            Halaman manajemen kategori hanya dapat diakses oleh peran Pemilik (Owner) atau Manajer.
          </p>
        </div>
      </div>
    );
  }

  const totalCategories = categories.length;
  const activeCount = categories.filter((c) => c.is_active).length;
  const totalProducts = categories.reduce(
    (acc, c) => acc + (c.products_count ?? 0),
    0
  );

  return (
    <div className="flex h-full flex-col gap-4 overflow-y-auto p-4 md:p-6">
      {/* Header Halaman */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight">Kategori Produk</h1>
          <p className="text-xs text-muted-foreground">
            Pengelompokan datar untuk katalog barang dagangan toko (PRD FR-PROD-01)
          </p>
        </div>
        <Button onClick={openCreateModal} variant="primary">
          + Tambah Kategori
        </Button>
      </div>

      {/* Kartu Ringkasan Metrik */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-border bg-surface p-4">
          <p className="text-xs text-muted-foreground">Total Kategori</p>
          <p className="mt-1 text-2xl font-bold">{totalCategories}</p>
        </div>
        <div className="rounded-xl border border-border bg-surface p-4">
          <p className="text-xs text-muted-foreground">Kategori Aktif</p>
          <p className="mt-1 text-2xl font-bold text-success">{activeCount}</p>
        </div>
        <div className="rounded-xl border border-border bg-surface p-4">
          <p className="text-xs text-muted-foreground">Total Produk Terhubung</p>
          <p className="mt-1 text-2xl font-bold">{totalProducts}</p>
        </div>
      </div>

      {/* Filter & Pencarian */}
      <div className="flex flex-col gap-2 rounded-xl border border-border bg-surface p-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="w-full sm:w-72">
          <Input
            type="search"
            placeholder="Cari nama atau slug..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <div className="flex items-center gap-2">
          <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-muted-foreground">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => setActiveOnly(e.target.checked)}
              className="h-4 w-4 rounded border-border"
            />
            Hanya tampilkan kategori aktif
          </label>
        </div>
      </div>

      {/* Tabel Kategori */}
      <div className="flex-1 overflow-hidden rounded-xl border border-border bg-surface shadow-xs">
        {loading ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Memuat daftar kategori...
          </div>
        ) : error ? (
          <div className="p-8 text-center">
            <p className="text-sm text-danger">{error}</p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={loadCategories}
            >
              Coba Lagi
            </Button>
          </div>
        ) : categories.length === 0 ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada kategori yang ditemukan.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Nama Kategori</th>
                  <th className="px-4 py-3">Slug (Kode URL)</th>
                  <th className="px-4 py-3">Jumlah Produk</th>
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {categories.map((cat) => (
                  <tr key={cat.id} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3 font-medium text-foreground">
                      {cat.name}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-muted-foreground">
                      {cat.slug ?? "—"}
                    </td>
                    <td className="px-4 py-3">
                      <span className="font-semibold">{cat.products_count ?? 0}</span>{" "}
                      <span className="text-xs text-muted-foreground">produk</span>
                    </td>
                    <td className="px-4 py-3">
                      <Badge variant={cat.is_active ? "success" : "default"}>
                        {cat.is_active ? "Aktif" : "Diarsipkan"}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => openEditModal(cat)}
                        >
                          Ubah
                        </Button>
                        {cat.is_active && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-danger hover:bg-red-50 hover:text-danger"
                            onClick={() => {
                              setArchiveTarget(cat);
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
      </div>

      {/* Modal Tambah / Edit Kategori */}
      <Modal
        open={modalOpen}
        title={editingCategory ? "Ubah Kategori" : "Tambah Kategori Baru"}
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
              Nama Kategori <span className="text-danger">*</span>
            </label>
            <Input
              type="text"
              placeholder="Contoh: Alat Tulis Kantor, Sembako, Deterjen"
              value={formName}
              onChange={(e) => setFormName(e.target.value)}
              required
              autoFocus
            />
            <p className="mt-1 text-xs text-muted-foreground">
              Slug unik akan dibuat otomatis oleh sistem berdasarkan nama.
            </p>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="categoryActive"
              checked={formActive}
              onChange={(e) => setFormActive(e.target.checked)}
              className="h-4 w-4 rounded border-border"
            />
            <label htmlFor="categoryActive" className="text-xs font-medium cursor-pointer">
              Status Aktif (dapat digunakan untuk produk baru)
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
              {submitting ? "Menyimpan..." : "Simpan Kategori"}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Konfirmasi Arsip Kategori */}
      <Modal
        open={archiveTarget !== null}
        title="Konfirmasi Pengarsipan Kategori"
        onClose={() => setArchiveTarget(null)}
        maxWidthClass="max-w-md"
      >
        <div className="p-5">
          <p className="text-sm text-foreground">
            Yakin ingin mengarsipkan kategori{" "}
            <span className="font-bold">{archiveTarget?.name}</span>?
          </p>
          <p className="mt-2 text-xs text-muted-foreground">
            Sesuai aturan PRD (AC-36), kategori tidak dapat dinonaktifkan jika masih
            memiliki produk aktif yang terdaftar di dalamnya.
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
              {archiving ? "Mengarsipkan..." : "Ya, Arsipkan Kategori"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
