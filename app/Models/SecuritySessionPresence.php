<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecuritySessionPresence extends Model
{
    protected $table = 'security_session_presence';

    protected $primaryKey = 'session_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_id',
        'guard',
        'account_type',
        'account_id',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
