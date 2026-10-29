<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecurityLocation extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'map_url',
        'embed_url',
        'latitude',
        'longitude',
        'updated_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['updated_at' => 'datetime'];
    }
}
