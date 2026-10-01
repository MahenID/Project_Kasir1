<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperationKey extends Model
{
    protected $fillable = [
        'user_id',
        'operation',
        'key',
        'payload_hash',
        'status',
        'result_resource_type',
        'result_resource_id',
        'response_snapshot',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'response_snapshot' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
