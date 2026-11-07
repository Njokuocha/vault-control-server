<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuthEvent extends Model
{
    protected $fillable = [
        'guard',
        'account_type',
        'account_id',
        'account_name',
        'ip_address',
        'duration_ms',
        'status',
        'is_brute_force',
        'result_detail',
        'user_agent',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'is_brute_force' => 'boolean',
            'occurred_at' => 'datetime',
        ];
    }

    public function bruteForceRecord(): HasOne
    {
        return $this->hasOne(BruteForceRecord::class);
    }
}
