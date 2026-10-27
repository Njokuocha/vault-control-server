<?php

use App\Services\VideoProcessingService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    config(['vault.upload_disk' => 'vault']);
    Storage::fake('vault');
});

test('installed ffmpeg tools probe and create private video previews', function () {
    $finder = new ExecutableFinder;
    $ffmpeg = $finder->find('ffmpeg', config('vault.ffmpeg_path'));
    $ffprobe = $finder->find('ffprobe', config('vault.ffprobe_path'));

    if ($ffmpeg === null || $ffprobe === null) {
        $this->markTestSkipped('FFmpeg and ffprobe are not installed or configured.');
    }

    $directory = storage_path('framework/testing/video-processing-'.\Illuminate\Support\Str::uuid());
    if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Unable to create the video processing test directory.');
    }
    $inputPath = $directory.DIRECTORY_SEPARATOR.'sample.mp4';

    try {
        $generator = new Process([
            $ffmpeg,
            '-nostdin', '-v', 'error', '-y',
            '-f', 'lavfi',
            '-i', 'color=c=black:s=160x90:d=2',
            '-an',
            '-c:v', 'libx264',
            '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
            $inputPath,
        ]);
        $generator->setTimeout(120);
        $generator->mustRun();

        $result = app(VideoProcessingService::class)->process($inputPath, 'feature-test');

        expect($result['playable_path'])->toBeString()
            ->and($result['poster_path'])->toBeString()
            ->and($result['duration'])->toBe(2);
        Storage::disk('vault')->assertExists($result['playable_path']);
        Storage::disk('vault')->assertExists($result['poster_path']);
    } finally {
        if (is_file($inputPath)) {
            unlink($inputPath);
        }
        rmdir($directory);
    }
});
