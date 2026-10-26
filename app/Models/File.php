<?php

namespace App\Models;

use App\Events\FileDeleted;
use App\Events\FileEdited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use App\Support\VersionedCache;

class File extends Model
{
    protected $fillable = [
        'uuid',
        'original_name',
        'stored_path',
        'extension',
        'mime_type',
        'category',
        'upload_method',
        'size',
        'checksum',
        'is_editable',
        'version',
        'source_ip',
        'edited_at',
        'archive_index',
        'archive_status',
        'archive_entry_count',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'is_editable' => 'boolean',
            'version' => 'integer',
            'edited_at' => 'datetime',
            'archive_index' => 'array',
            'archive_entry_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (): void {
            app(VersionedCache::class)->bump('files');
            Cache::forget('dashboard.archive-metrics');
            Cache::forget('dashboard.archive-years');
            Cache::forget('dashboard.archive-extensions');
        });
        static::deleted(function (File $file): void {
            app(VersionedCache::class)->bump('files');
            Cache::forget('dashboard.archive-metrics');
            Cache::forget('dashboard.archive-years');
            Cache::forget('dashboard.archive-extensions');

            event(new FileDeleted(
                $file->uuid,
                $file->original_name,
                $file->category,
                $file->created_at?->format('Y-m') ?? '',
            ));
        });
        static::updated(function (File $file): void {
            if (! $file->wasChanged(['original_name', 'category', 'size', 'extension', 'mime_type'])) {
                return;
            }

            event(new FileEdited(
                $file->uuid,
                $file->original_name,
                $file->category,
                $file->created_at?->format('Y-m') ?? '',
            ));
        });
    }
}
