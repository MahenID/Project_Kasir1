<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnRequest extends Model
{
    protected $fillable = [
        'request_number',
        'sale_id',
        'requested_by',
        'status',
        'reason',
        'approved_by',
        'approved_at',
        'approval_hash',
        'approval_expires_at',
        'override_reason',
        'completed_by',
        'completed_at',
        'handling_shift_id',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'approval_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function handlingShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'handling_shift_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(Refund::class);
    }
}
