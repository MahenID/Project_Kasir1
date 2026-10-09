<?php

namespace App\Services;

use App\Exceptions\DomainException;
use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Category master data (FR-PROD-01) — Fase 4.A.
 *
 * Flat categories with unique slug. Archival (is_active=false) is blocked
 * while active products still reference the category, so cashiers never
 * lose the category of a sellable product (AC-36).
 */
class CategoryService
{
    /**
     * @param  array{name: string, is_active?: bool}  $data
     */
    public function create(User $user, array $data): Category
    {
        $this->authorizeManage($user);

        $category = new Category();
        $category->name = $data['name'];
        $category->slug = $this->uniqueSlug($data['name']);
        $category->is_active = $data['is_active'] ?? true;
        $category->save();

        return $category;
    }

    /**
     * @param  array{name?: string, is_active?: bool}  $data
     */
    public function update(User $user, Category $category, array $data): Category
    {
        $this->authorizeManage($user);

        if (array_key_exists('name', $data) && $data['name'] !== $category->name) {
            $category->name = $data['name'];
            $category->slug = $this->uniqueSlug($data['name'], $category);
        }

        if (array_key_exists('is_active', $data)) {
            $this->assertDeactivatable($category, (bool) $data['is_active']);
            $category->is_active = (bool) $data['is_active'];
        }

        $category->save();

        return $category;
    }

    /**
     * Archive (soft deactivate). Blocked while active products remain (AC-36).
     */
    public function archive(User $user, Category $category): Category
    {
        return $this->update($user, $category, ['is_active' => false]);
    }

    public function buildCategoryPayload(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'is_active' => $category->is_active,
            'products_count' => $category->relationLoaded('products')
                ? $category->products->count()
                : $category->products()->count(),
            'created_at' => $category->created_at?->toIso8601String(),
            'updated_at' => $category->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @throws DomainException
     */
    public function authorizeManage(User $user): void
    {
        if (!($user->can('manage-catalog') || $user->hasAnyRole(['owner', 'manager']))) {
            throw new DomainException(
                'Anda tidak memiliki izin untuk mengelola kategori.',
                'CATALOG_FORBIDDEN',
                403,
            );
        }
    }

    /**
     * @throws DomainException
     */
    protected function assertDeactivatable(Category $category, bool $newActive): void
    {
        if ($newActive) {
            return;
        }

        $activeProducts = $category->products()->where('is_active', true)->count();
        if ($activeProducts > 0) {
            throw new DomainException(
                "Kategori masih dipakai oleh {$activeProducts} produk aktif. Nonaktifkan atau pindahkan produk terlebih dahulu.",
                'CATEGORY_IN_USE',
                409,
                ['category_id' => $category->id, 'active_products' => $activeProducts],
            );
        }
    }

    protected function uniqueSlug(string $name, ?Category $exclude = null): string
    {
        $base = Str::slug($name) ?: 'kategori';
        $slug = $base;
        $suffix = 2;

        while (Category::where('slug', $slug)
            ->when($exclude, fn ($q) => $q->whereKeyNot($exclude->getKey()))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
