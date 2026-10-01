<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'sku',
        'barcode',
        'name',
        'description',
        'category_id',
        'preferred_supplier_id',
        'unit',
        'selling_price',
        'average_cost',
        'stock',
        'min_stock',
        'is_active',
        'commercial_version',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'selling_price' => 'integer',
            'average_cost' => 'decimal:6',
            'stock' => 'integer',
            'min_stock' => 'integer',
            'is_active' => 'boolean',
            'commercial_version' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function preferredSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'preferred_supplier_id');
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function receivingItems(): HasMany
    {
        return $this->hasMany(ReceivingItem::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
