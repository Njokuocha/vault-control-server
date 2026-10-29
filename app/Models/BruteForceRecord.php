<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BruteForceRecord extends Model
{
    protected $fillable = [
        'auth_event_id',
        'account_type',
        'account_id',
        'account_name',
        'ip_address',
        'failed_attempts',
        'window_seconds',
        'user_agent',
        'detected_at',
    ];

    protected function casts(): array
    {
        return ['detected_at' => 'datetime'];
    }

    public function authEvent(): BelongsTo
    {
        return $this->belongsTo(AuthEvent::class);
    }
}
