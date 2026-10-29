<?php

namespace App\Models;

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
        $invalidate = static function (PersonImage $image): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('media');
            $cache->bumpAfterCommit('people');
        };
        static::saved($invalidate);
        static::deleted($invalidate);
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
