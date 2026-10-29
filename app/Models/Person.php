<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Person extends Model
{
    protected static function booted(): void
    {
        static::saved(function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('people');
            $cache->bumpAfterCommit('reports');
        });
        static::deleted(function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('people');
            $cache->bumpAfterCommit('reports');
        });
    }

    protected $fillable = [
        'public_id',
        'name',
        'first_name',
        'last_name',
        'surname',
        'age',
        'date_of_birth',
        'marital_status',
        'spouse',
        'gender',
        'citizenship',
        'state',
        'blood_group',
        'genotype',
        'height',
        'flag',
        'occupation',
        'aliases',
        'last_known_location',
        'identifying_marks',
        'avatar_group',
        'main_image_path',
        'main_image_file_id',
        'statement',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(PersonImage::class);
    }

    public function mainImageFile(): BelongsTo
    {
        return $this->belongsTo(File::class, 'main_image_file_id');
    }

    public function cases(): BelongsToMany
    {
        return $this->belongsToMany(InvestigationCase::class, 'case_person', 'person_id', 'case_file_id')->withTimestamps();
    }

    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(Report::class, 'report_person', 'person_id', 'report_id')->withTimestamps();
    }
}
