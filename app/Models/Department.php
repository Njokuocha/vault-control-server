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
        static::saved(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('reports'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('reports'));
    }

    public function staff(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
