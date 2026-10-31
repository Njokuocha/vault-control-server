<?php

namespace App\Models;

use App\Jobs\BuildImageThumbnailJob;
use App\Jobs\DeleteImageThumbnailJob;
use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonImage extends Model
{
    protected $fillable = ['person_id', 'path', 'original_name', 'type', 'file_id', 'poster_path', 'duration', 'playable_path'];

    protected function casts(): array
    {
        return ['duration' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(function (PersonImage $image): void {
            if ($image->file_id === null && in_array($image->type, [null, 'image'], true)) {
                BuildImageThumbnailJob::dispatch($image->path)->afterCommit();
            }
        });
        $invalidate = static function (PersonImage $image): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('media');
            $cache->bumpAfterCommit('people');
            $cache->bumpAfterCommit('files');
        };
        static::saved($invalidate);
        static::deleted(function (PersonImage $image) use ($invalidate): void {
            $invalidate($image);
            if ($image->file_id === null && in_array($image->type, [null, 'image'], true)) {
                DeleteImageThumbnailJob::dispatch($image->path)->afterCommit();
            }
        });
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
