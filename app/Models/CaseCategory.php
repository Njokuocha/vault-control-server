<?php

namespace App\Models;

use App\Support\VersionedCache;
use App\Support\CaseAnalysisCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CaseCategory extends Model
{
    protected $fillable = ['case_file_id', 'title', 'description', 'sort_order', 'is_locked'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }

    protected static function booted(): void
    {
        $invalidate = static function (CaseCategory $category): void {
            app(VersionedCache::class)->bumpAfterCommit('cases');
            app(CaseAnalysisCache::class)->invalidate((int) $category->case_file_id);
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function caseFile(): BelongsTo
    {
        return $this->belongsTo(InvestigationCase::class, 'case_file_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(CaseImage::class);
    }

    public function assignedImages(): BelongsToMany
    {
        return $this->belongsToMany(
            CaseImage::class,
            'case_category_case_image',
            'case_category_id',
            'case_image_id',
        );
    }

    public function authorizedStaff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'case_category_user');
    }
}
