<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Admin extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bump('reports'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bump('reports'));
    }
}
