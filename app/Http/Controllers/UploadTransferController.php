<?php

namespace App\Http\Controllers;

use App\Jobs\FinalizeUploadTransferJob;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Services\FileUploadService;
use App\Services\RecordUploadAttachmentService;
use App\Services\UploadTransferStatus;
use App\Support\ChunkUploadSize;
use App\Support\RecordAttachmentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UploadTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $transfers = $user->uploadTransfers();
        $totalUploads = (clone $transfers)->count();
        $pendingUploads = (clone $transfers)->whereIn('status', ['uploading', 'pending', 'processing'])->count();
        $active = (clone $transfers)
            ->whereIn('status', ['uploading', 'pending', 'processing'])
            ->latest('id')
            ->with(['file:id,uuid,extension,mime_type', 'user:id,department'])
            ->get();
        $recent = (clone $transfers)
            ->whereNotIn('status', ['uploading', 'pending', 'processing'])
            ->latest('id')
            ->limit(100)
            ->with(['file:id,uuid,extension,mime_type', 'user:id,department'])
            ->get();

        return response()->json([
            'total_uploads' => $totalUploads,
            'pending_uploads' => $pendingUploads,
            'uploads' => $active->concat($recent)->map(fn (UploadTransfer $upload): array => $this->serialize($upload))->values(),
        ]);
    }

    public function initialize(
        Request $request,
        FileUploadService $uploads,
        ChunkUploadSize $chunkSize,
        RecordUploadAttachmentService $attachments,
        RecordAttachmentTypes $attachmentTypes,
    ): JsonResponse {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'attachment' => ['nullable', 'array'],
            'attachment.purpose' => ['required_with:attachment', Rule::in([
                RecordAttachmentTypes::PERSON_MAIN_IMAGE,
                RecordAttachmentTypes::PERSON_GALLERY,
                RecordAttachmentTypes::CASE_EVIDENCE,
            ])],
            'attachment.record_id' => ['nullable', 'integer', 'min:1'],
            'attachment.draft_token' => ['nullable', 'uuid'],
            'attachment.target_id' => ['nullable', 'integer', 'min:1'],
            'attachment.target_key' => ['nullable', 'uuid'],
            'attachment.commit_immediately' => ['nullable', 'boolean'],
        ]);
        $uploads->assertUploadsAllowed();

        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $attachmentIntent = isset($validated['attachment'])
            ? $attachments->validateIntent($user, $validated['attachment'])
            : [];
        if ($attachmentIntent === []) {
            $attachments->assertStandaloneUploadAllowed($user);
        }
        if ($attachmentIntent !== []) {
            $attachmentTypes->assertAllowedExtension($validated['name'], $attachmentIntent['attachment_purpose']);
        }

        $size = (int) $validated['size'];
        if ($size > $uploads->maximumBytes()) {
            return response()->json(['message' => 'The file exceeds the configured maximum upload size.'], 413);
        }

        $effectiveChunkSize = $chunkSize->bytes();
        $chunkCount = (int) ceil($size / $effectiveChunkSize);
        if ($chunkCount > 10000) {
            return response()->json(['message' => 'The file requires too many upload chunks.'], 422);
        }

        $upload = $user->uploadTransfers()->create([
            ...$attachmentIntent,
            'token' => (string) Str::uuid(),
            'original_name' => $this->safeOriginalName($validated['name']),
            'total_size' => $size,
            'chunk_size' => $effectiveChunkSize,
            'chunk_count' => $chunkCount,
            'status' => 'uploading',
            'committed_at' => (bool) ($validated['attachment']['commit_immediately'] ?? false) ? now() : null,
            'expires_at' => now()->addDay(),
        ]);

        app(UploadTransferStatus::class)->broadcast($upload);

        return response()->json($this->transferUrls($upload), 201);
    }

    public function storeChunk(Request $request, string $token, int $index): JsonResponse
    {
        $upload = $this->ownedTransfer($request, $token);
        abort_unless($upload->status === 'uploading' && $upload->expires_at->isFuture(), 410);
        abort_if($index < 0 || $index >= $upload->chunk_count, 404);

        $expectedSize = min(
            $upload->chunk_size,
            $upload->total_size - ($index * $upload->chunk_size),
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
        $directory = 'upload-transfer-chunks/'.$upload->token;
        $chunkPath = $directory.'/'.$index.'.part';
        $incomingPath = $chunk->getRealPath();
        abort_unless(is_string($incomingPath), 422, 'The uploaded chunk is unavailable.');

        if ($disk->exists($chunkPath)) {
            $this->assertSameChunk($disk->path($chunkPath), $incomingPath);
        } else {
            $disk->makeDirectory($directory);
            $target = fopen($disk->path($chunkPath), 'xb');
            if ($target === false) {
                if (! $disk->exists($chunkPath)) {
                    throw new \RuntimeException('Unable to create a private upload chunk.');
                }
                $this->assertSameChunk($disk->path($chunkPath), $incomingPath);
            } else {
                $incoming = fopen($incomingPath, 'rb');
                $copyError = null;
                try {
                    if ($incoming === false || stream_copy_to_stream($incoming, $target) !== $expectedSize) {
                        throw new \RuntimeException('Unable to store a complete upload chunk.');
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

        $receivedChunks = $this->receivedChunks($upload);
        if (count($receivedChunks) !== $upload->chunk_count) {
            return response()->json([
                'status' => 'uploading',
                'received_chunks' => $receivedChunks,
                'chunk_count' => $upload->chunk_count,
            ]);
        }

        $claimed = UploadTransfer::query()
            ->whereKey($upload->id)
            ->where('user_id', $upload->user_id)
            ->where('status', 'uploading')
            ->update(['status' => 'pending', 'updated_at' => now()]);

        if ($claimed === 1) {
            $upload->refresh();
            FinalizeUploadTransferJob::dispatch($upload->id)->afterCommit();
        }

        return response()->json(['status' => 'pending']);
    }

    public function status(Request $request, string $token): JsonResponse
    {
        return response()->json($this->serialize($this->ownedTransfer($request, $token)));
    }

    public function cancel(Request $request, string $token): JsonResponse
    {
        $upload = $this->ownedTransfer($request, $token);
        $updated = UploadTransfer::query()
            ->whereKey($upload->id)
            ->where('user_id', $upload->user_id)
            ->whereIn('status', ['uploading', 'pending'])
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        if ($updated !== 1) {
            throw new ConflictHttpException('This upload can no longer be cancelled.');
        }

        Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$upload->token);
        $upload->refresh();
        app(UploadTransferStatus::class)->broadcast($upload);

        return response()->json(['status' => 'cancelled']);
    }

    public function retry(Request $request, string $token): JsonResponse
    {
        $upload = $this->ownedTransfer($request, $token);
        $updated = DB::transaction(function () use ($upload): int {
            return UploadTransfer::query()
                ->whereKey($upload->id)
                ->where('user_id', $upload->user_id)
                ->whereIn('status', ['failed', 'cancelled'])
                ->update([
                    'status' => 'uploading',
                    'error_message' => null,
                    'file_id' => null,
                    'expires_at' => now()->addDay(),
                    'updated_at' => now(),
                ]);
        });

        if ($updated !== 1) {
            throw new ConflictHttpException('This upload is not eligible for retry.');
        }

        Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$upload->token);
        $upload->refresh();
        app(UploadTransferStatus::class)->broadcast($upload);

        return response()->json($this->transferUrls($upload));
    }

    public function remove(Request $request, string $token): JsonResponse
    {
        $upload = $this->ownedTransfer($request, $token)->loadMissing('file');
        if (in_array($upload->status, ['uploading', 'pending', 'processing'], true)) {
            throw new ConflictHttpException('An active upload cannot be removed.');
        }

        $upload->delete();
        app(UploadTransferStatus::class)->broadcast($upload, 'removed');

        return response()->json(['status' => 'removed']);
    }

    private function ownedTransfer(Request $request, string $token): UploadTransfer
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->uploadTransfers()->where('token', $token)->firstOrFail();
    }

    private function serialize(UploadTransfer $upload): array
    {
        $file = $upload->file;
        $previewUrl = $file?->uuid ? route('files.preview', ['file' => $file->uuid]) : null;
        if (
            $file?->uuid
            && $upload->attachment_purpose === RecordAttachmentTypes::CASE_EVIDENCE
            && $upload->user?->department === 'cfm'
            && $upload->attached_at !== null
            && $upload->record_id !== null
        ) {
            $previewUrl = route('cfm.cases.evidence-preview', [
                'caseFile' => $upload->record_id,
                'file' => $file->uuid,
            ]);
        }
        $receivedChunks = $upload->status === 'uploading' ? $this->receivedChunks($upload) : [];
        $receivedBytes = array_sum(array_map(
            fn (int $index): int => min($upload->chunk_size, $upload->total_size - ($index * $upload->chunk_size)),
            $receivedChunks,
        ));
        $progress = in_array($upload->status, ['pending', 'processing', 'done'], true)
            ? 100
            : ($upload->status === 'uploading' && $upload->total_size > 0
                ? (int) floor(($receivedBytes / $upload->total_size) * 100)
                : 0);
        $finished = in_array($upload->status, ['done', 'failed', 'cancelled'], true);

        return [
            'id' => $upload->getKey(),
            'token' => $upload->token,
            'name' => $upload->original_name,
            'size' => $upload->total_size,
            'file_type' => $file?->extension ?? pathinfo($upload->original_name, PATHINFO_EXTENSION),
            'file_mime_type' => $file?->mime_type,
            'preview_url' => $previewUrl,
            'uploaded_at' => $upload->created_at?->toIso8601String(),
            'duration_seconds' => $finished && $upload->created_at && $upload->updated_at
                ? max(0, $upload->updated_at->getTimestamp() - $upload->created_at->getTimestamp())
                : null,
            'status' => $upload->status,
            'progress' => $progress,
            'chunk_size' => $upload->chunk_size,
            'chunk_count' => $upload->chunk_count,
            'received_chunks' => $receivedChunks,
            'status_url' => route('upload-transfers.status', $upload->token),
            'chunk_url' => route('upload-transfers.chunk', ['token' => $upload->token, 'index' => '__INDEX__']),
            'cancel_url' => route('upload-transfers.cancel', $upload->token),
            'retry_url' => route('upload-transfers.retry', $upload->token),
            'remove_url' => route('upload-transfers.remove', $upload->token),
            'file_uuid' => $upload->file?->uuid,
            'error' => $upload->error_message,
            'updated_at' => $upload->updated_at?->toIso8601String(),
            'attachment_purpose' => $upload->attachment_purpose,
            'draft_token' => $upload->draft_token,
            'attachment_record_id' => $upload->record_id,
            'attachment_target_id' => $upload->target_id,
            'attachment_target_key' => $upload->target_key,
            'attached_at' => $upload->attached_at?->toIso8601String(),
        ];
    }

    private function transferUrls(UploadTransfer $upload): array
    {
        return [
            'id' => $upload->getKey(),
            'token' => $upload->token,
            'chunk_size' => $upload->chunk_size,
            'chunk_count' => $upload->chunk_count,
            'received_chunks' => [],
            'status' => $upload->status,
            'uploaded_at' => $upload->created_at?->toIso8601String(),
            'status_url' => route('upload-transfers.status', $upload->token),
            'chunk_url' => route('upload-transfers.chunk', ['token' => $upload->token, 'index' => '__INDEX__']),
            'cancel_url' => route('upload-transfers.cancel', $upload->token),
            'retry_url' => route('upload-transfers.retry', $upload->token),
            'remove_url' => route('upload-transfers.remove', $upload->token),
        ];
    }

    /**
     * @return list<int>
     */
    private function receivedChunks(UploadTransfer $upload): array
    {
        $directory = 'upload-transfer-chunks/'.$upload->token;
        $chunks = [];

        foreach (Storage::disk('local')->files($directory) as $path) {
            if (preg_match('/\/(\d+)\.part$/', $path, $matches) === 1) {
                $index = (int) $matches[1];
                if ($index >= 0 && $index < $upload->chunk_count) {
                    $chunks[] = $index;
                }
            }
        }

        sort($chunks);

        return array_values(array_unique($chunks));
    }

    private function assertSameChunk(string $existingPath, string $incomingPath): void
    {
        abort_unless(
            filesize($existingPath) === filesize($incomingPath)
            && hash_file('sha256', $existingPath) === hash_file('sha256', $incomingPath),
            409,
            'This chunk index has already been uploaded with different content.',
        );
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return Str::limit(trim($name) ?: 'unnamed-file', 255, '');
    }
}
