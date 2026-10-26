<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\VersionedCache;

class Setting extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bump('settings'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bump('settings'));
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];
}
