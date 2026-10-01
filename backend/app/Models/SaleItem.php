<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id',
        'product_id',
        'sku_snapshot',
        'barcode_snapshot',
        'name_snapshot',
        'unit_snapshot',
        'quantity',
        'unit_price',
        'cost_price_snapshot',
        'gross_amount',
        'discount_type',
        'discount_input',
        'item_discount_amount',
        'sale_discount_allocation',
        'net_amount',
        'tax_amount',
        'subtotal',
        'extended_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'cost_price_snapshot' => 'decimal:6',
            'gross_amount' => 'integer',
            'discount_input' => 'integer',
            'item_discount_amount' => 'integer',
            'sale_discount_allocation' => 'integer',
            'net_amount' => 'integer',
            'tax_amount' => 'integer',
            'subtotal' => 'integer',
            'extended_cost' => 'decimal:6',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }
}
