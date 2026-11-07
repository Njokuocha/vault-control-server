<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoUpload extends Model
{
    protected $fillable = [
        'token',
        'original_name',
        'total_size',
        'chunk_size',
        'chunk_count',
        'status',
        'file_id',
        'error_message',
        'expires_at',
        'attached_at',
    ];

    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'chunk_count' => 'integer',
            'file_id' => 'integer',
            'expires_at' => 'datetime',
            'attached_at' => 'datetime',
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
}
