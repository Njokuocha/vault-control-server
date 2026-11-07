<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'code',
        'name',
        'rank',
        'rank_short',
    ];

    protected static function booted(): void
    {
        static::saved(function (Department $department): void {
            $cache = app(VersionedCache::class);
            if ($department->wasChanged(['name', 'rank', 'rank_short', 'code'])) {
                $cache->bumpAfterCommit('reports');
                $cache->bumpAfterCommit('report-related');
            }
        });
        static::deleted(function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('reports');
            $cache->bumpAfterCommit('report-related');
        });
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
