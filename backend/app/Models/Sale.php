<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    protected $fillable = [
        'receipt_number',
        'user_id',
        'shift_id',
        'terminal_id',
        'customer_id',
        'customer_snapshot',
        'cashier_name_snapshot',
        'terminal_code_snapshot',
        'store_snapshot',
        'subtotal',
        'item_discount_total',
        'sale_discount_type',
        'sale_discount_input',
        'sale_discount_amount',
        'net_total',
        'tax_rate_percent_snapshot',
        'tax_amount',
        'grand_total',
        'total_cost',
        'status',
        'return_status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_snapshot' => 'array',
            'store_snapshot' => 'array',
            'subtotal' => 'integer',
            'item_discount_total' => 'integer',
            'sale_discount_input' => 'integer',
            'sale_discount_amount' => 'integer',
            'net_total' => 'integer',
            'tax_rate_percent_snapshot' => 'decimal:2',
            'tax_amount' => 'integer',
            'grand_total' => 'integer',
            'total_cost' => 'decimal:6',
            'completed_at' => 'datetime',
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

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function reprints(): HasMany
    {
        return $this->hasMany(SaleReprint::class);
    }
}
