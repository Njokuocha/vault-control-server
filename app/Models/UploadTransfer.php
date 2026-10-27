<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadTransfer extends Model
{
    protected $fillable = [
        'token',
        'user_id',
        'original_name',
        'total_size',
        'chunk_size',
        'chunk_count',
        'status',
        'file_id',
        'error_message',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'chunk_count' => 'integer',
            'file_id' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
