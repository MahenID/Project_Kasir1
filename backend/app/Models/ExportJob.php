<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'user_id',
        'report_type',
        'filters_snapshot',
        'status',
        'file_path',
        'row_count',
        'failure_code',
        'error_message',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters_snapshot' => 'array',
            'row_count' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
