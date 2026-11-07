<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function uploadTransfers(): HasMany
    {
        return $this->hasMany(UploadTransfer::class);
    }

    protected static function booted(): void
    {
        static::saved(function (Admin $admin): void {
            $cache = app(VersionedCache::class);
            if ($admin->wasChanged(['name', 'email'])) {
                $cache->bumpAfterCommit('reports');
            }
            if ($admin->wasChanged('name')) {
                $cache->bumpAfterCommit('report-related');
            }
        });
        static::deleted(function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('reports');
            $cache->bumpAfterCommit('report-related');
        });
    }
}
