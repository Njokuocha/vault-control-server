<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class VideoProcessingService
{
    /**
     * @return array{playable_path: string|null, poster_path: string|null, duration: int|null}
     */
    public function process(string $inputPath, string $token): array
    {
        $ffprobe = $this->executable('ffprobe', config('vault.ffprobe_path'));
        $ffmpeg = $this->executable('ffmpeg', config('vault.ffmpeg_path'));
        $duration = null;

        if ($ffprobe !== null) {
            $duration = $this->probe($ffprobe, $inputPath);
        }

        if ($ffmpeg === null) {
            return ['playable_path' => null, 'poster_path' => null, 'duration' => $duration];
        }

        $temporaryDirectory = storage_path('app/private/video-processing');
        if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0700, true) && ! is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Unable to create the private video processing directory.');
        }

        $safeToken = Str::uuid()->toString();
        $playableTemporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.$safeToken.'.mp4';
        $posterTemporaryPath = $temporaryDirectory.DIRECTORY_SEPARATOR.$safeToken.'.jpg';

        try {
            $this->run($ffmpeg, [
                '-nostdin', '-v', 'error', '-y',
                '-protocol_whitelist', 'file,pipe',
                '-i', $inputPath,
                '-map', '0:v:0',
                '-map', '0:a?',
                '-c:v', 'libx264',
                '-preset', 'veryfast',
                '-crf', '23',
                '-c:a', 'aac',
                '-movflags', '+faststart',
                '-f', 'mp4',
                $playableTemporaryPath,
            ], 1800);

            $this->run($ffmpeg, [
                '-nostdin', '-v', 'error', '-y',
                '-ss', '1',
                '-protocol_whitelist', 'file,pipe',
                '-i', $inputPath,
                '-frames:v', '1',
                '-vf', 'scale=min(640\\,iw):-2',
                '-f', 'image2',
                $posterTemporaryPath,
            ], 180);

            if (! is_file($playableTemporaryPath) || filesize($playableTemporaryPath) < 1
                || ! is_file($posterTemporaryPath) || filesize($posterTemporaryPath) < 1) {
                throw new \RuntimeException('The video processor did not create the expected preview files.');
            }

            $disk = Storage::disk(config('vault.upload_disk', 'vault'));
            $playablePath = 'renditions/videos/'.$safeToken.'.mp4';
            $posterPath = 'renditions/posters/'.$safeToken.'.jpg';

            try {
                $this->storePrivateFile($disk, $playablePath, $playableTemporaryPath);
                $this->storePrivateFile($disk, $posterPath, $posterTemporaryPath);
            } catch (Throwable $exception) {
                $disk->delete([$playablePath, $posterPath]);
                throw $exception;
            }

            return [
                'playable_path' => $playablePath,
                'poster_path' => $posterPath,
                'duration' => $duration,
            ];
        } catch (\RuntimeException $exception) {
            Log::warning('Video preview processing failed; the original video remains available.', [
                'token' => $token,
                'exception' => $exception,
            ]);

            return ['playable_path' => null, 'poster_path' => null, 'duration' => $duration];
        } finally {
            foreach ([$playableTemporaryPath, $posterTemporaryPath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function probe(string $ffprobe, string $inputPath): ?int
    {
        $process = new Process([
            $ffprobe,
            '-v', 'error',
            '-protocol_whitelist', 'file,pipe',
            '-show_entries', 'stream=codec_type:format=duration',
            '-of', 'json',
            $inputPath,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (! $process->isSuccessful()) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file does not contain a readable video stream.',
            ]);
        }

        try {
            $metadata = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The video probe returned invalid metadata.', previous: $exception);
        }

        $hasVideoStream = collect($metadata['streams'] ?? [])
            ->contains(fn (mixed $stream): bool => is_array($stream) && ($stream['codec_type'] ?? null) === 'video');
        if (! $hasVideoStream) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file does not contain a video stream.',
            ]);
        }

        $duration = $metadata['format']['duration'] ?? null;

        return is_numeric($duration) && (float) $duration >= 0
            ? (int) round((float) $duration)
            : null;
    }

    /**
     * @param list<string> $arguments
     */
    private function run(string $executable, array $arguments, int $timeout): void
    {
        $process = new Process([$executable, ...$arguments]);
        $process->setTimeout($timeout);
        $process->mustRun();
    }

    private function executable(string $name, mixed $configuredPath): ?string
    {
        if (is_string($configuredPath) && $configuredPath !== '') {
            if (! is_file($configuredPath) || ! is_executable($configuredPath)) {
                throw new \InvalidArgumentException("Configured {$name} executable is not available.");
            }

            return $configuredPath;
        }

        return (new ExecutableFinder)->find($name);
    }

    private function storePrivateFile(mixed $disk, string $path, string $source): void
    {
        $stream = fopen($source, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to read a generated private video preview file.');
        }

        try {
            if (! $disk->put($path, $stream)) {
                throw new \RuntimeException('Unable to store a generated private video preview file.');
            }
        } finally {
            fclose($stream);
        }
    }
}
