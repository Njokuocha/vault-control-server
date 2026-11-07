<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ImageThumbnailService
{
    public function thumbnailPath(string $sourcePath): string
    {
        return 'renditions/images/'.hash('sha256', $sourcePath).'.jpg';
    }

    public function generate(string $sourcePath): ?string
    {
        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        if (! $disk->exists($sourcePath) || ! method_exists($disk, 'path')) {
            return null;
        }

        $sourceAbsolutePath = $disk->path($sourcePath);
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($sourceAbsolutePath);
        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'image/bmp'], true)) {
            return null;
        }

        $ffmpeg = $this->executable();
        if ($ffmpeg === null) {
            return null;
        }

        $temporaryDirectory = storage_path('app/private/image-processing');
        if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Unable to create the private image processing directory.');
        }

        $temporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.Str::uuid().'.jpg';

        try {
            $process = new Process([
                $ffmpeg,
                '-nostdin', '-v', 'error', '-y',
                '-protocol_whitelist', 'file,pipe',
                '-i', $sourceAbsolutePath,
                '-frames:v', '1',
                '-vf', 'scale=min(480\\,iw):-2',
                '-q:v', '4',
                '-f', 'image2',
                $temporaryPath,
            ]);
            $process->setTimeout(120);
            $process->mustRun();

            if (! is_file($temporaryPath) || filesize($temporaryPath) < 1) {
                throw new \RuntimeException('The image processor did not create a thumbnail.');
            }
            if (! $disk->exists($sourcePath)) {
                return null;
            }

            $thumbnailPath = $this->thumbnailPath($sourcePath);
            $stream = fopen($temporaryPath, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Unable to read the generated image thumbnail.');
            }

            try {
                if (! $disk->put($thumbnailPath, $stream)) {
                    throw new \RuntimeException('Unable to store the generated private image thumbnail.');
                }
            } finally {
                fclose($stream);
            }

            return $thumbnailPath;
        } catch (ProcessFailedException $exception) {
            Log::warning('Image thumbnail processing failed; the original image remains available.', [
                'source_path' => $sourcePath,
                'exception' => $exception,
            ]);

            return null;
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }

    public function delete(string $sourcePath): void
    {
        $thumbnailPath = $this->thumbnailPath($sourcePath);
        $disk = Storage::disk(config('vault.upload_disk', 'vault'));
        if ($disk->exists($thumbnailPath) && ! $disk->delete($thumbnailPath)) {
            throw new \RuntimeException('Unable to remove the generated private image thumbnail.');
        }
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
