<?php

namespace App\Services;

use App\Models\ReportMedia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ReportVideoThumbnailService
{
    private const THUMBNAIL_DIRECTORY = 'renditions/report-video-posters/';

    public function thumbnailPath(string $sourcePath): string
    {
        return self::THUMBNAIL_DIRECTORY.hash('sha256', $sourcePath).'.jpg';
    }

    public function generate(string $sourcePath): ?string
    {
        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        if (! $disk->exists($sourcePath) || ! method_exists($disk, 'path')) {
            return null;
        }

        $thumbnailPath = $this->thumbnailPath($sourcePath);
        if ($disk->exists($thumbnailPath)) {
            return $thumbnailPath;
        }

        $ffmpeg = $this->executable();
        if ($ffmpeg === null) {
            return null;
        }

        $temporaryDirectory = storage_path('app/private/video-processing');
        if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Unable to create the private video processing directory.');
        }

        $temporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.jpg';
        try {
            $this->extractFrame($ffmpeg, $disk->path($sourcePath), $temporaryPath);
            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to read the generated report video thumbnail.');
            }

            try {
                if (! $disk->put($thumbnailPath, $stream)) {
                    throw new \RuntimeException('Unable to store the generated report video thumbnail.');
                }
            } finally {
                fclose($stream);
            }

            return $thumbnailPath;
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function deleteIfUnreferenced(string $thumbnailPath): void
    {
        if (! str_starts_with($thumbnailPath, self::THUMBNAIL_DIRECTORY)
            || ReportMedia::query()->where('thumbnail_path', $thumbnailPath)->exists()) {
            return;
        }

        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        if ($disk->exists($thumbnailPath) && ! $disk->delete($thumbnailPath)) {
            throw new \RuntimeException('Unable to remove the generated report video thumbnail.');
        }
    }

    private function extractFrame(string $ffmpeg, string $inputPath, string $outputPath): void
    {
        $lastException = null;
        foreach (['1', '0'] as $timestamp) {
            try {
                $process = new Process([
                    $ffmpeg,
                    '-nostdin', '-v', 'error', '-y',
                    '-protocol_whitelist', 'file,pipe',
                    '-i', $inputPath,
                    '-ss', $timestamp,
                    '-frames:v', '1',
                    '-vf', 'scale=min(640\\,iw):-2',
                    '-q:v', '4',
                    '-f', 'image2',
                    $outputPath,
                ]);
                $process->setTimeout(150);
                $process->mustRun();

                if (is_file($outputPath) && filesize($outputPath) > 0) {
                    return;
                }
            } catch (ProcessFailedException $exception) {
                $lastException = $exception;
            }

            if (is_file($outputPath)) {
                unlink($outputPath);
            }
        }

        Log::warning('Unable to extract a frame from report video.', [
            'exception' => $lastException,
        ]);
        throw new \RuntimeException('The report video did not produce a thumbnail frame.', previous: $lastException);
    }

    private function executable(): ?string
    {
        $configuredPath = config('vault.ffmpeg_path');
        if (is_string($configuredPath) && $configuredPath !== '') {
            if (! is_file($configuredPath) || ! is_executable($configuredPath)) {
                throw new \InvalidArgumentException('Configured ffmpeg executable is not available.');
            }

            return $configuredPath;
        }

        return (new ExecutableFinder)->find('ffmpeg');
    }
}
