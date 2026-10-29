<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestigationCase extends Model
{
    protected static function booted(): void
    {
        static::saved(function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('cases');
            $cache->bumpAfterCommit('reports');
        });
        static::deleted(function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('cases');
            $cache->bumpAfterCommit('reports');
        });
    }

    protected $table = 'case_files';

    protected $fillable = ['public_id', 'title', 'classification', 'case_status', 'narrative', 'comment', 'created_by_user_id'];

    public function categories(): HasMany
    {
        return $this->hasMany(CaseCategory::class, 'case_file_id')->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'case_person', 'case_file_id', 'person_id')->withTimestamps();
    }

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class, 'report_case_file', 'case_file_id', 'report_id')->withTimestamps();
    }
}
