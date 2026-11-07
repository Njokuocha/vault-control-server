<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PersonGalleryCategory extends Model
{
    protected $fillable = ['person_id', 'title', 'description', 'sort_order', 'is_locked'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('people'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('people'));
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(
            PersonImage::class,
            'person_gallery_category_person_image',
            'person_gallery_category_id',
            'person_image_id',
        );
    }

    public function authorizedStaff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'person_gallery_category_user');
    }
}
