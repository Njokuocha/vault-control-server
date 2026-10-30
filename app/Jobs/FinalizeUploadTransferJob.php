<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\UploadTransfer;
use App\Services\FileUploadService;
use App\Services\RecordUploadAttachmentService;
use App\Services\UploadTransferStatus;
use App\Services\VideoProcessingService;
use App\Support\RecordAttachmentTypes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class FinalizeUploadTransferJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 2100;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $uploadTransferId)
    {
        $this->onQueue('default');
    }

    public function handle(
        FileUploadService $uploads,
        VideoProcessingService $videoProcessor,
        UploadTransferStatus $statusUpdates,
        RecordAttachmentTypes $attachmentTypes,
        RecordUploadAttachmentService $attachments,
    ): void {
        $upload = UploadTransfer::query()->findOrFail($this->uploadTransferId);
        if ($upload->attachment_purpose !== null && $upload->committed_at === null) {
            return;
        }
        if ($upload->status === 'cancelled') {
            Storage::disk('local')->deleteDirectory($this->chunkDirectory($upload));

            return;
        }
        if ($upload->status === 'pending') {
            $statusUpdates->broadcast($upload);
            $claimed = UploadTransfer::query()
                ->whereKey($upload->id)
                ->where('status', 'pending')
                ->update(['status' => 'processing', 'updated_at' => now()]);
            if ($claimed !== 1) {
                return;
            }
            $upload->refresh();
            $statusUpdates->broadcast($upload);
        } elseif ($upload->status !== 'processing') {
            return;
        }

        $disk = Storage::disk('local');
        $directory = $this->chunkDirectory($upload);
        $assembledPath = $directory.'/assembled.upload';
        $disk->makeDirectory($directory);
        $assembledAbsolutePath = $disk->path($assembledPath);
        $output = fopen($assembledAbsolutePath, 'wb');

        if ($output === false) {
            throw new \RuntimeException('Unable to create the private upload assembly file.');
        }

        try {
            for ($index = 0; $index < $upload->chunk_count; $index++) {
                $chunkPath = $directory.'/'.$index.'.part';
                $input = $disk->readStream($chunkPath);
                if (! is_resource($input)) {
                    throw new \RuntimeException('A required upload chunk is missing.');
                }

                try {
                    $expectedSize = min(
                        $upload->chunk_size,
                        $upload->total_size - ($index * $upload->chunk_size),
                    );
                    if (stream_copy_to_stream($input, $output) !== $expectedSize) {
                        throw new \RuntimeException('Unable to assemble a complete upload chunk.');
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
            throw new \RuntimeException('The assembled file size does not match the declared upload size.');
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($assembledAbsolutePath);
        if (! is_string($mimeType) || $mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        $assembledUpload = new UploadedFile($assembledAbsolutePath, $upload->original_name, $mimeType, UPLOAD_ERR_OK, true);
        try {
            if ($upload->attachment_purpose !== null) {
                $attachmentTypes->assertAllowedFile(
                    $upload->original_name,
                    $mimeType,
                    $upload->attachment_purpose,
                );
            }
            $uploads->assertWithinLimit($assembledUpload);
        } catch (ValidationException $exception) {
            $this->rejectUpload($upload, $exception, $directory, $statusUpdates);

            return;
        }

        $videoPreview = str_starts_with($mimeType, 'video/')
            ? $videoProcessor->process($assembledAbsolutePath, $upload->token)
            : null;

        try {
            $file = $uploads->store(
                $assembledUpload,
                null,
                'manual',
                $upload->admin ?? $upload->user,
            );
        } catch (Throwable $exception) {
            if ($videoPreview !== null) {
                Storage::disk(config('vault.upload_disk', 'vault'))->delete(array_filter([
                    $videoPreview['playable_path'],
                    $videoPreview['poster_path'],
                ]));
            }

            throw $exception;
        }

        try {
            if ($videoPreview !== null
                && ($videoPreview['playable_path'] !== null || $videoPreview['poster_path'] !== null || $videoPreview['duration'] !== null)) {
                $file->forceFill([
                    'video_playable_path' => $videoPreview['playable_path'],
                    'video_poster_path' => $videoPreview['poster_path'],
                    'video_duration' => $videoPreview['duration'],
                ])->save();
            }

            $updated = DB::transaction(function () use ($upload, $file, $attachments): bool {
                $current = UploadTransfer::query()->whereKey($upload->id)->lockForUpdate()->first();
                if (! $current || $current->status !== 'processing') {
                    return false;
                }

                $current->forceFill([
                    'status' => 'done',
                    'file_id' => $file->id,
                    'error_message' => null,
                ])->save();
                if ($current->attachment_purpose !== null && $current->committed_at !== null) {
                    $attachments->attach($current);
                }

                return true;
            });
            if (! $updated) {
                $this->deleteStoredFile($file);

                return;
            }
        } catch (Throwable $exception) {
            $this->deleteStoredFile($file);
            throw $exception;
        }

        $upload->refresh();
        $statusUpdates->broadcast($upload);
        $disk->deleteDirectory($directory);
    }

    public function failed(?Throwable $exception): void
    {
        $upload = UploadTransfer::query()->find($this->uploadTransferId);
        if (! $upload || in_array($upload->status, ['done', 'cancelled'], true)) {
            if ($upload?->status === 'cancelled') {
                Storage::disk('local')->deleteDirectory($this->chunkDirectory($upload));
            }

            return;
        }

        $upload->forceFill([
            'status' => 'failed',
            'error_message' => $this->safeErrorMessage($exception),
        ])->save();

        Storage::disk('local')->deleteDirectory($this->chunkDirectory($upload));
        app(UploadTransferStatus::class)->broadcast($upload);
    }

    private function chunkDirectory(UploadTransfer $upload): string
    {
        return 'upload-transfer-chunks/'.$upload->token;
    }

    private function deleteStoredFile(File $file): void
    {
        Storage::disk(config('vault.upload_disk', 'vault'))->delete(array_filter([
            $file->stored_path,
            $file->video_playable_path,
            $file->video_poster_path,
        ]));
        $file->delete();
    }

    private function rejectUpload(
        UploadTransfer $upload,
        ValidationException $exception,
        string $directory,
        UploadTransferStatus $statusUpdates,
    ): void {
        $message = collect($exception->errors())->flatten()->first() ?? 'The uploaded file was rejected.';
        $updated = UploadTransfer::query()
            ->whereKey($upload->id)
            ->where('status', 'processing')
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'updated_at' => now(),
            ]);

        Storage::disk('local')->deleteDirectory($directory);

        if ($updated === 1) {
            $upload->refresh();
            $statusUpdates->broadcast($upload);
        }
    }

    private function safeErrorMessage(?Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first() ?? 'The uploaded file was rejected.';
        }

        return 'The upload could not be finalized. Retry the upload or check the server logs.';
    }
}
