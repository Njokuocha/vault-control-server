<?php

namespace App\Http\Controllers;

use App\Events\VideoUploadStatusChanged;
use App\Jobs\FinalizeVideoUploadJob;
use App\Models\VideoUpload;
use App\Services\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VideoUploadController extends Controller
{
    public function initialize(Request $request, FileUploadService $uploads): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);
        $uploads->assertUploadsAllowed();

        $size = (int) $validated['size'];
        if ($size > $uploads->maximumBytes()) {
            return response()->json(['message' => 'The video exceeds the configured maximum upload size.'], 413);
        }

        $chunkSize = $this->effectiveChunkSize();
        $chunkCount = (int) ceil($size / $chunkSize);
        if ($chunkCount > 10000) {
            return response()->json(['message' => 'The video requires too many upload chunks.'], 422);
        }

        $originalName = $this->safeOriginalName($validated['name']);
        $videoUpload = VideoUpload::query()->create([
            'token' => (string) Str::uuid(),
            'original_name' => $originalName,
            'total_size' => $size,
            'chunk_size' => $chunkSize,
            'chunk_count' => $chunkCount,
            'status' => 'uploading',
            'expires_at' => now()->addDay(),
        ]);

        return response()->json([
            'token' => $videoUpload->token,
            'chunk_size' => $videoUpload->chunk_size,
            'chunk_count' => $videoUpload->chunk_count,
            'status_url' => route('video-uploads.status', $videoUpload),
            'chunk_url' => route('video-uploads.chunk', ['videoUpload' => $videoUpload, 'index' => '__INDEX__']),
            'cancel_url' => route('video-uploads.cancel', $videoUpload),
            'retry_url' => route('video-uploads.retry', $videoUpload),
        ], 201);
    }

    public function storeChunk(Request $request, VideoUpload $videoUpload, int $index): JsonResponse
    {
        abort_unless($videoUpload->status === 'uploading' && $videoUpload->expires_at->isFuture(), 410);
        abort_if($index < 0 || $index >= $videoUpload->chunk_count, 404);

        $expectedSize = min(
            $videoUpload->chunk_size,
            $videoUpload->total_size - ($index * $videoUpload->chunk_size),
        );
        $validated = $request->validate([
            'chunk' => ['required', 'file', 'max:'.max(1, (int) ceil($expectedSize / 1024))],
        ]);
        /** @var UploadedFile $chunk */
        $chunk = $validated['chunk'];
        if ($chunk->getSize() !== $expectedSize) {
            return response()->json(['message' => 'The uploaded chunk has an unexpected size.'], 422);
        }

        $disk = Storage::disk('local');
        $directory = 'vault-chunks/'.$videoUpload->token;
        $chunkPath = $directory.'/'.$index.'.part';

        if ($disk->exists($chunkPath)) {
            $existingPath = $disk->path($chunkPath);
            $incomingPath = $chunk->getRealPath();
            abort_unless(
                is_string($incomingPath)
                && filesize($existingPath) === filesize($incomingPath)
                && hash_file('sha256', $existingPath) === hash_file('sha256', $incomingPath),
                409,
                'This chunk index has already been uploaded with different content.',
            );
        } else {
            $disk->makeDirectory($directory);
            $target = fopen($disk->path($chunkPath), 'xb');
            if ($target === false) {
                if (! $disk->exists($chunkPath)) {
                    throw new \RuntimeException('Unable to create a video upload chunk privately.');
                }
                $existingPath = $disk->path($chunkPath);
                $incomingPath = $chunk->getRealPath();
                abort_unless(
                    is_string($incomingPath)
                    && filesize($existingPath) === filesize($incomingPath)
                    && hash_file('sha256', $existingPath) === hash_file('sha256', $incomingPath),
                    409,
                    'This chunk index has already been uploaded with different content.',
                );
            } else {
                $incoming = fopen((string) $chunk->getRealPath(), 'rb');
                $copyError = null;
                try {
                    if ($incoming === false || stream_copy_to_stream($incoming, $target) !== $expectedSize) {
                        throw new \RuntimeException('Unable to store a complete video upload chunk.');
                    }
                } catch (\Throwable $exception) {
                    $copyError = $exception;
                } finally {
                    if (is_resource($incoming)) {
                        fclose($incoming);
                    }
                    fclose($target);
                }
                if ($copyError !== null) {
                    $disk->delete($chunkPath);
                    throw $copyError;
                }
            }
        }

        $receivedCount = count(array_filter(
            $disk->files($directory),
            static fn (string $path): bool => preg_match('/\/\d+\.part$/', $path) === 1,
        ));
        if ($receivedCount !== $videoUpload->chunk_count) {
            return response()->json([
                'status' => 'uploading',
                'received_chunks' => $receivedCount,
                'chunk_count' => $videoUpload->chunk_count,
            ]);
        }

        $claimed = VideoUpload::query()
            ->whereKey($videoUpload->id)
            ->where('status', 'uploading')
            ->update(['status' => 'processing', 'updated_at' => now()]);

        if ($claimed === 1) {
            event(new VideoUploadStatusChanged($videoUpload->token, 'processing', 100));
            FinalizeVideoUploadJob::dispatch($videoUpload->id);
        }

        return response()->json(['status' => 'processing']);
    }

    public function status(VideoUpload $videoUpload): JsonResponse
    {
        if ($videoUpload->status === 'uploading') {
            $disk = Storage::disk('local');
            $directory = 'vault-chunks/'.$videoUpload->token;
            $received = 0;
            for ($index = 0; $index < $videoUpload->chunk_count; $index++) {
                $received += $disk->exists($directory.'/'.$index.'.part') ? 1 : 0;
            }
            $progress = (int) floor(($received / $videoUpload->chunk_count) * 100);
        } else {
            $progress = in_array($videoUpload->status, ['processing', 'completed'], true) ? 100 : 0;
        }

        return response()->json([
            'token' => $videoUpload->token,
            'name' => $videoUpload->original_name,
            'size' => $videoUpload->total_size,
            'status' => $videoUpload->status,
            'progress' => $progress,
            'file_uuid' => $videoUpload->file?->uuid,
            'error' => $videoUpload->error_message,
        ]);
    }

    public function cancel(VideoUpload $videoUpload): JsonResponse
    {
        $updated = VideoUpload::query()
            ->whereKey($videoUpload->id)
            ->whereIn('status', ['uploading', 'processing'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new ConflictHttpException('This video upload can no longer be cancelled.');
        }

        Storage::disk('local')->deleteDirectory('vault-chunks/'.$videoUpload->token);
        event(new VideoUploadStatusChanged($videoUpload->token, 'cancelled', 0));

        return response()->json(['status' => 'cancelled']);
    }

    public function retry(VideoUpload $videoUpload): JsonResponse
    {
        $updated = DB::transaction(function () use ($videoUpload): int {
            return VideoUpload::query()
                ->whereKey($videoUpload->id)
                ->whereIn('status', ['failed', 'cancelled'])
                ->where('expires_at', '>', now())
                ->update([
                    'status' => 'uploading',
                    'error_message' => null,
                    'updated_at' => now(),
                ]);
        });

        if ($updated !== 1) {
            throw new ConflictHttpException('This video upload is not eligible for retry.');
        }

        Storage::disk('local')->deleteDirectory('vault-chunks/'.$videoUpload->token);
        event(new VideoUploadStatusChanged($videoUpload->token, 'uploading', 0));

        return response()->json([
            'token' => $videoUpload->token,
            'status' => 'uploading',
            'chunk_size' => $videoUpload->chunk_size,
            'chunk_count' => $videoUpload->chunk_count,
        ]);
    }

    private function effectiveChunkSize(): int
    {
        $configured = max(1, (int) config('vault.chunk_size_mb', 5)) * 1024 * 1024;
        $uploadLimit = $this->phpLimitBytes('upload_max_filesize');
        $postLimit = $this->phpLimitBytes('post_max_size');
        if ($postLimit > 0 && $postLimit <= 262144) {
            throw new \RuntimeException('PHP post_max_size is too small to accept a video upload chunk.');
        }
        $limits = array_filter([
            $configured,
            $uploadLimit > 0 ? $uploadLimit : null,
            $postLimit > 0 ? $postLimit - 262144 : null,
        ]);
        $chunkSize = (int) min($limits);

        if ($chunkSize < 1) {
            throw new \RuntimeException('The PHP request limits do not allow a video upload chunk.');
        }

        return $chunkSize;
    }

    private function phpLimitBytes(string $setting): int
    {
        $value = (string) ini_get($setting);

        return function_exists('ini_parse_quantity') ? max(0, (int) ini_parse_quantity($value)) : 0;
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit(trim($name) ?: 'video-upload', 255, '');
    }
}
