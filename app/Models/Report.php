<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Report extends Model
{
    protected $fillable = [
        'uuid',
        'body',
        'reporter_type',
        'reporter_id',
        'is_hidden',
    ];

    protected function casts(): array
    {
        return ['is_hidden' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bump('reports'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bump('reports'));
    }

    public function reporter(): MorphTo
    {
        return $this->morphTo();
    }

    public function media(): HasMany
    {
        return $this->hasMany(ReportMedia::class)->orderBy('sort_order')->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ReportComment::class);
    }

    public function latestComment(): HasOne
    {
        return $this->hasOne(ReportComment::class)->latestOfMany('updated_at');
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(InvestigationCase::class, 'report_case_file', 'report_id', 'case_file_id')
            ->withTimestamps();
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'report_person', 'report_id', 'person_id')
            ->withTimestamps();
    }
}
