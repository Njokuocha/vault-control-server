<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ReportComment extends Model
{
    protected $fillable = [
        'report_id',
        'parent_id',
        'author_type',
        'author_id',
        'body',
    ];

    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bump('reports'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bump('reports'));
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->latest('updated_at');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
