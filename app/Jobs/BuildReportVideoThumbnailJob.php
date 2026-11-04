<?php

namespace App\Jobs;

use App\Events\AgencyReportUpdated;
use App\Models\ReportMedia;
use App\Services\ReportVideoThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuildReportVideoThumbnailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public array $backoff = [30, 120];

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $reportMediaId)
    {
        $this->onQueue('video-processing');
    }

    public function uniqueId(): string
    {
        return (string) $this->reportMediaId;
    }

    public function handle(ReportVideoThumbnailService $thumbnails): void
    {
        $media = ReportMedia::query()->find($this->reportMediaId);
        if (! $media || $media->media_type !== 'video') {
            return;
        }

        $sourcePath = $media->path;
        $thumbnailPath = $thumbnails->generate($sourcePath);
        if ($thumbnailPath === null) {
            Log::warning('Report video thumbnail generation did not produce a poster.', [
                'report_media_id' => $media->id,
            ]);

            return;
        }

        $currentMedia = ReportMedia::query()->find($this->reportMediaId);
        if (! $currentMedia || $currentMedia->media_type !== 'video' || $currentMedia->path !== $sourcePath) {
            $thumbnails->deleteIfUnreferenced($thumbnailPath);

            return;
        }

        $currentMedia->forceFill(['thumbnail_path' => $thumbnailPath])->save();
        event(new AgencyReportUpdated($currentMedia->report_id, 'media_thumbnail_ready'));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Report video thumbnail job failed.', [
            'report_media_id' => $this->reportMediaId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
