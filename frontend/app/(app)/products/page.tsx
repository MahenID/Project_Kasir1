"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Image from "next/image";
import { useAuth } from "@/lib/auth-context";
import { productApi } from "@/lib/api/products";
import { categoryApi } from "@/lib/api/categories";
import { supplierApi } from "@/lib/api/suppliers";
import type { Category, ProductDetail, Supplier } from "@/lib/types";
import { ApiError } from "@/lib/api";
import { formatRupiah } from "@/lib/format";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Modal } from "@/components/ui/modal";
import { Badge } from "@/components/ui/badge";

export default function ProductsPage() {
  const { user } = useAuth();
  const [products, setProducts] = useState<ProductDetail[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Filter States
  const [search, setSearch] = useState("");
  const [selectedCategory, setSelectedCategory] = useState<string>("");
  const [activeOnly, setActiveOnly] = useState(false);
  const [lowStockOnly, setLowStockOnly] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [totalItems, setTotalItems] = useState(0);

  // Modal Create / Edit State
  const [modalOpen, setModalOpen] = useState(false);
  const [editingProduct, setEditingProduct] = useState<ProductDetail | null>(null);
  const [formSku, setFormSku] = useState("");
  const [formBarcode, setFormBarcode] = useState("");
  const [formName, setFormName] = useState("");
  const [formDescription, setFormDescription] = useState("");
  const [formCategoryId, setFormCategoryId] = useState<string>("");
  const [formSupplierId, setFormSupplierId] = useState<string>("");
  const [formUnit, setFormUnit] = useState("pcs");
  const [formSellingPrice, setFormSellingPrice] = useState("");
  const [formMinStock, setFormMinStock] = useState("0");
  const [formActive, setFormActive] = useState(true);
  const [selectedImageFile, setSelectedImageFile] = useState<File | null>(null);
  const [imagePreviewUrl, setImagePreviewUrl] = useState<string | null>(null);
  const [formSubmitting, setFormSubmitting] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  // Archive Confirm State
  const [archiveTarget, setArchiveTarget] = useState<ProductDetail | null>(null);
  const [archiving, setArchiving] = useState(false);
  const [archiveError, setArchiveError] = useState<string | null>(null);

  const isSupervisor =
    user?.roles.includes("owner") || user?.roles.includes("manager");
  const canViewCosts =
    user?.roles.includes("owner") ||
    user?.roles.includes("manager") ||
    user?.permissions.includes("view-costs");

  // Load Categories & Suppliers for Dropdowns
  useEffect(() => {
    if (!isSupervisor) return;
    categoryApi
      .list({ active_only: false })
      .then(setCategories)
      .catch(() => {});
    supplierApi
      .list({ active_only: true })
      .then(setSuppliers)
      .catch(() => {});
  }, [isSupervisor]);

  // Load Products
  const loadProducts = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await productApi.list({
        search: search.trim() || undefined,
        category_id: selectedCategory ? Number(selectedCategory) : undefined,
        active_only: activeOnly ? true : undefined,
        low_stock: lowStockOnly ? true : undefined,
        page: currentPage,
        per_page: 20,
      });
      setProducts(res.data);
      if (res.meta?.pagination) {
        setTotalPages(res.meta.pagination.last_page);
        setTotalItems(res.meta.pagination.total);
      }
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err.message
          : "Gagal memuat katalog produk. Periksa koneksi ke server."
      );
    } finally {
      setLoading(false);
    }
  }, [search, selectedCategory, activeOnly, lowStockOnly, currentPage]);

  useEffect(() => {
    const timer = setTimeout(() => {
      loadProducts();
    }, 0);
    return () => clearTimeout(timer);
  }, [loadProducts]);

  // Handle Image Selection
  const handleImageChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
      setFormError("Ukuran file gambar maksimal 5 MB.");
      return;
    }

    const validTypes = ["image/jpeg", "image/png", "image/webp"];
    if (!validTypes.includes(file.type)) {
      setFormError("Format gambar harus JPEG, PNG, atau WebP.");
      return;
    }

    setSelectedImageFile(file);
    setImagePreviewUrl(URL.createObjectURL(file));
    setFormError(null);
  };

  const removeSelectedImage = () => {
    setSelectedImageFile(null);
    setImagePreviewUrl(null);
    if (fileInputRef.current) {
      fileInputRef.current.value = "";
    }
  };

  const openCreateModal = () => {
    setEditingProduct(null);
    setFormSku("");
    setFormBarcode("");
    setFormName("");
    setFormDescription("");
    setFormCategoryId(categories[0]?.id ? String(categories[0].id) : "");
    setFormSupplierId("");
    setFormUnit("pcs");
    setFormSellingPrice("");
    setFormMinStock("0");
    setFormActive(true);
    setSelectedImageFile(null);
    setImagePreviewUrl(null);
    setFormError(null);
    setModalOpen(true);
  };

  const openEditModal = (prod: ProductDetail) => {
    setEditingProduct(prod);
    setFormSku(prod.sku);
    setFormBarcode(prod.barcode ?? "");
    setFormName(prod.name);
    setFormDescription(prod.description ?? "");
    setFormCategoryId(prod.category ? String(prod.category.id) : "");
    setFormSupplierId(
      prod.preferred_supplier ? String(prod.preferred_supplier.id) : ""
    );
    setFormUnit(prod.unit);
    setFormSellingPrice(String(prod.selling_price));
    setFormMinStock(String(prod.min_stock));
    setFormActive(prod.is_active);
    setSelectedImageFile(null);
    setImagePreviewUrl(prod.image_url);
    setFormError(null);
    setModalOpen(true);
  };

  const handleSaveProduct = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formName.trim() || !formSku.trim() || !formCategoryId) {
      setFormError("Nama, SKU, dan Kategori wajib diisi.");
      return;
    }

    const priceNum = parseInt(formSellingPrice, 10);
    if (isNaN(priceNum) || priceNum < 0) {
      setFormError("Harga jual harus berupa bilangan bulat positif.");
      return;
    }

    const minStockNum = parseInt(formMinStock, 10);
    if (isNaN(minStockNum) || minStockNum < 0) {
      setFormError("Batas stok minimum harus berupa bilangan bulat positif.");
      return;
    }

    setFormSubmitting(true);
    setFormError(null);

    try {
      const formData = new FormData();
      formData.append("sku", formSku.trim());
      if (formBarcode.trim()) formData.append("barcode", formBarcode.trim());
      formData.append("name", formName.trim());
      if (formDescription.trim())
        formData.append("description", formDescription.trim());
      formData.append("category_id", formCategoryId);
      if (formSupplierId)
        formData.append("preferred_supplier_id", formSupplierId);
      formData.append("unit", formUnit.trim() || "pcs");
      formData.append("selling_price", String(priceNum));
      formData.append("min_stock", String(minStockNum));
      formData.append("is_active", formActive ? "1" : "0");

      if (selectedImageFile) {
        formData.append("image", selectedImageFile);
      }

      if (editingProduct) {
        await productApi.update(editingProduct.id, formData);
      } else {
        await productApi.create(formData);
      }

      setModalOpen(false);
      await loadProducts();
    } catch (err) {
      setFormError(
        err instanceof ApiError ? err.message : "Gagal menyimpan data produk."
      );
    } finally {
      setFormSubmitting(false);
    }
  };

  const handleArchiveProduct = async () => {
    if (!archiveTarget) return;
    setArchiving(true);
    setArchiveError(null);
    try {
      await productApi.archive(archiveTarget.id);
      setArchiveTarget(null);
      await loadProducts();
    } catch (err) {
      setArchiveError(
        err instanceof ApiError ? err.message : "Gagal mengarsipkan produk."
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
            Halaman katalog produk hanya dapat diakses oleh peran Pemilik (Owner) atau Manajer.
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
          <h1 className="text-xl font-bold tracking-tight">Katalog Produk</h1>
          <p className="text-xs text-muted-foreground">
            Master data barang dagangan ritel, harga jual & batas stok (PRD FR-PROD-02)
          </p>
        </div>
        <Button onClick={openCreateModal} variant="primary">
          + Tambah Produk Baru
        </Button>
      </div>

      {/* Bar Filter & Pencarian */}
      <div className="flex flex-col gap-3 rounded-xl border border-border bg-surface p-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-1 flex-col gap-2 sm:flex-row sm:items-center">
          <div className="w-full sm:w-64">
            <Input
              type="search"
              placeholder="Cari SKU, barcode, nama..."
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setCurrentPage(1);
              }}
            />
          </div>

          <div className="w-full sm:w-48">
            <select
              aria-label="Pilih Kategori"
              value={selectedCategory}
              onChange={(e) => {
                setSelectedCategory(e.target.value);
                setCurrentPage(1);
              }}
              className="h-10 w-full rounded-lg border border-border bg-background px-3 text-xs focus:border-primary focus:outline-hidden"
            >
              <option value="">Semua Kategori</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-4 text-xs font-medium text-muted-foreground">
          <label className="flex cursor-pointer items-center gap-2">
            <input
              type="checkbox"
              checked={activeOnly}
              onChange={(e) => {
                setActiveOnly(e.target.checked);
                setCurrentPage(1);
              }}
              className="h-4 w-4 rounded border-border"
            />
            Hanya Aktif
          </label>

          <label className="flex cursor-pointer items-center gap-2">
            <input
              type="checkbox"
              checked={lowStockOnly}
              onChange={(e) => {
                setLowStockOnly(e.target.checked);
                setCurrentPage(1);
              }}
              className="h-4 w-4 rounded border-border text-danger"
            />
            <span className={lowStockOnly ? "font-bold text-danger" : ""}>
              Stok Rendah / Menipis (FR-PROD-04)
            </span>
          </label>
        </div>
      </div>

      {/* Tabel Produk */}
      <div className="flex flex-1 flex-col overflow-hidden rounded-xl border border-border bg-surface shadow-xs">
        {loading ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Memuat katalog produk...
          </div>
        ) : error ? (
          <div className="p-8 text-center">
            <p className="text-sm text-danger">{error}</p>
            <Button
              variant="outline"
              size="sm"
              className="mt-3"
              onClick={loadProducts}
            >
              Coba Lagi
            </Button>
          </div>
        ) : products.length === 0 ? (
          <div className="p-8 text-center text-sm text-muted-foreground">
            Tidak ada produk yang sesuai dengan kriteria filter.
          </div>
        ) : (
          <div className="flex-1 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-border bg-slate-50 text-xs font-semibold text-muted-foreground">
                <tr>
                  <th className="px-4 py-3">Produk</th>
                  <th className="px-4 py-3">SKU & Barcode</th>
                  <th className="px-4 py-3">Kategori</th>
                  <th className="px-4 py-3 text-right">Harga Jual</th>
                  <th className="px-4 py-3 text-center">Stok Fisik</th>
                  {canViewCosts && (
                    <th className="px-4 py-3 text-right">HPP Rata-rata</th>
                  )}
                  <th className="px-4 py-3">Status</th>
                  <th className="px-4 py-3 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border">
                {products.map((prod) => (
                  <tr key={prod.id} className="hover:bg-slate-50/50">
                    <td className="px-4 py-3">
                      <div className="flex items-center gap-3">
                        {prod.image_url ? (
                          <div className="relative h-10 w-10 shrink-0 overflow-hidden rounded-lg border border-border bg-slate-100">
                            <Image
                              src={prod.image_url}
                              alt={prod.name}
                              fill
                              unoptimized
                              className="object-cover"
                            />
                          </div>
                        ) : (
                          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-border bg-slate-100 font-bold text-slate-500">
                            {prod.name.charAt(0).toUpperCase()}
                          </div>
                        )}
                        <div className="min-w-0">
                          <p className="font-medium text-foreground truncate max-w-[220px]">
                            {prod.name}
                          </p>
                          <p className="text-xs text-muted-foreground">
                            Satuan: {prod.unit}
                          </p>
                        </div>
                      </div>
                    </td>

                    <td className="px-4 py-3 font-mono text-xs">
                      <div>{prod.sku}</div>
                      {prod.barcode ? (
                        <div className="text-muted-foreground">
                          {prod.barcode}
                        </div>
                      ) : (
                        <span className="text-slate-400">—</span>
                      )}
                    </td>

                    <td className="px-4 py-3 text-xs">
                      {prod.category?.name ?? "—"}
                    </td>

                    <td className="px-4 py-3 text-right font-semibold text-primary">
                      {formatRupiah(prod.selling_price)}
                    </td>

                    <td className="px-4 py-3 text-center">
                      <div className="inline-flex flex-col items-center">
                        <span
                          className={`font-bold ${
                            prod.is_low_stock
                              ? "text-danger"
                              : "text-foreground"
                          }`}
                        >
                          {prod.stock} {prod.unit}
                        </span>
                        {prod.min_stock > 0 && (
                          <span className="text-[10px] text-muted-foreground">
                            Min: {prod.min_stock}
                          </span>
                        )}
                        {prod.is_low_stock && (
                          <Badge variant="danger">Tipis</Badge>
                        )}
                      </div>
                    </td>

                    {canViewCosts && (
                      <td className="px-4 py-3 text-right text-xs font-mono text-muted-foreground">
                        {prod.average_cost
                          ? formatRupiah(Math.round(parseFloat(prod.average_cost)))
                          : "Rp 0"}
                      </td>
                    )}

                    <td className="px-4 py-3">
                      <Badge variant={prod.is_active ? "success" : "default"}>
                        {prod.is_active ? "Aktif" : "Diarsipkan"}
                      </Badge>
                    </td>

                    <td className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => openEditModal(prod)}
                        >
                          Ubah
                        </Button>
                        {prod.is_active && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="text-danger hover:bg-red-50 hover:text-danger"
                            onClick={() => {
                              setArchiveTarget(prod);
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
              Total <span className="font-semibold">{totalItems}</span> produk
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

      {/* Modal Tambah / Edit Produk */}
      <Modal
        open={modalOpen}
        title={editingProduct ? "Ubah Data Produk" : "Tambah Produk Baru"}
        onClose={() => setModalOpen(false)}
        maxWidthClass="max-w-2xl"
      >
        <form onSubmit={handleSaveProduct} className="flex flex-col gap-4 p-5">
          {formError && (
            <div className="rounded-lg bg-red-50 p-3 text-xs text-danger">
              {formError}
            </div>
          )}

          {/* Alert Integritas Stok PRD */}
          <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900 leading-relaxed">
            <span className="font-bold">Integritas Inventaris:</span> Stok fisik (saat ini {editingProduct ? editingProduct.stock : 0}) dan harga pokok rata-rata (HPP) tidak dapat diedit langsung di form ini. Perubahan stok harus melalui mutasi buku besar resmi (Penerimaan Barang / Receiving, Penyesuaian, atau Stocktake) sesuai aturan PRD FR-INV-01.
          </div>

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Nama Produk <span className="text-danger">*</span>
              </label>
              <Input
                type="text"
                placeholder="Contoh: Buku Tulis Sinar Dunia 38L"
                value={formName}
                onChange={(e) => setFormName(e.target.value)}
                required
                autoFocus
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                SKU (Stock Keeping Unit) <span className="text-danger">*</span>
              </label>
              <Input
                type="text"
                placeholder="Contoh: ATK-BK-001"
                value={formSku}
                onChange={(e) => setFormSku(e.target.value)}
                required
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Barcode (EAN / Code 128)
              </label>
              <Input
                type="text"
                placeholder="Contoh: 8992775312012"
                value={formBarcode}
                onChange={(e) => setFormBarcode(e.target.value)}
              />
              <p className="mt-1 text-[11px] text-muted-foreground">
                Nomor barcode fisik untuk discan kasir saat checkout.
              </p>
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Kategori <span className="text-danger">*</span>
              </label>
              <select
                aria-label="Pilih Kategori Produk"
                value={formCategoryId}
                onChange={(e) => setFormCategoryId(e.target.value)}
                required
                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-xs focus:border-primary focus:outline-hidden"
              >
                <option value="">-- Pilih Kategori --</option>
                {categories.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name} {!c.is_active ? "(Non-aktif)" : ""}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Harga Jual (Rp) <span className="text-danger">*</span>
              </label>
              <Input
                type="number"
                min="0"
                step="1"
                placeholder="Contoh: 5000"
                value={formSellingPrice}
                onChange={(e) => setFormSellingPrice(e.target.value)}
                required
              />
              <p className="mt-1 text-[11px] text-muted-foreground">
                Nilai integer rupiah utuh untuk kasir.
              </p>
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Satuan Unit Penjualan
              </label>
              <Input
                type="text"
                placeholder="pcs, botol, pack, rim..."
                value={formUnit}
                onChange={(e) => setFormUnit(e.target.value)}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Batas Minimum Stok (Peringatan Tipis)
              </label>
              <Input
                type="number"
                min="0"
                step="1"
                placeholder="Contoh: 10"
                value={formMinStock}
                onChange={(e) => setFormMinStock(e.target.value)}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs font-semibold text-foreground">
                Supplier Pilihan (Opsional)
              </label>
              <select
                aria-label="Pilih Supplier Pilihan"
                value={formSupplierId}
                onChange={(e) => setFormSupplierId(e.target.value)}
                className="h-10 w-full rounded-lg border border-border bg-background px-3 text-xs focus:border-primary focus:outline-hidden"
              >
                <option value="">-- Tanpa Supplier Pilihan --</option>
                {suppliers.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.code} - {s.name}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div>
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Deskripsi Produk
            </label>
            <textarea
              rows={2}
              placeholder="Catatan spesifikasi atau keterangan produk..."
              value={formDescription}
              onChange={(e) => setFormDescription(e.target.value)}
              className="w-full rounded-lg border border-border bg-background p-2.5 text-xs focus:border-primary focus:outline-hidden"
            />
          </div>

          {/* Upload Foto Produk (FR-MEDIA-01) */}
          <div className="rounded-lg border border-dashed border-border p-3">
            <label className="mb-1 block text-xs font-semibold text-foreground">
              Foto Produk (JPEG, PNG, WebP — Maks 5 MB)
            </label>
            <div className="mt-2 flex items-center gap-4">
              {imagePreviewUrl ? (
                <div className="relative h-16 w-16 shrink-0 overflow-hidden rounded-lg border border-border">
                  <Image
                    src={imagePreviewUrl}
                    alt="Preview"
                    fill
                    unoptimized
                    className="object-cover"
                  />
                </div>
              ) : (
                <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg border border-border bg-slate-100 text-xs text-muted-foreground">
                  Tanpa Foto
                </div>
              )}
              <div className="flex flex-col gap-1.5">
                <input
                  ref={fileInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={handleImageChange}
                  className="text-xs text-muted-foreground file:mr-2 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-xs file:font-semibold hover:file:bg-slate-200"
                />
                {imagePreviewUrl && (
                  <button
                    type="button"
                    onClick={removeSelectedImage}
                    className="text-left text-[11px] text-danger hover:underline"
                  >
                    Hapus pilihan gambar
                  </button>
                )}
              </div>
            </div>
          </div>

          <div className="flex items-center gap-2">
            <input
              type="checkbox"
              id="productActive"
              checked={formActive}
              onChange={(e) => setFormActive(e.target.checked)}
              className="h-4 w-4 rounded border-border"
            />
            <label htmlFor="productActive" className="text-xs font-medium cursor-pointer">
              Produk Aktif (tersedia untuk dicari dan dijual oleh kasir)
            </label>
          </div>

          <div className="mt-2 flex justify-end gap-2 border-t border-border pt-4">
            <Button
              type="button"
              variant="outline"
              onClick={() => setModalOpen(false)}
              disabled={formSubmitting}
            >
              Batal
            </Button>
            <Button type="submit" variant="primary" disabled={formSubmitting}>
              {formSubmitting ? "Menyimpan..." : "Simpan Produk"}
            </Button>
          </div>
        </form>
      </Modal>

      {/* Modal Konfirmasi Arsip Produk */}
      <Modal
        open={archiveTarget !== null}
        title="Konfirmasi Pengarsipan Produk"
        onClose={() => setArchiveTarget(null)}
        maxWidthClass="max-w-md"
      >
        <div className="p-5">
          <p className="text-sm text-foreground">
            Yakin ingin mengarsipkan produk{" "}
            <span className="font-bold">{archiveTarget?.name}</span> (SKU:{" "}
            {archiveTarget?.sku})?
          </p>
          <p className="mt-2 text-xs text-muted-foreground">
            Produk yang diarsipkan tidak akan muncul di layar pencarian kasir,
            namun riwayat penjualan masa lalunya tetap tersimpan utuh di sistem.
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
              onClick={handleArchiveProduct}
              disabled={archiving}
            >
              {archiving ? "Mengarsipkan..." : "Ya, Arsipkan Produk"}
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
