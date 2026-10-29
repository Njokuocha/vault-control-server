<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('settings'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('settings'));
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];
}
