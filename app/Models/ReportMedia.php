<?php

namespace App\Models;

use App\Jobs\BuildImageThumbnailJob;
use App\Jobs\DeleteImageThumbnailJob;
use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportMedia extends Model
{
    protected $fillable = [
        'report_id',
        'path',
        'original_name',
        'mime_type',
        'media_type',
        'size',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    protected static function booted(): void
    {
        static::created(function (ReportMedia $media): void {
            if ($media->media_type === 'image') {
                BuildImageThumbnailJob::dispatch($media->path)->afterCommit();
            }
        });
        static::saved(function (ReportMedia $media): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('reports');
            $cache->bumpAfterCommit('report-content-'.$media->report_id);
        });
        static::deleted(function (ReportMedia $media): void {
            $cache = app(VersionedCache::class);
            $cache->bumpAfterCommit('reports');
            $cache->bumpAfterCommit('report-content-'.$media->report_id);
            if ($media->media_type === 'image') {
                DeleteImageThumbnailJob::dispatch($media->path)->afterCommit();
            }
        });
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
