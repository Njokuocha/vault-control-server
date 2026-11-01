<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\File;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\PersonGalleryCategory;
use App\Models\PersonImage;
use App\Models\User;
use App\Models\VideoUpload;
use App\Services\ActivityLogger;
use App\Services\GalleryCategoryAccess;
use App\Services\ImageThumbnailService;
use App\Services\PublicRecordId;
use App\Services\RecordUploadAttachmentService;
use App\Services\ReportAccess;
use App\Services\SettingService;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class PersonController extends Controller
{
    private const TEXT_FIELDS = [
        'name', 'first_name', 'last_name', 'surname', 'age', 'date_of_birth',
        'marital_status', 'spouse', 'gender', 'citizenship', 'state',
        'blood_group', 'genotype', 'height', 'occupation', 'aliases',
        'last_known_location', 'identifying_marks', 'statement',
    ];

    public function index(): View
    {
        if (request()->routeIs('pm.*')) {
            return view('departments.pm.people-index');
        }

        return view('people.index');
    }

    public function create(): View
    {
        if (request()->routeIs('pm.*')) {
            return view('departments.pm.form', [
                'person' => new Person,
            ]);
        }

        return view('people.form', [
            'person' => new Person,
        ]);
    }

    public function store(Request $request, PublicRecordId $ids, RecordUploadAttachmentService $attachments): RedirectResponse|JsonResponse
    {
        $validated = $this->validatePerson($request);
        $actor = $request->user('admin') ?? $request->user('web');
        abort_unless($actor instanceof User || $actor instanceof Admin, 401);
        $mainImage = $request->file('main_image');
        $otherImages = $request->file('other_images', []);
        $storedPaths = [];

        try {
            $mainImagePath = $mainImage instanceof UploadedFile
                ? $this->storeImage($mainImage, 'people/main', $storedPaths)
                : null;
            $otherImagePaths = [];
            foreach ($otherImages as $index => $image) {
                if ($image instanceof UploadedFile) {
                    $otherImagePaths[$index] = $this->storeImage($image, 'people/gallery', $storedPaths);
                }
            }

            $person = DB::transaction(function () use ($validated, $mainImage, $otherImages, $mainImagePath, $otherImagePaths, $ids, $attachments, $actor, $request): Person {
                $attributes = $this->personAttributes($validated);
                $attributes['public_id'] = $ids->generate(Person::class, 'USR');
                $attributes['avatar_group'] = $validated['avatar_group'] ?? $this->avatarGroup($attributes['gender']);

                if ($mainImage instanceof UploadedFile) {
                    $attributes['main_image_path'] = $mainImagePath;
                    $attributes['main_image_file_id'] = null;
                }

                $person = Person::query()->create($attributes);

                foreach ($otherImagePaths as $index => $path) {
                    $person->images()->create([
                        'path' => $path,
                        'original_name' => $this->safeImageName($otherImages[$index]),
                    ]);
                }
                $this->attachVideosToPerson($person, $validated['video_upload_tokens'] ?? []);
                $attachments->commitPersonUploads(
                    $actor,
                    $person,
                    $validated['record_upload_draft_token'] ?? null,
                    $validated['main_image_upload_token'] ?? null,
                    $validated['gallery_upload_tokens'] ?? [],
                );
                app(ActivityLogger::class)->record(
                    'profile-management',
                    'person.created',
                    "Person profile {$person->public_id} was created.",
                    [
                        'person_id' => $person->getKey(),
                        'public_id' => $person->public_id,
                        'gallery_items_submitted' => count($otherImages) + count($validated['gallery_upload_tokens'] ?? []),
                        'has_primary_image' => $mainImage instanceof UploadedFile || ! empty($validated['main_image_upload_token']),
                    ],
                    $actor,
                    $request,
                );

                return $person;
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }

        $redirectUrl = route($this->routePrefix().'.show', $person);
        if ($request->expectsJson()) {
            return response()->json([
                'redirect_url' => $redirectUrl,
                'message' => 'Person profile created.',
                'record_id' => $person->getKey(),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Person profile created.');
    }

    public function show(
        Person $person,
        SettingService $settings,
        ReportAccess $reportAccess,
        GalleryCategoryAccess $galleryAccess,
    ): Response {
        request()->session()->forget('unlocked_person_images');
        $profileManagement = request()->routeIs('pm.*');
        $person = app(VersionedCache::class)->remember(
            'people',
            ($profileManagement ? 'pm:' : '').'profile:'.$person->getKey(),
            now()->addSeconds(30),
            fn (): Person => $profileManagement
                ? $person
                : $person->load(['cases' => fn ($query) => $query->latest()]),
        );
        $linkedReports = $reportAccess->visibleQuery()
            ->join('report_person', 'reports.id', '=', 'report_person.report_id')
            ->where('report_person.person_id', $person->id)
            ->select('reports.*')
            ->withCount('comments')
            ->latest('updated_at')
            ->orderByDesc('reports.id')
            ->paginate(10, ['reports.*'], 'linkedReportsPage');
        $visibleCategoryIds = $galleryAccess->visiblePersonCategoryIds($person->getKey(), request());
        $galleryCategories = $person->galleryCategories()->withCount('images')->get();
        $galleryCategories->each(function (PersonGalleryCategory $category) use ($visibleCategoryIds): void {
            $category->setAttribute('has_restricted_media', $category->images_count > 0);
            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->images_count = 0;
            }
        });
        $uncategorizedCount = $person->images()
            ->whereNull('gallery_category_id')
            ->whereDoesntHave('galleryCategories')
            ->count();
        $previewCategory = $galleryCategories->first(fn (PersonGalleryCategory $category): bool => $category->images_count > 0);
        $galleryPreviewRestricted = false;
        if (! $previewCategory && $uncategorizedCount === 0) {
            $previewCategory = $galleryCategories->first(fn (PersonGalleryCategory $category): bool => $category->has_restricted_media);
            $galleryPreviewRestricted = $previewCategory instanceof PersonGalleryCategory;
        }
        if ($galleryPreviewRestricted) {
            $galleryPreviewMedia = collect();
            $galleryPreviewCount = 0;
        } else {
            $galleryPreviewQuery = $person->images()->with(['file', 'galleryCategories'])->latest('id');
            if ($previewCategory instanceof PersonGalleryCategory) {
                $galleryPreviewQuery->whereHas('galleryCategories', fn ($query) => $query->whereKey($previewCategory->id));
                $galleryPreviewCount = $previewCategory->images_count;
            } else {
                $galleryPreviewQuery->whereNull('gallery_category_id')->whereDoesntHave('galleryCategories');
                $galleryPreviewCount = $uncategorizedCount;
            }
            $galleryPreviewMedia = $galleryPreviewQuery->limit(4)->get();
        }
        $avatarGroup = strtolower($person->avatar_group);
        $avatarPath = in_array($avatarGroup, ['male', 'female', 'group', 'unknown', 'suspect', 'verified'], true)
            ? $settings->get('avatar_model_'.$avatarGroup)
            : null;

        return response()->view('people.show', [
            'person' => $person,
            'linkedReports' => $linkedReports,
            'galleryPreviewMedia' => $galleryPreviewMedia,
            'galleryPreviewCategory' => $previewCategory,
            'galleryPreviewCount' => $galleryPreviewCount,
            'galleryPreviewRestricted' => $galleryPreviewRestricted,
            'galleryTotalCount' => $galleryCategories->sum('images_count') + $uncategorizedCount,
            'profileManagement' => $profileManagement,
            'routePrefix' => $this->routePrefix(),
            'canManageGalleryAccess' => $galleryAccess->isAdministrator(request()),
            'imagePinConfigured' => $settings->get('person_image_pin_hash') !== null,
            'avatarModelUrl' => is_string($avatarPath) && Storage::disk(config('vault.upload_disk'))->exists($avatarPath)
                ? route($profileManagement ? 'pm.avatar-models.stream' : 'avatar-models.stream', $avatarGroup)
                : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function gallery(Request $request, Person $person, GalleryCategoryAccess $galleryAccess): Response
    {
        $request->session()->put('unlocked_person_gallery', $person->getKey());
        if ((string) $request->session()->get('unlocked_person_images') !== (string) $person->getKey()) {
            $request->session()->forget('unlocked_person_images');
        }

        $categories = $person->galleryCategories()->withCount('images')->get();
        $visibleCategoryIds = $galleryAccess->visiblePersonCategoryIds($person->getKey(), $request);
        $categories->each(function (PersonGalleryCategory $category) use ($visibleCategoryIds): void {
            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->images_count = 0;
            }
        });
        $uncategorizedCount = $person->images()
            ->whereNull('gallery_category_id')
            ->whereDoesntHave('galleryCategories')
            ->count();
        $requestedCategory = $request->query('category');
        $selectedCategory = null;
        $selectedUncategorized = false;
        $categoryRestricted = false;

        if ($requestedCategory === 'uncategorized') {
            $selectedUncategorized = true;
        } elseif ($requestedCategory !== null) {
            abort_unless(is_string($requestedCategory) && ctype_digit($requestedCategory), 404);
            $selectedCategory = $categories->firstWhere('id', (int) $requestedCategory);
            abort_unless($selectedCategory instanceof PersonGalleryCategory, 404);
            $categoryRestricted = ! in_array($selectedCategory->getKey(), $visibleCategoryIds, true);
        } else {
            $selectedCategory = $categories->first(fn (PersonGalleryCategory $category): bool => $category->images_count > 0)
                ?? $categories->first(fn (PersonGalleryCategory $category): bool => in_array($category->getKey(), $visibleCategoryIds, true))
                ?? $categories->first();
            $categoryRestricted = $selectedCategory instanceof PersonGalleryCategory
                && ! in_array($selectedCategory->getKey(), $visibleCategoryIds, true);
            $selectedUncategorized = $selectedCategory === null || ($selectedCategory->images_count === 0 && $uncategorizedCount > 0);
            if ($selectedUncategorized) {
                $selectedCategory = null;
                $categoryRestricted = false;
            }
        }

        $mediaQuery = $person->images()->with(['file', 'galleryCategories'])->latest('id');
        if ($categoryRestricted) {
            $mediaQuery->whereRaw('1 = 0');
        } elseif ($selectedCategory instanceof PersonGalleryCategory) {
            $mediaQuery->whereHas('galleryCategories', fn ($query) => $query->whereKey($selectedCategory->id));
        } else {
            $mediaQuery->whereNull('gallery_category_id')->whereDoesntHave('galleryCategories');
        }

        return response()->view('people.gallery', [
            'person' => $person,
            'categories' => $categories,
            'uncategorizedCount' => $uncategorizedCount,
            'selectedCategory' => $selectedCategory,
            'categoryRestricted' => $categoryRestricted,
            'selectedUncategorized' => $selectedUncategorized,
            'visibleCategoryIds' => $visibleCategoryIds,
            'galleryMedia' => $mediaQuery->paginate(24)->withQueryString(),
            'galleryTotalCount' => $categories->sum('images_count') + $uncategorizedCount,
            'profileManagement' => request()->routeIs('pm.*'),
            'routePrefix' => $this->routePrefix(),
            'galleryMediaUnlocked' => $request->session()->get('unlocked_person_gallery') === $person->getKey(),
            'canManageGalleryAccess' => $galleryAccess->isAdministrator($request),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function createGalleryCategory(Request $request, Person $person): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $person->galleryCategories()->create([
            'title' => trim($validated['title']),
            'description' => trim($validated['description'] ?? ''),
            'sort_order' => (int) $person->galleryCategories()->max('sort_order') + 1,
        ]);

        return $this->galleryActionResponse($request, $person, request()->query('category'), 'Gallery category created.');
    }

    public function updateGalleryCategory(
        Request $request,
        Person $person,
        int $category,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $categoryModel = $person->galleryCategories()->whereKey($category)->firstOrFail();
        abort_unless($galleryAccess->canViewPersonCategory($categoryModel, $request), 403);
        $categoryModel->update([
            'title' => trim($validated['title']),
            'description' => trim($validated['description'] ?? ''),
        ]);

        return $this->galleryActionResponse($request, $person, (string) $category, 'Gallery category renamed.');
    }

    public function deleteGalleryCategory(
        Request $request,
        Person $person,
        int $category,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $categoryModel = $person->galleryCategories()->whereKey($category)->firstOrFail();
        abort_unless($galleryAccess->canViewPersonCategory($categoryModel, $request), 403);
        $categoryModel->delete();

        return $this->galleryActionResponse($request, $person, 'uncategorized', 'Gallery category deleted.');
    }

    public function assignGalleryImageCategory(
        Request $request,
        Person $person,
        int $image,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        if ($request->has('category_ids')) {
            $validated = $request->validate([
                'category_ids' => ['present', 'array'],
                'category_ids.*' => ['integer', 'distinct'],
            ]);
            $categoryIds = array_map('intval', $validated['category_ids']);
        } elseif ($request->expectsJson()) {
            $categoryIds = [];
        } else {
            $validated = $request->validate(['category_id' => ['nullable', 'integer']]);
            $categoryIds = isset($validated['category_id']) ? [(int) $validated['category_id']] : [];
        }

        $categories = $person->galleryCategories()->whereIn('id', $categoryIds)->get();
        abort_unless($categories->count() === count($categoryIds), 404);
        foreach ($categories as $category) {
            abort_unless($galleryAccess->canViewPersonCategory($category, $request), 403);
        }

        $media = $person->images()->whereKey($image)->firstOrFail();
        abort_unless($galleryAccess->canViewPersonImage($media, $request), 403);
        DB::transaction(function () use ($media, $categoryIds): void {
            $media->update(['gallery_category_id' => $categoryIds[0] ?? null]);
            $media->galleryCategories()->sync($categoryIds);
        });

        $currentCategory = request()->query('category');
        $redirectCategory = $currentCategory !== null && in_array((int) $currentCategory, $categoryIds, true)
            ? $currentCategory
            : ($categoryIds[0] ?? 'uncategorized');

        return $this->galleryActionResponse($request, $person, (string) $redirectCategory, 'Media categories updated.');
    }

    private function galleryActionResponse(
        Request $request,
        Person $person,
        ?string $category,
        string $message,
    ): RedirectResponse|JsonResponse {
        $parameters = ['person' => $person];
        if ($category !== null) {
            $parameters['category'] = $category;
        }
        $redirectUrl = route($this->routePrefix().'.gallery', $parameters);

        if ($request->expectsJson()) {
            return response()->json(['redirect_url' => $redirectUrl, 'message' => $message]);
        }

        return redirect()->to($redirectUrl)->with('status', $message);
    }

    public function edit(Person $person): View
    {
        if (request()->routeIs('pm.*')) {
            return view('departments.pm.form', [
                'person' => $person,
            ]);
        }

        return view('people.form', [
            'person' => $person,
        ]);
    }

    public function update(Request $request, Person $person, RecordUploadAttachmentService $attachments): RedirectResponse|JsonResponse
    {
        $validated = $this->validatePerson($request);
        $actor = $request->user('admin') ?? $request->user('web');
        abort_unless($actor instanceof User || $actor instanceof Admin, 401);
        $mainImage = $request->file('main_image');
        $otherImages = $request->file('other_images', []);
        $storedPaths = [];
        $previousMainImage = $person->main_image_path;
        $removeMainImage = (bool) ($validated['remove_main_image'] ?? false);

        try {
            $mainImagePath = $mainImage instanceof UploadedFile
                ? $this->storeImage($mainImage, 'people/main', $storedPaths)
                : null;
            $otherImagePaths = [];
            foreach ($otherImages as $index => $image) {
                if ($image instanceof UploadedFile) {
                    $otherImagePaths[$index] = $this->storeImage($image, 'people/gallery', $storedPaths);
                }
            }

            DB::transaction(function () use ($validated, $mainImage, $otherImages, $mainImagePath, $otherImagePaths, $person, $removeMainImage, $attachments, $actor, $request): void {
                $attributes = $this->personAttributes($validated);
                $attributes['avatar_group'] = $validated['avatar_group'] ?? $this->avatarGroup($attributes['gender']);

                if ($mainImage instanceof UploadedFile) {
                    $attributes['main_image_path'] = $mainImagePath;
                    $attributes['main_image_file_id'] = null;
                } elseif ($removeMainImage) {
                    $attributes['main_image_path'] = null;
                    $attributes['main_image_file_id'] = null;
                }

                $person->update($attributes);

                foreach ($otherImagePaths as $index => $path) {
                    $person->images()->create([
                        'path' => $path,
                        'original_name' => $this->safeImageName($otherImages[$index]),
                    ]);
                }
                $this->attachVideosToPerson($person, $validated['video_upload_tokens'] ?? []);
                $attachments->commitPersonUploads(
                    $actor,
                    $person,
                    $validated['record_upload_draft_token'] ?? null,
                    $validated['main_image_upload_token'] ?? null,
                    $validated['gallery_upload_tokens'] ?? [],
                );
                app(ActivityLogger::class)->record(
                    'profile-management',
                    'person.updated',
                    "Person profile {$person->public_id} was updated.",
                    [
                        'person_id' => $person->getKey(),
                        'public_id' => $person->public_id,
                        'changed_fields' => array_keys($person->getChanges()),
                        'gallery_items_submitted' => count($otherImages) + count($validated['gallery_upload_tokens'] ?? []),
                        'has_primary_image' => $mainImage instanceof UploadedFile || ! empty($validated['main_image_upload_token']),
                    ],
                    $actor,
                    $request,
                );
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }

        if (($mainImage instanceof UploadedFile || $removeMainImage) && $previousMainImage) {
            Storage::disk(config('vault.upload_disk'))->delete($previousMainImage);
        }

        $redirectUrl = route($this->routePrefix().'.show', $person);
        if ($request->expectsJson()) {
            return response()->json([
                'redirect_url' => $redirectUrl,
                'message' => 'Person profile updated.',
                'record_id' => $person->getKey(),
            ]);
        }

        return redirect()->to($redirectUrl)->with('status', 'Person profile updated.');
    }

    public function authenticateImages(Request $request, Person $person, SettingService $settings): JsonResponse
    {
        $validated = $request->validate(['pin' => ['required', 'digits:4']]);
        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $failureKey = 'person-image-pin:'.hash('sha256', implode('|', [
            $actor::class,
            (string) $actor->getAuthIdentifier(),
        ]));
        $pinHash = $settings->get('person_image_pin_hash');
        $pinIsValid = is_string($pinHash) && Hash::check($validated['pin'], $pinHash);

        if ($pinIsValid) {
            RateLimiter::clear($failureKey);
            $request->session()->put('unlocked_person_images', $person->getKey());
            if ((string) $request->session()->get('unlocked_person_gallery') !== (string) $person->getKey()) {
                $request->session()->forget('unlocked_person_gallery');
            }

            return response()->json(['status' => 'unlocked']);
        }

        if (RateLimiter::tooManyAttempts($failureKey, 8)) {
            return response()->json([
                'message' => 'Too many incorrect PIN attempts. Please try again shortly.',
                'retry_after' => RateLimiter::availableIn($failureKey),
            ], 429);
        }

        RateLimiter::hit($failureKey, 60);

        return response()->json([
            'message' => is_string($pinHash) ? 'The PIN is incorrect.' : 'No person image PIN has been configured.',
        ], 422);
    }

    public function image(
        Person $person,
        string $image,
        GalleryCategoryAccess $galleryAccess,
        bool $download = false,
        bool $thumbnail = false,
    ): mixed {
        if ($image === 'main') {
            abort_unless(request()->session()->get('unlocked_person_images') === $person->getKey(), 403);
        } else {
            abort_unless($this->hasUnlockedPersonMedia($person), 403);
        }

        if ($image !== 'main') {
            $media = $person->images()->whereKey($image)->firstOrFail();
            abort_unless($galleryAccess->canViewPersonImage($media, request()), 403);
            if ($media->type === 'video') {
                $file = $media->file;
                abort_unless($file instanceof File, 404);

                return $download
                    ? app(FileController::class)->download($file)
                    : ($media->playable_path
                        ? app(FileController::class)->videoRendition($file)
                        : app(FileController::class)->stream(request(), $file));
            }
            if (in_array($media->type, ['audio', 'document'], true)) {
                $file = $media->file;
                abort_unless($file instanceof File, 404);

                return app(FileController::class)->download($file);
            }
            $path = $media->path;
        } else {
            $file = $person->mainImageFile;
            if ($file instanceof File) {
                abort_unless($file->category === 'image', 404);

                if ($thumbnail) {
                    return $this->privateImagePreview($file->stored_path, $file->original_name);
                }

                return $download
                    ? app(FileController::class)->download($file)
                    : app(FileController::class)->stream(request(), $file);
            }
            $path = $person->main_image_path;
        }

        abort_unless(is_string($path) && Storage::disk(config('vault.upload_disk'))->exists($path), 404);

        if ($thumbnail) {
            return $this->privateImagePreview($path, basename($path));
        }

        $response = app(FileController::class)->streamPrivatePath(
            request(),
            $path,
            null,
            basename($path),
            $download,
        );
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    private function privateImagePreview(string $sourcePath, string $fileName): mixed
    {
        $disk = Storage::disk(config('vault.upload_disk'));
        $thumbnailPath = app(ImageThumbnailService::class)->thumbnailPath($sourcePath);
        $path = $disk->exists($thumbnailPath) ? $thumbnailPath : $sourcePath;

        $response = app(FileController::class)->streamPrivatePath(
            request(),
            $path,
            $path === $thumbnailPath ? 'image/jpeg' : null,
            $fileName,
        );
        $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

        return $response;
    }

    public function imagePoster(Person $person, PersonImage $image, GalleryCategoryAccess $galleryAccess): mixed
    {
        abort_unless($this->hasUnlockedPersonMedia($person), 403);
        abort_unless($image->person_id === $person->id && $image->type === 'video', 404);
        abort_unless($galleryAccess->canViewPersonImage($image, request()), 403);
        $disk = Storage::disk(config('vault.upload_disk'));
        $posterPath = collect([$image->poster_path, $image->file?->video_poster_path])
            ->first(fn ($path): bool => is_string($path) && $disk->exists($path));
        abort_unless(is_string($posterPath), 404);

        $fileName = pathinfo($image->original_name, PATHINFO_FILENAME).'-poster.jpg';

        return app(FileController::class)->streamPrivatePath(request(), $posterPath, 'image/jpeg', $fileName);
    }

    private function hasUnlockedPersonMedia(Person $person): bool
    {
        $session = request()->session();

        return $session->get('unlocked_person_images') === $person->getKey()
            || $session->get('unlocked_person_gallery') === $person->getKey();
    }

    public function destroyImage(Person $person, PersonImage $image, GalleryCategoryAccess $galleryAccess): JsonResponse
    {
        abort_unless($image->person_id === $person->id, 404);
        abort_unless($galleryAccess->canViewPersonImage($image, request()), 403);
        $file = $image->file;
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

        return response()->json(['status' => 'deleted']);
    }

    public function linkableCases(Request $request, Person $person): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $cases = InvestigationCase::query()
            ->whereDoesntHave('people', fn ($query) => $query->whereKey($person->id))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('public_id', 'like', '%'.$search.'%')
                        ->orWhere('title', 'like', '%'.$search.'%')
                        ->orWhere('classification', 'like', '%'.$search.'%');
                });
            })
            ->latest()
            ->limit(20)
            ->get(['id', 'public_id', 'title', 'classification', 'case_status']);

        return response()->json(['cases' => $cases]);
    }

    public function attachCase(Request $request, Person $person): JsonResponse
    {
        $validated = $request->validate([
            'case_id' => ['required', 'integer', 'exists:case_files,id'],
        ]);
        $person->cases()->syncWithoutDetaching([$validated['case_id']]);
        app(VersionedCache::class)->bump('people');
        app(VersionedCache::class)->bump('cases');
        app(\App\Support\CaseAnalysisCache::class)->invalidate((int) $validated['case_id']);

        return response()->json(['status' => 'linked']);
    }

    public function detachCase(Person $person, InvestigationCase $case): JsonResponse
    {
        $person->cases()->detach($case->id);
        app(VersionedCache::class)->bump('people');
        app(VersionedCache::class)->bump('cases');
        app(\App\Support\CaseAnalysisCache::class)->invalidate((int) $case->getKey());

        return response()->json(['status' => 'unlinked']);
    }

    private function validatePerson(Request $request): array
    {
        $rules = [];
        foreach (self::TEXT_FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.($field === 'statement' || $field === 'identifying_marks' ? 20000 : 255)];
        }

        return $request->validate([
            ...$rules,
            'flag' => ['nullable', Rule::in(['Alive', 'Deceased', 'Wanted', 'Unknown'])],
            'avatar_group' => ['nullable', Rule::in(['Male', 'Female', 'Group', 'Unknown', 'Suspect', 'Verified'])],
            'main_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'other_images' => ['nullable', 'array', 'max:40'],
            'other_images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'video_upload_tokens' => ['nullable', 'array', 'max:20'],
            'video_upload_tokens.*' => ['required', 'uuid', 'distinct', 'exists:video_uploads,token'],
            'record_upload_draft_token' => ['nullable', 'uuid'],
            'main_image_upload_token' => ['nullable', 'uuid'],
            'gallery_upload_tokens' => ['nullable', 'array', 'max:40'],
            'gallery_upload_tokens.*' => ['required', 'uuid', 'distinct'],
            'remove_main_image' => ['nullable', 'boolean'],
        ]);
    }

    private function routePrefix(): string
    {
        return request()->routeIs('pm.*') ? 'pm.people' : 'people';
    }

    private function attachVideosToPerson(Person $person, array $tokens): void
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
                'video_upload_tokens' => 'One or more selected videos are not available to attach.',
            ]);
        }

        foreach ($uploads as $upload) {
            $person->images()->create([
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

    private function personAttributes(array $validated): array
    {
        $attributes = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = trim((string) ($validated[$field] ?? ''));
            $attributes[$field] = $value === '' ? 'Unknown' : $value;
        }

        $attributes['flag'] = $validated['flag'] ?? 'Unknown';

        if ($attributes['name'] === 'Unknown') {
            $nameParts = array_filter([
                $attributes['first_name'],
                $attributes['last_name'],
                $attributes['surname'],
            ], fn (string $value): bool => $value !== 'Unknown');
            $attributes['name'] = $nameParts === [] ? 'Unknown' : implode(' ', $nameParts);
        }

        return $attributes;
    }

    private function avatarGroup(string $gender): string
    {
        return match (strtolower($gender)) {
            'male' => 'Male',
            'female' => 'Female',
            'group' => 'Group',
            default => 'Unknown',
        };
    }

    private function storeImage(UploadedFile $image, string $directory, array &$storedPaths): string
    {
        $extension = strtolower($image->guessExtension() ?: 'img');
        $path = $image->storeAs($directory, Str::uuid().'.'.$extension, config('vault.upload_disk'));
        if (! is_string($path)) {
            throw new \RuntimeException('Unable to store the uploaded image.');
        }

        $storedPaths[] = $path;

        return $path;
    }

    private function safeImageName(UploadedFile $image): string
    {
        return Str::limit(basename(str_replace('\\', '/', $image->getClientOriginalName())), 255, '');
    }
}
