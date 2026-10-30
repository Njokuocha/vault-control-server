<?php

namespace App\Models;

use App\Events\FileDeleted;
use App\Events\FileEdited;
use App\Services\ActivityLogger;
use App\Support\VersionedCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        'video_playable_path',
        'video_poster_path',
        'video_duration',
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
            'video_duration' => 'integer',
        ];
    }

    public function scopeStandaloneArchive(Builder $query): Builder
    {
        return $query
            ->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')
                ->from('case_images')
                ->where(fn ($attachment) => $attachment
                    ->whereColumn('case_images.file_id', 'files.id')
                    ->orWhereColumn('case_images.path', 'files.stored_path')))
            ->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')
                ->from('person_images')
                ->where(fn ($attachment) => $attachment
                    ->whereColumn('person_images.file_id', 'files.id')
                    ->orWhereColumn('person_images.path', 'files.stored_path')))
            ->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')
                ->from('people')
                ->where(fn ($attachment) => $attachment
                    ->whereColumn('people.main_image_file_id', 'files.id')
                    ->orWhereColumn('people.main_image_path', 'files.stored_path')))
            ->whereNotExists(fn ($subquery) => $subquery->selectRaw('1')
                ->from('upload_transfers')
                ->whereColumn('upload_transfers.file_id', 'files.id')
                ->whereNotNull('upload_transfers.attachment_purpose'));
    }

    protected static function booted(): void
    {
        static::deleting(function (File $file): void {
            $paths = array_filter([$file->video_playable_path, $file->video_poster_path]);
            if ($paths !== [] && ! Storage::disk(config('vault.upload_disk', 'vault'))->delete($paths)) {
                throw new \RuntimeException('Unable to remove generated private video previews.');
            }
        });
        static::saved(function (): void {
            DB::afterCommit(function (): void {
                app(VersionedCache::class)->bump('files');
                Cache::forget('dashboard.archive-metrics');
                Cache::forget('dashboard.archive-years');
                Cache::forget('dashboard.archive-extensions');
            });
        });
        static::deleted(function (File $file): void {
            DB::afterCommit(function (): void {
                app(VersionedCache::class)->bump('files');
                Cache::forget('dashboard.archive-metrics');
                Cache::forget('dashboard.archive-years');
                Cache::forget('dashboard.archive-extensions');
            });

            event(new FileDeleted(
                $file->uuid,
                $file->original_name,
                $file->category,
                $file->created_at?->format('Y-m') ?? '',
            ));
            app(ActivityLogger::class)->record(
                'file-management',
                'file.deleted',
                "A {$file->category} file was deleted from the archive.",
                [
                    'file_id' => $file->getKey(),
                    'file_uuid' => $file->uuid,
                    'file_type' => $file->category,
                    'extension' => $file->extension,
                    'size_bytes' => $file->size,
                ],
            );
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
            app(ActivityLogger::class)->record(
                'file-management',
                'file.updated',
                "A {$file->category} file was updated in the archive.",
                [
                    'file_id' => $file->getKey(),
                    'file_uuid' => $file->uuid,
                    'file_type' => $file->category,
                    'changed_fields' => array_keys($file->getChanges()),
                    'size_bytes' => $file->size,
                ],
            );
        });
    }
}
