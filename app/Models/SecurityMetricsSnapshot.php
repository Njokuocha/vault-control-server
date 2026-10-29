<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityMetricsSnapshot extends Model
{
    public $timestamps = false;

    protected $fillable = ['id', 'active_accounts', 'captured_at'];

    protected function casts(): array
    {
        return [
            'active_accounts' => 'integer',
            'captured_at' => 'datetime',
        ];
    }
}
