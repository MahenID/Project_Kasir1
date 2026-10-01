<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    protected $fillable = [
        'shift_id',
        'user_id',
        'type',
        'amount',
        'reason',
        'operation_key',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
