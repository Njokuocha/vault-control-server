<?php

namespace App\Jobs;

use App\Events\VideoUploadStatusChanged;
use App\Models\VideoUpload;
use App\Services\FileUploadService;
use App\Services\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class FinalizeVideoUploadJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 2100;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $videoUploadId)
    {
        $this->onQueue('default');
    }

    public function handle(FileUploadService $uploads, VideoProcessingService $videoProcessor): void
    {
        $upload = VideoUpload::query()->findOrFail($this->videoUploadId);
        if ($upload->status !== 'processing') {
            if ($upload->status === 'cancelled') {
                Storage::disk('local')->deleteDirectory('vault-chunks/'.$upload->token);
            }
            return;
        }

        $disk = Storage::disk('local');
        $directory = 'vault-chunks/'.$upload->token;
        $assembledPath = $directory.'/assembled.upload';
        $disk->makeDirectory($directory);
        $assembledAbsolutePath = $disk->path($assembledPath);
        $output = fopen($assembledAbsolutePath, 'wb');

        if ($output === false) {
            throw new \RuntimeException('Unable to create the private video assembly file.');
        }

        try {
            for ($index = 0; $index < $upload->chunk_count; $index++) {
                $chunkPath = $directory.'/'.$index.'.part';
                $input = $disk->readStream($chunkPath);
                if (! is_resource($input)) {
                    throw new \RuntimeException('A required video upload chunk is missing.');
                }

                try {
                    if (stream_copy_to_stream($input, $output) === false) {
                        throw new \RuntimeException('Unable to assemble the uploaded video chunks.');
                    }
                } finally {
                    fclose($input);
                }
            }
        } finally {
            fclose($output);
        }

        clearstatcache(true, $assembledAbsolutePath);
        if (filesize($assembledAbsolutePath) !== $upload->total_size) {
            throw new \RuntimeException('The assembled video size does not match the declared upload size.');
        }

        try {
            $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($assembledAbsolutePath);
            if (! is_string($mimeType) || ! str_starts_with($mimeType, 'video/')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => 'The uploaded content is not a video.',
                ]);
            }
            $claimed = VideoUpload::query()
                ->whereKey($upload->id)
                ->where('status', 'processing')
                ->update(['status' => 'finalizing', 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            event(new VideoUploadStatusChanged($upload->token, 'finalizing', 100));

            $preview = $videoProcessor->process($assembledAbsolutePath, $upload->token);
            try {
                $file = $uploads->store(
                    new UploadedFile($assembledAbsolutePath, $upload->original_name, null, null, true),
                    null,
                    'manual',
                );
            } catch (Throwable $exception) {
                Storage::disk(config('vault.upload_disk', 'vault'))->delete(array_filter([
                    $preview['playable_path'],
                    $preview['poster_path'],
                ]));
                throw $exception;
            }
            if ($preview['playable_path'] !== null || $preview['poster_path'] !== null || $preview['duration'] !== null) {
                $file->forceFill([
                    'video_playable_path' => $preview['playable_path'],
                    'video_poster_path' => $preview['poster_path'],
                    'video_duration' => $preview['duration'],
                ])->save();
            }

            $upload->forceFill([
                'status' => 'completed',
                'file_id' => $file->id,
                'error_message' => null,
            ])->save();

            event(new VideoUploadStatusChanged($upload->token, 'completed', 100, $file->uuid));
        } catch (Throwable $exception) {
            VideoUpload::query()->whereKey($upload->id)->update([
                'status' => 'failed',
                'error_message' => $this->safeErrorMessage($exception),
                'updated_at' => now(),
            ]);

            event(new VideoUploadStatusChanged($upload->token, 'failed', 0));
            throw $exception;
        } finally {
            $disk->deleteDirectory($directory);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $upload = VideoUpload::query()->find($this->videoUploadId);
        if (! $upload || in_array($upload->status, ['completed', 'cancelled'], true)) {
            if ($upload?->status === 'cancelled') {
                Storage::disk('local')->deleteDirectory('vault-chunks/'.$upload->token);
            }
            return;
        }

        $upload->forceFill([
            'status' => 'failed',
            'error_message' => $this->safeErrorMessage($exception),
        ])->save();

        Storage::disk('local')->deleteDirectory('vault-chunks/'.$upload->token);
        event(new VideoUploadStatusChanged($upload->token, 'failed', 0));
    }

    private function safeErrorMessage(?Throwable $exception): string
    {
        if ($exception instanceof \Illuminate\Validation\ValidationException) {
            return collect($exception->errors())->flatten()->first() ?? 'The video upload was rejected.';
        }

        return 'The video could not be finalized. Retry the upload or check the server logs.';
    }
}
