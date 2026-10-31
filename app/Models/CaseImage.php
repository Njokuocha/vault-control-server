<?php

namespace App\Models;

use App\Jobs\BuildImageThumbnailJob;
use App\Jobs\DeleteImageThumbnailJob;
use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaseImage extends Model
{
    protected $fillable = ['case_category_id', 'path', 'original_name', 'type', 'file_id', 'poster_path', 'duration', 'playable_path'];

    protected function casts(): array
    {
        return ['duration' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(function (CaseImage $image): void {
            if ($image->file_id === null && in_array($image->type, [null, 'image'], true)) {
                BuildImageThumbnailJob::dispatch($image->path)->afterCommit();
            }
        });
        $invalidate = static function (CaseImage $image): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('media');
            $cache->bumpAfterCommit('cases');
            $cache->bumpAfterCommit('files');
        };
        static::saved($invalidate);
        static::deleted(function (CaseImage $image) use ($invalidate): void {
            $invalidate($image);
            if ($image->file_id === null && in_array($image->type, [null, 'image'], true)) {
                DeleteImageThumbnailJob::dispatch($image->path)->afterCommit();
            }
        });
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CaseCategory::class, 'case_category_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
