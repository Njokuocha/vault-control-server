<?php

namespace App\Jobs;

use App\Services\ReportVideoThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeleteReportVideoThumbnailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [5, 30];

    public function __construct(public readonly string $thumbnailPath)
    {
        $this->onQueue('default');
    }

    public function handle(ReportVideoThumbnailService $thumbnails): void
    {
        $thumbnails->deleteIfUnreferenced($this->thumbnailPath);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Report video thumbnail cleanup job failed.', [
            'thumbnail_path' => $this->thumbnailPath,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
