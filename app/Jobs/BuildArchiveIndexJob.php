<?php

namespace App\Jobs;

use App\Events\ArchiveIndexUpdated;
use App\Models\File;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class BuildArchiveIndexJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $fileId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $file = File::query()->findOrFail($this->fileId);
        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        $absolutePath = $disk->path($file->stored_path);
        $archive = new ZipArchive;
        $opened = $archive->open($absolutePath);

        if ($opened !== true) {
            $file->forceFill([
                'archive_index' => null,
                'archive_status' => 'failed',
                'archive_entry_count' => 0,
            ])->save();
            event(new ArchiveIndexUpdated($file->uuid, 'failed'));

            return;
        }

        try {
            $maxEntries = (int) config('vault.archive_max_entries', 10000);
            $maxUncompressedBytes = (int) config('vault.archive_max_uncompressed_bytes', 2147483648);
            $maxCompressionRatio = (int) config('vault.archive_max_compression_ratio', 1000);

            if ($archive->numFiles > $maxEntries) {
                $this->markFailed($file, 'Archive contains more entries than the configured safety limit.');

                return;
            }

            $tree = [];
            $entryCount = 0;
            $totalUncompressedBytes = 0;

            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                $name = $archive->getNameIndex($index);

                if (! is_array($entry) || ! is_string($name) || $name === '') {
                    continue;
                }

                if (! $this->isSafeEntryName($name)) {
                    continue;
                }

                $isDirectory = str_ends_with($name, '/');
                $parts = explode('/', rtrim($name, '/'));
                if ($parts === ['']) {
                    continue;
                }

                $size = max(0, (int) ($entry['size'] ?? 0));
                $compressedSize = max(0, (int) ($entry['comp_size'] ?? 0));
                $totalUncompressedBytes += $size;

                if (
                    $totalUncompressedBytes > $maxUncompressedBytes
                    || ($size > 0 && $size / max(1, $compressedSize) > $maxCompressionRatio)
                ) {
                    $this->markFailed($file, 'Archive exceeds the configured uncompressed-size or compression-ratio safety limit.');

                    return;
                }

                $entryCount++;
                $this->insertEntry($tree, $parts, $name, $isDirectory, $size);
            }

            $this->sortTree($tree);
            $file->forceFill([
                'archive_index' => ['type' => 'directory', 'name' => '', 'children' => array_values($tree)],
                'archive_status' => 'ready',
                'archive_entry_count' => $entryCount,
            ])->save();
            event(new ArchiveIndexUpdated($file->uuid, 'ready'));
        } finally {
            $archive->close();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file) {
            return;
        }

        $file->forceFill([
            'archive_index' => null,
            'archive_status' => 'failed',
            'archive_entry_count' => 0,
        ])->save();
        event(new ArchiveIndexUpdated($file->uuid, 'failed'));

        if ($exception !== null) {
            Log::error('Archive index job failed.', [
                'file_id' => $this->fileId,
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $tree
     * @param list<string> $parts
     */
    private function insertEntry(array &$tree, array $parts, string $path, bool $isDirectory, int $size): void
    {
        $name = array_shift($parts);
        if (! is_string($name)) {
            return;
        }

        $isLeaf = $parts === [];
        if (! isset($tree[$name])) {
            $tree[$name] = [
                'type' => $isLeaf && ! $isDirectory ? 'file' : 'directory',
                'name' => $name,
                'path' => $isLeaf && ! $isDirectory ? $path : null,
                'size' => $isLeaf && ! $isDirectory ? $size : null,
                'children' => [],
            ];
        }

        if ($isLeaf) {
            $tree[$name]['type'] = $isDirectory ? 'directory' : 'file';
            $tree[$name]['path'] = $isDirectory ? null : $path;
            $tree[$name]['size'] = $isDirectory ? null : $size;

            return;
        }

        $this->insertEntry($tree[$name]['children'], $parts, $path, $isDirectory, $size);
    }

    /**
     * @param array<string, array<string, mixed>> $tree
     */
    private function sortTree(array &$tree): void
    {
        uasort($tree, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'directory' ? -1 : 1;
            }

            return strnatcasecmp($left['name'], $right['name']);
        });

        foreach ($tree as &$entry) {
            if ($entry['type'] === 'directory') {
                $this->sortTree($entry['children']);
            }
        }
    }

    private function isSafeEntryName(string $name): bool
    {
        if (str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }

        foreach (explode('/', trim($name, '/')) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function markFailed(File $file, string $reason): void
    {
        $file->forceFill([
            'archive_index' => null,
            'archive_status' => 'failed',
            'archive_entry_count' => 0,
        ])->save();
        event(new ArchiveIndexUpdated($file->uuid, 'failed'));

        Log::warning('Archive index was rejected by safety limits.', [
            'file_id' => $file->id,
            'reason' => $reason,
        ]);
    }
}
