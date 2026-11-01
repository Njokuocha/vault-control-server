<?php

namespace App\Models;

use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StaffGalleryImage extends Model
{
    protected $fillable = ['user_id', 'file_id', 'path', 'original_name', 'type'];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('staff-gallery');
            $cache->bumpAfterCommit('media');
            $cache->bumpAfterCommit('files');
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            StaffGalleryCategory::class,
            'staff_gallery_category_staff_gallery_image',
            'staff_gallery_image_id',
            'staff_gallery_category_id',
        );
    }
}
