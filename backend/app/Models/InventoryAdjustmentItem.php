<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustmentItem extends Model
{
    protected $fillable = [
        'inventory_adjustment_id',
        'product_id',
        'quantity_delta',
        'current_average_cost',
        'new_average_cost',
        'before_stock',
        'after_stock',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'current_average_cost' => 'decimal:6',
            'new_average_cost' => 'decimal:6',
            'before_stock' => 'integer',
            'after_stock' => 'integer',
        ];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(InventoryAdjustment::class, 'inventory_adjustment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
