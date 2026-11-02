<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffSessionHistory extends Model
{
    protected $table = 'staff_session_history';

    protected $primaryKey = 'session_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'session_key',
        'staff_id',
        'started_at',
        'last_seen_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
