<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receiving extends Model
{
    protected $fillable = [
        'receiving_number',
        'type',
        'supplier_id',
        'external_reference',
        'status',
        'received_at',
        'created_by',
        'posted_by',
        'correction_notes',
        'linked_receiving_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function linkedReceiving(): BelongsTo
    {
        return $this->belongsTo(Receiving::class, 'linked_receiving_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceivingItem::class);
    }
}
