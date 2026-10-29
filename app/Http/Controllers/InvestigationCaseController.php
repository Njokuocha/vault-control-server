<?php

namespace App\Http\Controllers;

use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\File;
use App\Models\InvestigationCase;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\ActivityLogger;
use App\Services\PublicRecordId;
use App\Services\RecordUploadAttachmentService;
use App\Services\SettingService;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class InvestigationCaseController extends Controller
{
    public function index(): View
    {
        return view('cases.index');
    }

    public function create(SettingService $settings): View
    {
        return view('cases.form', [
            'caseFile' => new InvestigationCase,
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
        ]);
    }

    public function store(
        Request $request,
        PublicRecordId $ids,
        RecordUploadAttachmentService $attachments,
    ): RedirectResponse|JsonResponse {
        $validated = $this->validateCase($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storedPaths = [];

        try {
            $caseFile = DB::transaction(function () use ($validated, $ids, $attachments, $user, $request, &$storedPaths): InvestigationCase {
                $caseFile = InvestigationCase::query()->create([
                    'created_by_user_id' => $user->getKey(),
                    'public_id' => $ids->generate(InvestigationCase::class, 'CSE'),
                    'title' => $this->unknown($validated['title'] ?? null),
                    'classification' => $validated['classification'] ?? 'CLASSIFIED',
                    'case_status' => $validated['case_status'] ?? 'OPEN',
                    'narrative' => $this->unknown($validated['narrative'] ?? null),
                    'comment' => $this->unknown($validated['comment'] ?? null),
                ]);
                $this->saveCategories(
                    $caseFile,
                    $validated['categories'] ?? [],
                    $storedPaths,
                    $attachments,
                    $user,
                    $validated['record_upload_draft_token'] ?? null,
                );
                app(ActivityLogger::class)->record(
                    'case-management',
                    'case.created',
                    "Case file {$caseFile->public_id} was created.",
                    [
                        'case_id' => $caseFile->getKey(),
                        'public_id' => $caseFile->public_id,
                        'case_status' => $caseFile->case_status,
                        'classification' => $caseFile->classification,
                        'category_count' => $caseFile->categories()->count(),
                    ],
                    $user,
                    $request,
                );

                return $caseFile;
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }

        $routePrefix = $request->routeIs('cfm.*') ? 'cfm.' : '';
        $redirectUrl = route($routePrefix.'cases.show', $caseFile);
        if ($request->expectsJson()) {
            return response()->json([
                'redirect_url' => $redirectUrl,
                'message' => 'Case file created.',
                'record_id' => $caseFile->getKey(),
                'upload_targets' => $this->uploadTargetIds($caseFile, $validated['categories'] ?? []),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Case file created.');
    }

    public function show(InvestigationCase $caseFile): View
    {
        $isCaseManager = request()->routeIs('cfm.*');
        $relations = ['categories.images', 'creator'];
        if (! $isCaseManager) {
            $relations[] = 'people';
        }

        $caseFile = app(VersionedCache::class)->remember(
            'cases',
            'record:'.($isCaseManager ? 'cfm:' : '').$caseFile->getKey(),
            now()->addSeconds(30),
            fn (): InvestigationCase => $caseFile->load($relations),
        );

        return view('cases.show', [
            'caseFile' => $caseFile,
            'galleryMediaCount' => $caseFile->categories->sum(fn ($category) => $category->images->count()),
        ]);
    }

    public function edit(InvestigationCase $caseFile, SettingService $settings): View
    {
        $caseFile->load('categories.images');

        return view('cases.form', [
            'caseFile' => $caseFile,
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
        ]);
    }

    public function update(
        Request $request,
        InvestigationCase $caseFile,
        RecordUploadAttachmentService $attachments,
    ): RedirectResponse|JsonResponse {
        $validated = $this->validateCase($request);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $storedPaths = [];
        $deletedPaths = [];
        $deletedFileIds = [];
        $deletedUploadIds = [];
        $deletedTransferTokens = [];
        try {
            DB::transaction(function () use ($validated, $caseFile, $attachments, $user, $request, &$storedPaths, &$deletedPaths, &$deletedFileIds, &$deletedUploadIds, &$deletedTransferTokens): void {
                $caseFile->update([
                    'title' => $this->unknown($validated['title'] ?? null),
                    'classification' => $validated['classification'] ?? 'CLASSIFIED',
                    'case_status' => $validated['case_status'] ?? 'OPEN',
                    'narrative' => $this->unknown($validated['narrative'] ?? null),
                    'comment' => $this->unknown($validated['comment'] ?? null),
                ]);
                foreach ($validated['delete_categories'] ?? [] as $categoryId) {
                    $category = $caseFile->categories()->with('images')->whereKey($categoryId)->first();
                    if ($category === null) {
                        continue;
                    }
                    $deletedPaths = [...$deletedPaths, ...$category->images->pluck('path')->all()];
                    $deletedFileIds = [...$deletedFileIds, ...$category->images->pluck('file_id')->filter()->all()];
                    $transfers = UploadTransfer::query()
                        ->where('record_type', 'case')
                        ->where('record_id', $caseFile->getKey())
                        ->where('target_id', $category->getKey())
                        ->lockForUpdate()
                        ->get();
                    $deletedFileIds = [...$deletedFileIds, ...$transfers->pluck('file_id')->filter()->all()];
                    $deletedTransferTokens = [...$deletedTransferTokens, ...$transfers->pluck('token')->all()];
                    $transfers->each->delete();
                    $category->delete();
                }
                $deletedFileIds = array_values(array_unique($deletedFileIds));
                if ($deletedFileIds !== []) {
                    $deletedUploadIds = VideoUpload::query()->whereIn('file_id', $deletedFileIds)->pluck('id')->all();
                }
                $this->saveCategories(
                    $caseFile,
                    $validated['categories'] ?? [],
                    $storedPaths,
                    $attachments,
                    $user,
                    $validated['record_upload_draft_token'] ?? null,
                );
                app(ActivityLogger::class)->record(
                    'case-management',
                    'case.updated',
                    "Case file {$caseFile->public_id} was updated.",
                    [
                        'case_id' => $caseFile->getKey(),
                        'public_id' => $caseFile->public_id,
                        'changed_fields' => array_keys($caseFile->getChanges()),
                        'case_status' => $caseFile->case_status,
                        'classification' => $caseFile->classification,
                        'category_count' => $caseFile->categories()->count(),
                    ],
                    $user,
                    $request,
                );
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }
        Storage::disk(config('vault.upload_disk'))->delete($deletedPaths);
        if ($deletedFileIds !== []) {
            File::query()->whereIn('id', $deletedFileIds)->get()->each(
                static fn (File $file) => $file->delete(),
            );
        }
        if ($deletedUploadIds !== []) {
            VideoUpload::query()->whereIn('id', $deletedUploadIds)->delete();
        }
        foreach ($deletedTransferTokens as $token) {
            Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$token);
        }

        $routePrefix = $request->routeIs('cfm.*') ? 'cfm.' : '';
        $redirectUrl = route($routePrefix.'cases.show', $caseFile);
        if ($request->expectsJson()) {
            return response()->json([
                'redirect_url' => $redirectUrl,
                'message' => 'Case file updated.',
                'record_id' => $caseFile->getKey(),
                'upload_targets' => $this->uploadTargetIds($caseFile, $validated['categories'] ?? []),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Case file updated.');
    }

    public function destroy(InvestigationCase $caseFile): RedirectResponse
    {
        $caseReference = $caseFile->public_id;
        $caseId = $caseFile->getKey();
        $fileIds = collect();
        $paths = collect();
        $chunkTokens = collect();

        DB::transaction(function () use ($caseFile, $caseReference, $caseId, &$fileIds, &$paths, &$chunkTokens): void {
            $caseFile->load('categories.images.file');
            $images = $caseFile->categories->flatMap(fn (CaseCategory $category) => $category->images);
            $transfers = UploadTransfer::query()
                ->where('record_type', 'case')
                ->where('record_id', $caseFile->getKey())
                ->lockForUpdate()
                ->get();

            $fileIds = $images->pluck('file_id')
                ->merge($transfers->pluck('file_id'))
                ->filter()
                ->unique()
                ->values();
            $paths = $images->pluck('path')
                ->merge(File::query()->whereKey($fileIds)->pluck('stored_path'))
                ->filter()
                ->unique()
                ->values();
            $chunkTokens = $transfers->pluck('token')->values();

            foreach ($images as $image) {
                $image->delete();
            }
            foreach ($caseFile->categories as $category) {
                $category->delete();
            }
            $caseFile->delete();
            $transfers->each->delete();
            VideoUpload::query()->whereIn('file_id', $fileIds)->delete();
            app(ActivityLogger::class)->record(
                'case-management',
                'case.deleted',
                "Case file {$caseReference} was deleted by an administrator.",
                [
                    'case_id' => $caseId,
                    'public_id' => $caseReference,
                    'evidence_file_count' => $fileIds->count(),
                ],
            );
        });

        Storage::disk(config('vault.upload_disk'))->delete($paths->all());
        foreach (File::query()->whereKey($fileIds)->get() as $file) {
            $file->delete();
        }
        foreach ($chunkTokens as $token) {
            Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$token);
        }

        return redirect()->route('cases.index')->with('status', 'Case file deleted.');
    }

    public function image(InvestigationCase $caseFile, CaseImage $image, bool $download = false): mixed
    {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        if ($image->type === 'video') {
            $file = $image->file;
            abort_unless($file instanceof File, 404);

            return $download
                ? app(FileController::class)->download($file)
                : ($image->playable_path
                    ? app(FileController::class)->videoRendition($file)
                    : app(FileController::class)->stream(request(), $file));
        }
        if (in_array($image->type, ['audio', 'document'], true)) {
            $file = $image->file;
            abort_unless($file instanceof File, 404);

            return app(FileController::class)->download($file);
        }
        abort_unless(Storage::disk(config('vault.upload_disk'))->exists($image->path), 404);

        $response = app(FileController::class)->streamPrivatePath(
            request(),
            $image->path,
            null,
            $image->original_name,
            $download,
        );
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    public function previewEvidence(InvestigationCase $caseFile, File $file): JsonResponse
    {
        $image = $this->evidenceImage($caseFile, $file);
        $preview = app(FileController::class)->preview($file)->getData(true);
        $preview['stream_url'] = route('cfm.cases.evidence-stream', ['caseFile' => $caseFile, 'file' => $file->uuid]);
        $preview['download_url'] = route('cfm.cases.images.download', ['caseFile' => $caseFile, 'image' => $image]);

        return response()->json($preview);
    }

    public function streamEvidence(Request $request, InvestigationCase $caseFile, File $file): mixed
    {
        $image = $this->evidenceImage($caseFile, $file);
        if ($image->type === 'video' && $image->playable_path) {
            return app(FileController::class)->videoRendition($file);
        }

        return app(FileController::class)->stream($request, $file);
    }

    public function imagePoster(InvestigationCase $caseFile, CaseImage $image): mixed
    {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        abort_unless($image->type === 'video', 404);
        $file = $image->file;
        abort_unless($file instanceof File, 404);

        return app(FileController::class)->videoPoster($file);
    }

    public function destroyImage(InvestigationCase $caseFile, CaseImage $image): JsonResponse
    {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        $file = $image->file;
        $transferTokens = $image->file_id === null
            ? collect()
            : UploadTransfer::query()->where('file_id', $image->file_id)->pluck('token');
        $videoUploadId = $file
            ? VideoUpload::query()->where('file_id', $file->id)->value('id')
            : null;
        Storage::disk(config('vault.upload_disk'))->delete($image->path);
        $image->delete();
        if ($file) {
            $file->delete();
            if ($videoUploadId !== null) {
                VideoUpload::query()->whereKey($videoUploadId)->delete();
            }
        }
        UploadTransfer::query()->whereIn('token', $transferTokens)->delete();
        foreach ($transferTokens as $token) {
            Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$token);
        }

        return response()->json(['status' => 'deleted']);
    }

    private function validateCase(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'classification' => ['nullable', Rule::in(['CLASSIFIED', 'HIGHLY SENSITIVE', 'ACCESSIBLE'])],
            'case_status' => ['nullable', Rule::in(['OPEN', 'UNDER REVIEW', 'CLOSED'])],
            'narrative' => ['nullable', 'string', 'max:50000'],
            'comment' => ['nullable', 'string', 'max:20000'],
            'record_upload_draft_token' => ['nullable', 'uuid'],
            'categories' => ['nullable', 'array', 'max:30'],
            'categories.*.id' => ['nullable', 'integer', 'exists:case_categories,id'],
            'categories.*.title' => ['nullable', 'string', 'max:255'],
            'categories.*.description' => ['nullable', 'string', 'max:5000'],
            'categories.*.images' => ['nullable', 'array', 'max:40'],
            'categories.*.images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'categories.*.video_upload_tokens' => ['nullable', 'array', 'max:20'],
            'categories.*.video_upload_tokens.*' => ['required', 'uuid', 'distinct', 'exists:video_uploads,token'],
            'categories.*.upload_target_key' => ['nullable', 'uuid'],
            'categories.*.upload_transfer_tokens' => ['nullable', 'array', 'max:40'],
            'categories.*.upload_transfer_tokens.*' => ['required', 'uuid', 'distinct'],
            'delete_categories' => ['nullable', 'array'],
            'delete_categories.*' => ['integer', 'exists:case_categories,id'],
        ]);
    }

    private function saveCategories(
        InvestigationCase $caseFile,
        array $categories,
        array &$storedPaths,
        RecordUploadAttachmentService $attachments,
        User $user,
        ?string $draftToken,
    ): void {
        foreach ($categories as $index => $categoryData) {
            $attributes = [
                'title' => $this->unknown($categoryData['title'] ?? null),
                'description' => $this->unknown($categoryData['description'] ?? null),
                'sort_order' => $index,
            ];
            $category = isset($categoryData['id'])
                ? $caseFile->categories()->whereKey($categoryData['id'])->firstOrFail()
                : $caseFile->categories()->make();
            $category->fill($attributes);
            $caseFile->categories()->save($category);

            foreach ($categoryData['images'] ?? [] as $image) {
                if (! $image instanceof UploadedFile) {
                    continue;
                }

                $extension = strtolower($image->guessExtension() ?: 'img');
                $path = $image->storeAs('cases/'.$caseFile->id.'/evidence', Str::uuid().'.'.$extension, config('vault.upload_disk'));
                if (! is_string($path)) {
                    throw new \RuntimeException('Unable to store the case evidence image.');
                }
                $storedPaths[] = $path;
                $category->images()->create([
                    'path' => $path,
                    'original_name' => Str::limit(basename(str_replace('\\', '/', $image->getClientOriginalName())), 255, ''),
                ]);
            }

            $this->attachVideosToCategory($category, $categoryData['video_upload_tokens'] ?? []);
            $transferTokens = $categoryData['upload_transfer_tokens'] ?? [];
            if ($transferTokens !== []) {
                $attachments->commitCaseUploads(
                    $user,
                    $caseFile,
                    $draftToken,
                    array_map(
                        fn (string $token): array => [
                            'token' => $token,
                            'category_id' => $category->getKey(),
                            'target_key' => $categoryData['upload_target_key'] ?? null,
                        ],
                        $transferTokens,
                    ),
                );
            }
        }
    }

    private function attachVideosToCategory(CaseCategory $category, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        $uploads = VideoUpload::query()
            ->whereIn('token', $tokens)
            ->where('status', 'completed')
            ->whereNull('attached_at')
            ->with('file')
            ->lockForUpdate()
            ->get();

        if ($uploads->count() !== count($tokens) || $uploads->contains(
            fn (VideoUpload $upload): bool => ! $upload->file || $upload->file->category !== 'video'
        )) {
            throw ValidationException::withMessages([
                'categories' => 'One or more selected videos are not available to attach.',
            ]);
        }

        foreach ($uploads as $upload) {
            $category->images()->create([
                'path' => $upload->file->stored_path,
                'original_name' => $upload->file->original_name,
                'type' => 'video',
                'file_id' => $upload->file_id,
                'poster_path' => $upload->file->video_poster_path,
                'playable_path' => $upload->file->video_playable_path,
                'duration' => $upload->file->video_duration,
            ]);
            $upload->forceFill(['attached_at' => now()])->save();
        }
    }

    private function uploadTargetIds(InvestigationCase $caseFile, array $categories): array
    {
        $categoryIds = $caseFile->categories()->pluck('id', 'sort_order');
        $targets = [];
        foreach ($categories as $index => $category) {
            $targetKey = $category['upload_target_key'] ?? null;
            $categoryId = $categoryIds->get($index);
            if (is_string($targetKey) && $categoryId !== null) {
                $targets[$targetKey] = $categoryId;
            }
        }

        return $targets;
    }

    private function unknown(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'Unknown' : $value;
    }

    private function evidenceImage(InvestigationCase $caseFile, File $file): CaseImage
    {
        return CaseImage::query()
            ->where('file_id', $file->getKey())
            ->whereHas('category', fn ($query) => $query->where('case_file_id', $caseFile->getKey()))
            ->firstOrFail();
    }
}
