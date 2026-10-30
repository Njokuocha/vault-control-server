<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UploadTransfer extends Model
{
    protected $fillable = [
        'token',
        'user_id',
        'admin_id',
        'attachment_purpose',
        'record_type',
        'record_id',
        'draft_token',
        'target_id',
        'target_key',
        'committed_at',
        'attached_at',
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
            'admin_id' => 'integer',
            'record_id' => 'integer',
            'target_id' => 'integer',
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'chunk_count' => 'integer',
            'file_id' => 'integer',
            'expires_at' => 'datetime',
            'committed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function scopeOwnedBy(Builder $query, User|Admin $owner): Builder
    {
        return $query->where(
            $owner instanceof Admin ? 'admin_id' : 'user_id',
            $owner->getAuthIdentifier(),
        );
    }
}
