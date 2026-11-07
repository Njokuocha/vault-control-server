<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StaffGalleryCategory extends Model
{
    protected $fillable = ['user_id', 'title', 'description', 'sort_order', 'is_locked'];

    protected function casts(): array
    {
        return ['is_locked' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('staff-gallery'));
        static::deleted(fn (): mixed => app(VersionedCache::class)->bumpAfterCommit('staff-gallery'));
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function images(): BelongsToMany
    {
        return $this->belongsToMany(
            StaffGalleryImage::class,
            'staff_gallery_category_staff_gallery_image',
            'staff_gallery_category_id',
            'staff_gallery_image_id',
        );
    }

    public function authorizedStaff(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_gallery_category_user');
    }
}
