<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Support\VersionedCache;
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
        $invalidate = static function (CaseImage $image): void {
            app(VersionedCache::class)->bump('media');
            app(VersionedCache::class)->bump('cases');
        };
        static::saved($invalidate);
        static::deleted($invalidate);
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
