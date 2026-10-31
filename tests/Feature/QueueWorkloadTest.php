<?php

use App\Jobs\BuildArchiveIndexJob;
use App\Jobs\BuildImageThumbnailJob;
use App\Jobs\BuildVideoPreviewJob;
use App\Jobs\DeleteImageThumbnailJob;
use App\Jobs\FinalizeUploadTransferJob;
use App\Jobs\FinalizeVideoUploadJob;
use App\Models\File;
use App\Services\VideoProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('long-running video jobs are isolated from routine jobs and duplicate previews are suppressed', function () {
    Queue::fake();

    FinalizeVideoUploadJob::dispatch(10);
    BuildVideoPreviewJob::dispatch(20);
    BuildVideoPreviewJob::dispatch(20);
    FinalizeUploadTransferJob::dispatch(30);
    BuildImageThumbnailJob::dispatch('2026/10/image.png');
    DeleteImageThumbnailJob::dispatch('2026/10/image.png');
    BuildArchiveIndexJob::dispatch(40);

    Queue::assertPushed(FinalizeVideoUploadJob::class, fn (FinalizeVideoUploadJob $job): bool => $job->queue === 'video-processing');
    Queue::assertPushed(BuildVideoPreviewJob::class, fn (BuildVideoPreviewJob $job): bool => $job->queue === 'video-processing');
    Queue::assertPushed(BuildVideoPreviewJob::class, 1);
    Queue::assertPushed(FinalizeUploadTransferJob::class, fn (FinalizeUploadTransferJob $job): bool => $job->queue === 'default');
    Queue::assertPushed(BuildImageThumbnailJob::class, fn (BuildImageThumbnailJob $job): bool => $job->queue === 'default');
    Queue::assertPushed(DeleteImageThumbnailJob::class, fn (DeleteImageThumbnailJob $job): bool => $job->queue === 'default');
    Queue::assertPushed(BuildArchiveIndexJob::class, fn (BuildArchiveIndexJob $job): bool => $job->queue === 'default');

    expect(config('queue.connections.database.retry_after'))
        ->toBeGreaterThan((new BuildVideoPreviewJob(20))->timeout)
        ->toBeGreaterThan((new FinalizeVideoUploadJob(10))->timeout)
        ->and((new BuildVideoPreviewJob(20))->tries)->toBe(2)
        ->and((new BuildVideoPreviewJob(20))->backoff)->toBe([60, 300])
        ->and((new FinalizeVideoUploadJob(10))->tries)->toBe(3)
        ->and((new FinalizeVideoUploadJob(10))->backoff)->toBe([60, 300])
        ->and((new FinalizeUploadTransferJob(30))->backoff)->toBe([30, 90])
        ->and((new BuildImageThumbnailJob('2026/10/image.png'))->tries)->toBe(2)
        ->and((new DeleteImageThumbnailJob('2026/10/image.png'))->tries)->toBe(3);
});

test('video preview jobs attach generated renditions to the existing private file', function () {
    Storage::fake('vault');
    config(['vault.upload_disk' => 'vault']);

    $file = File::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'evidence.mp4',
        'stored_path' => '2026/10/evidence.mp4',
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'category' => 'video',
        'upload_method' => 'manual',
        'size' => 100,
        'checksum' => str_repeat('a', 64),
    ]);
    Storage::disk('vault')->put($file->stored_path, 'private video bytes');

    $processor = Mockery::mock(VideoProcessingService::class);
    $processor->shouldReceive('process')
        ->once()
        ->with(Storage::disk('vault')->path($file->stored_path), 'file-'.$file->id)
        ->andReturn([
            'playable_path' => 'renditions/videos/preview.mp4',
            'poster_path' => 'renditions/posters/preview.jpg',
            'duration' => 12,
        ]);
    (new BuildVideoPreviewJob($file->id))->handle($processor);

    expect($file->fresh()->video_playable_path)->toBe('renditions/videos/preview.mp4')
        ->and($file->fresh()->video_poster_path)->toBe('renditions/posters/preview.jpg')
        ->and($file->fresh()->video_duration)->toBe(12);
});

test('exhausted video preview failures are logged without deleting the original', function () {
    Storage::fake('vault');
    config(['vault.upload_disk' => 'vault']);

    $file = File::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'evidence.mp4',
        'stored_path' => '2026/10/evidence.mp4',
        'extension' => 'mp4',
        'mime_type' => 'video/mp4',
        'category' => 'video',
        'upload_method' => 'manual',
        'size' => 100,
        'checksum' => str_repeat('a', 64),
    ]);
    Storage::disk('vault')->put($file->stored_path, 'private video bytes');

    Log::shouldReceive('error')->once()->with(
        'Video preview job failed; the original video remains available.',
        ['file_id' => $file->id, 'exception' => RuntimeException::class],
    );
    (new BuildVideoPreviewJob($file->id))->failed(new RuntimeException('preview failed'));

    Storage::disk('vault')->assertExists($file->stored_path);
    expect($file->fresh()->video_playable_path)->toBeNull();
});
