<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryControl extends Model
{
    protected $table = 'inventory_control';

    protected $fillable = [
        'active_stocktake_id',
        'notes',
    ];

    public function activeStocktake(): BelongsTo
    {
        return $this->belongsTo(Stocktake::class, 'active_stocktake_id');
    }
}
