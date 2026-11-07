<?php

namespace App\Jobs;

use App\Services\ImageThumbnailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildImageThumbnailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 150;

    public array $backoff = [10, 30];

    public int $uniqueFor = 7200;

    public function __construct(public readonly string $sourcePath)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->sourcePath);
    }

    public function handle(ImageThumbnailService $thumbnails): void
    {
        $thumbnails->generate($this->sourcePath);
    }
}
