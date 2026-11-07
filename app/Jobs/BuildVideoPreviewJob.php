<?php

namespace App\Jobs;

use App\Models\File;
use App\Services\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class BuildVideoPreviewJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 2100;

    public bool $failOnTimeout = true;

    public array $backoff = [60, 300];

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $fileId)
    {
        $this->onQueue('video-processing');
    }

    public function uniqueId(): string
    {
        return (string) $this->fileId;
    }

    public function handle(VideoProcessingService $videoProcessor): void
    {
        $file = File::query()->find($this->fileId);
        if (! $file || $file->category !== 'video') {
            return;
        }

        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        if (! $disk->exists($file->stored_path)) {
            return;
        }
        if (! method_exists($disk, 'path')) {
            throw new \RuntimeException('The configured private storage disk cannot provide a local video processing path.');
        }

        $preview = $videoProcessor->process($disk->path($file->stored_path), 'file-'.$file->id);
        $previewPaths = array_filter([$preview['playable_path'], $preview['poster_path']]);
        if ($previewPaths === [] && $preview['duration'] === null) {
            return;
        }

        $currentFile = File::query()->find($this->fileId);
        if (! $currentFile || $currentFile->stored_path !== $file->stored_path) {
            $this->deletePreviewFiles($disk, $previewPaths);

            return;
        }

        try {
            $saved = $currentFile->forceFill([
                'video_playable_path' => $preview['playable_path'],
                'video_poster_path' => $preview['poster_path'],
                'video_duration' => $preview['duration'],
            ])->save();
            if (! $saved) {
                throw new \RuntimeException('Unable to attach generated video previews to the private file.');
            }
        } catch (Throwable $exception) {
            $this->deletePreviewFiles($disk, $previewPaths);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Video preview job failed; the original video remains available.', [
            'file_id' => $this->fileId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }

    private function deletePreviewFiles(FilesystemAdapter $disk, array $paths): void
    {
        if ($paths !== [] && ! $disk->delete($paths)) {
            throw new \RuntimeException('Unable to remove unreferenced generated video previews.');
        }
    }
}
