<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutQuote extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'shift_id',
        'quote_hash',
        'payload_snapshot',
        'result_snapshot',
        'commercial_versions',
        'consumed_sale_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'result_snapshot' => 'array',
            'commercial_versions' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function consumedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'consumed_sale_id');
    }
}
