<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StocktakeItem extends Model
{
    protected $fillable = [
        'stocktake_id',
        'product_id',
        'snapshot_stock',
        'snapshot_average_cost',
        'counted_stock',
        'difference',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_stock' => 'integer',
            'snapshot_average_cost' => 'decimal:6',
            'counted_stock' => 'integer',
            'difference' => 'integer',
        ];
    }

    public function stocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
