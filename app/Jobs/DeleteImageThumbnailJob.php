<?php

namespace App\Jobs;

use App\Services\ImageThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteImageThumbnailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [5, 30];

    public function __construct(public readonly string $sourcePath)
    {
        $this->onQueue('default');
    }

    public function handle(ImageThumbnailService $thumbnails): void
    {
        $thumbnails->delete($this->sourcePath);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Image thumbnail cleanup job failed.', [
            'source_path' => $this->sourcePath,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
