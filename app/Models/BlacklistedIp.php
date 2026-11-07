<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlacklistedIp extends Model
{
    protected $fillable = [
        'ip_address',
        'failed_attempts',
        'triggered_at',
        'last_attempt_at',
        'reason',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }
}
