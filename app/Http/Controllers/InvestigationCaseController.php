<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\File;
use App\Models\InvestigationCase;
use App\Models\UploadTransfer;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\ActivityLogger;
use App\Services\GalleryCategoryAccess;
use App\Services\ImageThumbnailService;
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
        $actor = $request->user('admin') ?? $request->user('web');
        abort_unless($actor instanceof User || $actor instanceof Admin, 401);
        $uploadOwner = $actor;
        $storedPaths = [];

        try {
            $categoryImagePaths = $this->storeCategoryImages($validated['categories'] ?? [], $storedPaths);
            $caseFile = DB::transaction(function () use ($validated, $categoryImagePaths, $ids, $attachments, $actor, $uploadOwner, $request): InvestigationCase {
                $caseFile = InvestigationCase::query()->create([
                    'created_by_user_id' => $actor instanceof User ? $actor->getKey() : null,
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
                    $categoryImagePaths,
                    $attachments,
                    $uploadOwner,
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
                    $actor,
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
        $relations = [
            'categories.images.file',
            'categories.images.categories',
            'categories.assignedImages.file',
            'categories.assignedImages.categories',
            'creator',
        ];
        if (! $isCaseManager) {
            $relations[] = 'people';
        }

        $caseFile = app(VersionedCache::class)->remember(
            'cases',
            'record:'.($isCaseManager ? 'cfm:' : '').$caseFile->getKey(),
            now()->addSeconds(30),
            fn (): InvestigationCase => $caseFile->load($relations),
        );
        $caseFile->loadMissing($relations);
        foreach ($caseFile->categories as $category) {
            $images = $category->images
                ->merge($category->assignedImages)
                ->unique('id')
                ->sortBy('id')
                ->values();
            $images->loadMissing(['file', 'categories']);
            $category->setRelation('images', $images);
        }

        return view('cases.show', [
            'caseFile' => $caseFile,
            'galleryMediaCount' => $caseFile->categories->sum(fn ($category) => $category->images->count()),
        ]);
    }

    public function gallery(Request $request, InvestigationCase $caseFile, GalleryCategoryAccess $galleryAccess): View
    {
        $categories = $caseFile->categories()
            ->withCount('assignedImages')
            ->get();
        $visibleCategoryIds = $galleryAccess->visibleCaseCategoryIds($caseFile->getKey(), $request);
        $categories->each(function (CaseCategory $category) use ($visibleCategoryIds): void {
            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->assigned_images_count = 0;
            }
        });
        $requestedCategory = $request->query('category');
        $categoryRestricted = false;

        if ($requestedCategory !== null) {
            abort_unless(is_string($requestedCategory) && ctype_digit($requestedCategory), 404);
            $selectedCategory = $categories->firstWhere('id', (int) $requestedCategory);
            abort_unless($selectedCategory instanceof CaseCategory, 404);
            $categoryRestricted = ! in_array($selectedCategory->getKey(), $visibleCategoryIds, true);
        } else {
            $selectedCategory = $categories->first(fn (CaseCategory $category): bool => $category->assigned_images_count > 0)
                ?? $categories->first(fn (CaseCategory $category): bool => in_array($category->getKey(), $visibleCategoryIds, true))
                ?? $categories->first();
            $categoryRestricted = $selectedCategory instanceof CaseCategory
                && ! in_array($selectedCategory->getKey(), $visibleCategoryIds, true);
        }

        $galleryMedia = $selectedCategory instanceof CaseCategory && ! $categoryRestricted
            ? $selectedCategory->assignedImages()->with(['file', 'categories'])->latest('case_images.id')->paginate(24)->withQueryString()
            : CaseImage::query()->whereRaw('1 = 0')->paginate(24);

        return view('cases.gallery', [
            'caseFile' => $caseFile,
            'categories' => $categories,
            'selectedCategory' => $selectedCategory,
            'categoryRestricted' => $categoryRestricted,
            'visibleCategoryIds' => $visibleCategoryIds,
            'galleryMedia' => $galleryMedia,
            'routePrefix' => request()->routeIs('cfm.*') ? 'cfm.' : '',
            'canManageGalleryAccess' => $galleryAccess->isAdministrator($request),
        ]);
    }

    public function createCategory(Request $request, InvestigationCase $caseFile): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $category = $caseFile->categories()->create([
            'title' => trim($validated['title']),
            'description' => trim($validated['description'] ?? ''),
            'sort_order' => (int) $caseFile->categories()->max('sort_order') + 1,
        ]);

        return $this->caseCategoryActionResponse($request, $caseFile, 'Evidence category created.', $category->getKey());
    }

    public function updateCategory(
        Request $request,
        InvestigationCase $caseFile,
        int $category,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $categoryModel = $caseFile->categories()->whereKey($category)->firstOrFail();
        abort_unless($galleryAccess->canViewCaseCategory($categoryModel, $request), 403);
        $categoryModel->update([
            'title' => trim($validated['title']),
            'description' => trim($validated['description'] ?? ''),
        ]);

        return $this->caseCategoryActionResponse($request, $caseFile, 'Evidence category updated.', $categoryModel->getKey());
    }

    public function deleteCategory(
        Request $request,
        InvestigationCase $caseFile,
        int $category,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $categoryModel = $caseFile->categories()->whereKey($category)->firstOrFail();
        abort_unless($galleryAccess->canViewCaseCategory($categoryModel, $request), 403);
        $replacement = $caseFile->categories()->where('id', '!=', $categoryModel->getKey())->first();
        $transfers = UploadTransfer::query()
            ->where('record_type', 'case')
            ->where('record_id', $caseFile->getKey())
            ->where('target_id', $categoryModel->getKey());

        if ($replacement === null && ($categoryModel->images()->exists() || $categoryModel->assignedImages()->exists() || $transfers->exists())) {
            throw ValidationException::withMessages([
                'category' => 'Create another evidence category before deleting the final category containing media or pending uploads.',
            ]);
        }

        DB::transaction(function () use ($categoryModel, $replacement, $transfers): void {
            $images = $categoryModel->images()
                ->with('categories')
                ->get()
                ->merge($categoryModel->assignedImages()->with('categories')->get())
                ->unique('id');

            foreach ($images as $image) {
                $categoryIds = $image->categories->modelKeys();
                if (! in_array($categoryModel->getKey(), $categoryIds, true)) {
                    $categoryIds[] = $categoryModel->getKey();
                }
                $categoryIds = array_values(array_filter(
                    $categoryIds,
                    fn (int $id): bool => $id !== $categoryModel->getKey(),
                ));
                if ($categoryIds === [] && $replacement !== null) {
                    $categoryIds[] = $replacement->getKey();
                }

                $image->categories()->sync($categoryIds);
                if (! in_array($image->case_category_id, $categoryIds, true) && $categoryIds !== []) {
                    $image->update(['case_category_id' => $categoryIds[0]]);
                }
            }

            if ($replacement !== null) {
                $transfers->update(['target_id' => $replacement->getKey()]);
            }
            $categoryModel->delete();
        });

        return $this->caseCategoryActionResponse($request, $caseFile, 'Evidence category deleted.');
    }

    public function assignImageCategories(
        Request $request,
        InvestigationCase $caseFile,
        int $image,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'category_ids' => ['required', 'array', 'min:1'],
            'category_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $categoryIds = array_map('intval', $validated['category_ids']);
        $categories = $caseFile->categories()->whereIn('id', $categoryIds)->get();
        abort_unless($categories->count() === count($categoryIds), 404);
        foreach ($categories as $category) {
            abort_unless($galleryAccess->canViewCaseCategory($category, $request), 403);
        }
        $media = CaseImage::query()
            ->whereKey($image)
            ->whereHas('category', fn ($query) => $query->where('case_file_id', $caseFile->getKey()))
            ->firstOrFail();
        abort_unless($galleryAccess->canViewCaseImage($media, $request), 403);
        $existingCategoryIds = $media->categories()->pluck('case_categories.id')->map(fn ($id): int => (int) $id)->all();
        $membershipChanged = array_diff($existingCategoryIds, $categoryIds) !== []
            || array_diff($categoryIds, $existingCategoryIds) !== [];
        $primaryCategoryChanged = ! in_array((int) $media->case_category_id, $categoryIds, true);

        DB::transaction(function () use ($media, $categoryIds, $membershipChanged, $primaryCategoryChanged): void {
            if ($primaryCategoryChanged) {
                $media->update(['case_category_id' => $categoryIds[0]]);
            }
            $media->categories()->sync($categoryIds);
            if ($membershipChanged && ! $primaryCategoryChanged) {
                app(VersionedCache::class)->bumpAfterCommit('cases');
            }
        });

        return $this->caseCategoryActionResponse($request, $caseFile, 'Evidence categories updated.', $categoryIds[0]);
    }

    private function caseCategoryActionResponse(
        Request $request,
        InvestigationCase $caseFile,
        string $message,
        ?int $categoryId = null,
    ): RedirectResponse|JsonResponse {
        $routePrefix = $request->routeIs('cfm.*') ? 'cfm.' : '';
        $parameters = ['caseFile' => $caseFile];
        if ($categoryId !== null) {
            $parameters['category'] = $categoryId;
        }
        $redirectUrl = route($routePrefix.'cases.gallery', $parameters);

        if ($request->expectsJson()) {
            return response()->json(['redirect_url' => $redirectUrl, 'message' => $message]);
        }

        return redirect()->to($redirectUrl)->with('status', $message);
    }

    public function edit(InvestigationCase $caseFile, SettingService $settings): View
    {
        $caseFile->load('categories.images.file');

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
        $actor = $request->user('admin') ?? $request->user('web');
        abort_unless($actor instanceof User || $actor instanceof Admin, 401);
        $uploadOwner = $actor;
        $storedPaths = [];
        $deletedPaths = [];
        $deletedThumbnailPaths = [];
        $deletedFileIds = [];
        $deletedUploadIds = [];
        $deletedTransferTokens = [];
        try {
            $categoryImagePaths = $this->storeCategoryImages($validated['categories'] ?? [], $storedPaths);
            DB::transaction(function () use ($validated, $categoryImagePaths, $caseFile, $attachments, $actor, $uploadOwner, $request, &$deletedPaths, &$deletedThumbnailPaths, &$deletedFileIds, &$deletedUploadIds, &$deletedTransferTokens): void {
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
                    foreach ($category->images as $image) {
                        if (in_array($image->type, [null, 'image'], true)) {
                            $deletedThumbnailPaths[] = app(ImageThumbnailService::class)->thumbnailPath($image->path);
                        }
                    }
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
                    $categoryImagePaths,
                    $attachments,
                    $uploadOwner,
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
                    $actor,
                    $request,
                );
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }
        $disk = Storage::disk(config('vault.upload_disk'));
        $existingThumbnails = array_filter($deletedThumbnailPaths, fn (string $path): bool => $disk->exists($path));
        $disk->delete([...$deletedPaths, ...$existingThumbnails]);
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
        $thumbnailPaths = collect();
        $chunkTokens = collect();

        DB::transaction(function () use ($caseFile, $caseReference, $caseId, &$fileIds, &$paths, &$thumbnailPaths, &$chunkTokens): void {
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
            $thumbnailPaths = $images
                ->filter(fn (CaseImage $image): bool => in_array($image->type, [null, 'image'], true))
                ->map(fn (CaseImage $image): string => app(ImageThumbnailService::class)->thumbnailPath($image->path))
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

        $disk = Storage::disk(config('vault.upload_disk'));
        $existingThumbnails = $thumbnailPaths->filter(fn (string $path): bool => $disk->exists($path))->all();
        $disk->delete([...$paths->all(), ...$existingThumbnails]);
        foreach (File::query()->whereKey($fileIds)->get() as $file) {
            $file->delete();
        }
        foreach ($chunkTokens as $token) {
            Storage::disk('local')->deleteDirectory('upload-transfer-chunks/'.$token);
        }

        return redirect()->route('cases.index')->with('status', 'Case file deleted.');
    }

    public function image(
        InvestigationCase $caseFile,
        CaseImage $image,
        GalleryCategoryAccess $galleryAccess,
        bool $download = false,
        bool $thumbnail = false,
    ): mixed {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        abort_unless($galleryAccess->canViewCaseImage($image, request()), 403);
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

            return $download
                ? app(FileController::class)->download($file)
                : app(FileController::class)->stream(request(), $file);
        }
        abort_unless(Storage::disk(config('vault.upload_disk'))->exists($image->path), 404);

        $path = $image->path;
        if ($thumbnail) {
            $thumbnailPath = app(ImageThumbnailService::class)->thumbnailPath($path);
            if (Storage::disk(config('vault.upload_disk'))->exists($thumbnailPath)) {
                $path = $thumbnailPath;
            }
        }

        $response = app(FileController::class)->streamPrivatePath(
            request(),
            $path,
            $path === $image->path ? null : 'image/jpeg',
            $image->original_name,
            $download,
        );
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    public function previewEvidence(
        InvestigationCase $caseFile,
        File $file,
        GalleryCategoryAccess $galleryAccess,
    ): JsonResponse {
        $image = $this->evidenceImage($caseFile, $file);
        abort_unless($galleryAccess->canViewCaseImage($image, request()), 403);
        $preview = app(FileController::class)->preview($file)->getData(true);
        $routePrefix = request()->routeIs('cfm.*') ? 'cfm.' : '';
        $preview['stream_url'] = route($routePrefix.'cases.evidence-stream', ['caseFile' => $caseFile, 'file' => $file->uuid]);
        $preview['download_url'] = route($routePrefix.'cases.images.download', ['caseFile' => $caseFile, 'image' => $image]);

        return response()->json($preview);
    }

    public function streamEvidence(
        Request $request,
        InvestigationCase $caseFile,
        File $file,
        GalleryCategoryAccess $galleryAccess,
    ): mixed {
        $image = $this->evidenceImage($caseFile, $file);
        abort_unless($galleryAccess->canViewCaseImage($image, $request), 403);
        if ($image->type === 'video' && $image->playable_path) {
            return app(FileController::class)->videoRendition($file);
        }

        return app(FileController::class)->stream($request, $file);
    }

    public function imagePoster(
        InvestigationCase $caseFile,
        CaseImage $image,
        GalleryCategoryAccess $galleryAccess,
    ): mixed {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        abort_unless($galleryAccess->canViewCaseImage($image, request()), 403);
        abort_unless($image->type === 'video', 404);
        $disk = Storage::disk(config('vault.upload_disk'));
        $posterPath = collect([$image->poster_path, $image->file?->video_poster_path])
            ->first(fn ($path): bool => is_string($path) && $disk->exists($path));
        abort_unless(is_string($posterPath), 404);

        $fileName = pathinfo($image->original_name, PATHINFO_FILENAME).'-poster.jpg';

        return app(FileController::class)->streamPrivatePath(request(), $posterPath, 'image/jpeg', $fileName);
    }

    public function destroyImage(
        InvestigationCase $caseFile,
        CaseImage $image,
        GalleryCategoryAccess $galleryAccess,
    ): JsonResponse {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        abort_unless($galleryAccess->canViewCaseImage($image, request()), 403);
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
        array $categoryImagePaths,
        RecordUploadAttachmentService $attachments,
        User|Admin $user,
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

            foreach ($categoryData['images'] ?? [] as $imageIndex => $image) {
                if (! $image instanceof UploadedFile) {
                    continue;
                }

                $category->images()->create([
                    'path' => $categoryImagePaths[$index][$imageIndex],
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

    private function storeCategoryImages(array $categories, array &$storedPaths): array
    {
        $paths = [];
        foreach ($categories as $categoryIndex => $categoryData) {
            foreach ($categoryData['images'] ?? [] as $imageIndex => $image) {
                if (! $image instanceof UploadedFile) {
                    continue;
                }

                $extension = strtolower($image->guessExtension() ?: 'img');
                $path = $image->storeAs('cases/evidence', Str::uuid().'.'.$extension, config('vault.upload_disk'));
                if (! is_string($path)) {
                    throw new \RuntimeException('Unable to store the case evidence image.');
                }

                $storedPaths[] = $path;
                $paths[$categoryIndex][$imageIndex] = $path;
            }
        }

        return $paths;
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
