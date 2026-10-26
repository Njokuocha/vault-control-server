<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Support\VersionedCache;

class InvestigationCase extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bump('cases'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bump('cases'));
    }

    protected $table = 'case_files';

    protected $fillable = ['public_id', 'title', 'classification', 'case_status', 'narrative', 'comment'];

    public function categories(): HasMany
    {
        return $this->hasMany(CaseCategory::class, 'case_file_id')->orderBy('sort_order');
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'case_person', 'case_file_id', 'person_id')->withTimestamps();
    }
}
