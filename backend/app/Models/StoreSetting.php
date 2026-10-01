<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StoreSetting extends Model
{
    protected $fillable = [
        'store_name',
        'address',
        'phone',
        'email',
        'receipt_footer',
        'timezone',
        'tax_enabled',
        'tax_rate_percent',
        'max_cashier_discount_percent',
        'return_window_days',
        'settings_version',
        'logo_path',
    ];

    protected function casts(): array
    {
        return [
            'tax_enabled' => 'boolean',
            'tax_rate_percent' => 'decimal:2',
            'max_cashier_discount_percent' => 'decimal:2',
            'return_window_days' => 'integer',
            'settings_version' => 'integer',
        ];
    }
}
