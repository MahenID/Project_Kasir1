<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Product master data (FR-PROD-02/03, FR-MEDIA-01) — Fase 4.A.
 *
 * Regular product CRUD MUST NOT touch stock or average_cost: those change
 * only through posted inventory documents (receiving, adjustment, stocktake).
 * New products start at zero stock/cost. Changing the selling price bumps
 * commercial_version so outstanding quotes go stale (QUOTE_STALE).
 *
 * Image uploads (FR-MEDIA-01 / AC-33): JPEG/PNG/WebP, max 5 MB, content
 * verified with getimagesizefromstring (not the client MIME), resized safely
 * with GD, stored under a generated filename under the public disk. Image
 * processing happens BEFORE any DB write; a DB failure after storage removes
 * the freshly written file, so a failed upload never corrupts the product.
 */
class ProductService
{
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const MAX_IMAGE_DIMENSION = 1200;

    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, ?UploadedFile $image = null): Product
    {
        $this->authorizeManage($user);

        $this->assertSkuAvailable($data['sku']);
        if (!empty($data['barcode'])) {
            $this->assertBarcodeAvailable($data['barcode']);
        }
        $this->assertCategoryActive((int) $data['category_id']);
        $this->assertSupplierActive($data['preferred_supplier_id'] ?? null);

        $imagePath = $this->processImage($image);

        try {
            return DB::transaction(function () use ($data, $imagePath) {
                $product = new Product();
                $product->sku = $data['sku'];
                $product->barcode = $data['barcode'] ?? null;
                $product->name = $data['name'];
                $product->description = $data['description'] ?? null;
                $product->category_id = (int) $data['category_id'];
                $product->preferred_supplier_id = $data['preferred_supplier_id'] ?? null;
                $product->unit = $data['unit'] ?? 'pcs';
                $product->selling_price = (int) $data['selling_price'];
                $product->min_stock = (int) ($data['min_stock'] ?? 0);
                $product->is_active = $data['is_active'] ?? true;
                $product->average_cost = '0';
                $product->stock = 0;
                $product->commercial_version = 1;
                $product->image_path = $imagePath;
                $product->save();

                return $product;
            });
        } catch (\Throwable $e) {
            if ($imagePath !== null) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, Product $product, array $data, ?UploadedFile $image = null): Product
    {
        $this->authorizeManage($user);

        if (array_key_exists('sku', $data) && $data['sku'] !== $product->sku) {
            $this->assertSkuAvailable($data['sku']);
            $product->sku = $data['sku'];
        }

        if (array_key_exists('barcode', $data)) {
            $barcode = $data['barcode'] ?: null;
            if ($barcode !== null && $barcode !== $product->barcode) {
                $this->assertBarcodeAvailable($barcode);
            }
            $product->barcode = $barcode;
        }

        if (array_key_exists('category_id', $data) && (int) $data['category_id'] !== $product->category_id) {
            $this->assertCategoryActive((int) $data['category_id']);
            $product->category_id = (int) $data['category_id'];
        }

        if (array_key_exists('preferred_supplier_id', $data)) {
            $supplierId = $data['preferred_supplier_id'] ?: null;
            if ($supplierId !== null && (int) $supplierId !== (int) $product->preferred_supplier_id) {
                $this->assertSupplierActive($supplierId);
            }
            $product->preferred_supplier_id = $supplierId;
        }

        foreach (['name', 'description', 'unit'] as $field) {
            if (array_key_exists($field, $data)) {
                $product->{$field} = $data[$field];
            }
        }

        if (array_key_exists('min_stock', $data)) {
            $product->min_stock = (int) $data['min_stock'];
        }

        if (array_key_exists('is_active', $data)) {
            $product->is_active = (bool) $data['is_active'];
        }

        if (array_key_exists('selling_price', $data)
            && (int) $data['selling_price'] !== (int) $product->selling_price
        ) {
            $product->selling_price = (int) $data['selling_price'];
            // Invalidate outstanding quotes priced against the old price.
            $product->commercial_version = $product->commercial_version + 1;
        }

        $newImagePath = $this->processImage($image);
        $replacedPath = $newImagePath !== null ? $product->image_path : null;

        try {
            DB::transaction(function () use ($product, $newImagePath) {
                if ($newImagePath !== null) {
                    $product->image_path = $newImagePath;
                }
                $product->save();
            });
        } catch (\Throwable $e) {
            if ($newImagePath !== null) {
                Storage::disk('public')->delete($newImagePath);
            }
            throw $e;
        }

        if ($replacedPath !== null && $replacedPath !== $product->image_path) {
            Storage::disk('public')->delete($replacedPath);
        }

        return $product;
    }

    public function archive(User $user, Product $product): Product
    {
        return $this->update($user, $product, ['is_active' => false]);
    }

    /**
     * Cashiers never receive cost or margin data (PRD authorization rule).
     */
    public function buildProductPayload(Product $product, bool $includeCosts): array
    {
        $payload = [
            'id' => $product->id,
            'sku' => $product->sku,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'description' => $product->description,
            'unit' => $product->unit,
            'selling_price' => (int) $product->selling_price,
            'stock' => (int) $product->stock,
            'min_stock' => (int) $product->min_stock,
            'is_active' => $product->is_active,
            'is_low_stock' => (int) $product->stock <= (int) $product->min_stock,
            'image_url' => $product->image_path !== null
                ? Storage::disk('public')->url($product->image_path)
                : null,
            'category' => $product->category ? [
                'id' => $product->category->id,
                'name' => $product->category->name,
                'slug' => $product->category->slug,
            ] : null,
            'preferred_supplier' => $product->preferredSupplier ? [
                'id' => $product->preferredSupplier->id,
                'code' => $product->preferredSupplier->code,
                'name' => $product->preferredSupplier->name,
            ] : null,
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];

        if ($includeCosts) {
            $payload['average_cost'] = (string) $product->average_cost;
            $payload['commercial_version'] = (int) $product->commercial_version;
        }

        return $payload;
    }

    /**
     * @throws DomainException
     */
    public function authorizeManage(User $user): void
    {
        if (!($user->can('manage-catalog') || $user->hasAnyRole(['owner', 'manager']))) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk mengelola produk.',
                'CATALOG_FORBIDDEN',
                403,
            );
        }
    }

    public function userCanViewCosts(User $user): bool
    {
        return $user->can('view-costs') || $user->hasAnyRole(['owner', 'manager']);
    }

    /**
     * Validate + resize + store the image BEFORE any DB write (AC-33).
     *
     * @return string|null disk-relative path
     *
     * @throws DomainException
     */
    protected function processImage(?UploadedFile $image): ?string
    {
        if ($image === null) {
            return null;
        }

        if ($image->getSize() > self::MAX_IMAGE_BYTES) {
            throw new DomainException(
                'Gambar produk maksimal 5 MB.',
                'IMAGE_TOO_LARGE',
                413,
                ['image' => 'Ukuran file melebihi 5 MB.'],
            );
        }

        $binary = file_get_contents($image->getRealPath());
        $info = $binary === false ? false : @getimagesizefromstring($binary);
        $mime = $info ? $info['mime'] : null;

        if ($mime === null || !in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new DomainException(
                'Gambar produk harus berformat JPEG, PNG, atau WebP dengan konten file yang valid.',
                'IMAGE_INVALID_CONTENT',
                422,
                ['image' => 'Konten file bukan gambar JPEG/PNG/WebP yang valid.'],
            );
        }

        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new DomainException(
                'Gambar produk tidak dapat diproses. Gunakan file lain.',
                'IMAGE_PROCESSING_FAILED',
                422,
                ['image' => 'Gagal memproses gambar.'],
            );
        }

        $canvas = null;

        try {
            $width = imagesx($source);
            $height = imagesy($source);
            $scale = min(1.0, self::MAX_IMAGE_DIMENSION / max($width, $height));
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));

            $canvas = imagecreatetruecolor($targetW, $targetH);

            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            }

            if (imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height) === false) {
                throw new DomainException(
                    'Gambar produk tidak dapat diproses. Gunakan file lain.',
                    'IMAGE_PROCESSING_FAILED',
                    422,
                    ['image' => 'Gagal memproses gambar.'],
                );
            }

            $extension = match ($mime) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            ob_start();
            $rendered = match ($mime) {
                'image/png' => imagepng($canvas, null, 6),
                'image/webp' => imagewebp($canvas, null, 82),
                default => imagejpeg($canvas, null, 82),
            };
            $bytes = ob_get_clean();

            if ($rendered === false || $bytes === false || $bytes === '') {
                throw new DomainException(
                    'Gambar produk tidak dapat disimpan. Coba lagi.',
                    'IMAGE_PROCESSING_FAILED',
                    500,
                    ['image' => 'Gagal menyimpan gambar.'],
                );
            }

            $path = 'products/' . Str::uuid()->toString() . '.' . $extension;
            Storage::disk('public')->put($path, $bytes);

            return $path;
        } finally {
            if ($canvas instanceof \GdImage) {
                imagedestroy($canvas);
            }
            imagedestroy($source);
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertSkuAvailable(string $sku): void
    {
        if (Product::where('sku', $sku)->exists()) {
            throw new DomainException(
                "SKU '{$sku}' sudah dipakai.",
                'PRODUCT_SKU_TAKEN',
                409,
                ['sku' => $sku],
            );
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertBarcodeAvailable(string $barcode): void
    {
        if (Product::where('barcode', $barcode)->exists()) {
            throw new DomainException(
                "Barcode '{$barcode}' sudah dipakai produk lain.",
                'PRODUCT_BARCODE_TAKEN',
                409,
                ['barcode' => $barcode],
            );
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertCategoryActive(int $categoryId): void
    {
        $category = Category::find($categoryId);
        if ($category === null || !$category->is_active) {
            throw new DomainException(
                'Kategori tidak ditemukan atau tidak aktif.',
                'CATEGORY_NOT_AVAILABLE',
                422,
                ['category_id' => $categoryId],
            );
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertSupplierActive(?int $supplierId): void
    {
        if ($supplierId === null) {
            return;
        }

        $supplier = Supplier::find($supplierId);
        if ($supplier === null || !$supplier->is_active) {
            throw new DomainException(
                'Supplier tidak ditemukan atau tidak aktif.',
                'SUPPLIER_NOT_AVAILABLE',
                422,
                ['preferred_supplier_id' => $supplierId],
            );
        }
    }
}
