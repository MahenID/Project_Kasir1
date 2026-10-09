<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleReprint extends Model
{
    protected $fillable = [
        'sale_id',
        'reprinted_by_user_id',
        'reason',
        'source_ip',
        'reprinted_at',
    ];

    protected function casts(): array
    {
        return [
            'reprinted_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function reprintedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reprinted_by_user_id');
    }
}
