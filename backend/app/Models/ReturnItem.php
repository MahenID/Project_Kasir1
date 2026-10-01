<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnItem extends Model
{
    protected $fillable = [
        'return_request_id',
        'sale_item_id',
        'quantity',
        'disposition',
        'reason',
        'refund_net_amount',
        'refund_tax_amount',
        'refund_total_amount',
        'restored_cost_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'refund_net_amount' => 'integer',
            'refund_tax_amount' => 'integer',
            'refund_total_amount' => 'integer',
            'restored_cost_snapshot' => 'decimal:6',
        ];
    }

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
