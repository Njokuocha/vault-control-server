<?php

namespace App\Http\Controllers;

use App\Models\StaffGalleryCategory;
use App\Models\StaffGalleryImage;
use App\Models\User;
use App\Services\GalleryCategoryAccess;
use App\Services\ImageThumbnailService;
use App\Services\SettingService;
use App\Services\StaffMediaAccess;
use App\Support\RecordAttachmentTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class StaffGalleryController extends Controller
{
    public function gallery(
        Request $request,
        User $user,
        GalleryCategoryAccess $galleryAccess,
        StaffMediaAccess $mediaAccess,
        SettingService $settings,
    ): Response|RedirectResponse {
        $this->assertStaffRecord($user);
        $requestedToken = $request->query('auth');

        if ($requestedToken === null) {
            $activeToken = $mediaAccess->activeToken($request, $user->getKey());
            if ($activeToken !== null) {
                return redirect()->to(route($this->galleryRoutePrefix().'.index', [
                    'user' => $user,
                    'auth' => $activeToken,
                    ...$request->query->all(),
                ]));
            }

            return redirect()->to(route($this->galleryRoutePrefix().'.index', [
                'user' => $user,
                'auth' => 'unauthed',
                ...$request->query->all(),
            ]));
        }

        if (! $mediaAccess->allows($request, $user->getKey(), is_string($requestedToken) ? $requestedToken : null)) {
            return response()->view('staff.gallery-locked', [
                'staff' => $user,
                'routePrefix' => $this->staffRoutePrefix(),
                'galleryRoutePrefix' => $this->galleryRoutePrefix(),
                'pinConfigured' => $settings->get('person_image_pin_hash') !== null,
            ])->header('Cache-Control', 'no-store, private')
                ->header('Referrer-Policy', 'no-referrer');
        }

        $authToken = $requestedToken;
        $categories = $user->staffGalleryCategories()->withCount('images')->get();
        $visibleCategoryIds = $galleryAccess->visibleStaffCategoryIds($user->getKey(), $request);
        $categories->each(function (StaffGalleryCategory $category) use ($visibleCategoryIds): void {
            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->images_count = 0;
            }
        });

        $uncategorizedCount = $user->staffGalleryImages()
            ->whereDoesntHave('categories')
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
            abort_unless($selectedCategory instanceof StaffGalleryCategory, 404);
            $categoryRestricted = ! in_array($selectedCategory->getKey(), $visibleCategoryIds, true);
        } else {
            $selectedCategory = $categories->first(fn (StaffGalleryCategory $category): bool => $category->images_count > 0)
                ?? $categories->first(fn (StaffGalleryCategory $category): bool => in_array($category->getKey(), $visibleCategoryIds, true))
                ?? $categories->first();
            $categoryRestricted = $selectedCategory instanceof StaffGalleryCategory
                && ! in_array($selectedCategory->getKey(), $visibleCategoryIds, true);
            $selectedUncategorized = $selectedCategory === null
                || ($selectedCategory->images_count === 0 && $uncategorizedCount > 0);
            if ($selectedUncategorized) {
                $selectedCategory = null;
                $categoryRestricted = false;
            }
        }

        $mediaQuery = $user->staffGalleryImages()->with(['file', 'categories'])->latest('id');
        if ($categoryRestricted) {
            $mediaQuery->whereRaw('1 = 0');
        } elseif ($selectedCategory instanceof StaffGalleryCategory) {
            $mediaQuery->whereHas('categories', fn ($query) => $query->whereKey($selectedCategory->getKey()));
        } else {
            $mediaQuery->whereDoesntHave('categories');
        }

        return response()->view('staff.gallery', [
            'staff' => $user,
            'categories' => $categories,
            'uncategorizedCount' => $uncategorizedCount,
            'selectedCategory' => $selectedCategory,
            'selectedUncategorized' => $selectedUncategorized,
            'categoryRestricted' => $categoryRestricted,
            'visibleCategoryIds' => $visibleCategoryIds,
            'galleryMedia' => $mediaQuery->paginate(24)->withQueryString(),
            'galleryTotalCount' => $categories->sum('images_count') + $uncategorizedCount,
            'routePrefix' => $this->staffRoutePrefix(),
            'galleryRoutePrefix' => $this->galleryRoutePrefix(),
            'authToken' => $authToken,
            'mediaAuthExpiresAt' => $mediaAccess->expiresAt($request, $user->getKey(), $authToken),
            'canManageGalleryAccess' => $galleryAccess->isAdministrator($request),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function unlock(
        Request $request,
        User $user,
        SettingService $settings,
        StaffMediaAccess $mediaAccess,
        GalleryCategoryAccess $galleryAccess,
    ): JsonResponse {
        $this->assertStaffRecord($user);
        abort_unless($this->canManageStaffMedia($request, $galleryAccess), 403);
        $validated = $request->validate(['pin' => ['required', 'digits:4']]);
        $pinHash = $settings->get('person_image_pin_hash');
        if (! is_string($pinHash) || ! Hash::check($validated['pin'], $pinHash)) {
            return response()->json([
                'message' => is_string($pinHash) ? 'The PIN is incorrect.' : 'No staff gallery PIN has been configured.',
            ], 422)->header('Cache-Control', 'no-store, private');
        }

        $authorization = $mediaAccess->issue($request, $user);

        return response()->json([
            'status' => 'unlocked',
            ...$authorization,
            'redirect_url' => route($this->galleryRoutePrefix().'.index', [
                'user' => $user,
                'auth' => $authorization['token'],
            ]),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function uploadPage(
        Request $request,
        User $user,
        StaffMediaAccess $mediaAccess,
        SettingService $settings,
    ): Response|RedirectResponse {
        $this->assertStaffRecord($user);
        if ($request->query('auth') === null) {
            $activeToken = $mediaAccess->activeToken($request, $user->getKey());
            if ($activeToken !== null) {
                return redirect()->to(route($this->galleryRoutePrefix().'.uploads', [
                    'user' => $user,
                    'auth' => $activeToken,
                ]));
            }

            return redirect()->to(route($this->galleryRoutePrefix().'.index', [
                'user' => $user,
                'auth' => 'unauthed',
            ]));
        }

        if (! $this->authorized($request, $user, $mediaAccess)) {
            return redirect()->to(route($this->galleryRoutePrefix().'.index', [
                'user' => $user,
                'auth' => 'unauthed',
            ]));
        }

        return response()->view('staff.gallery-uploads', [
            'staff' => $user,
            'routePrefix' => $this->staffRoutePrefix(),
            'galleryRoutePrefix' => $this->galleryRoutePrefix(),
            'authToken' => $request->query('auth'),
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
            'acceptAttribute' => app(RecordAttachmentTypes::class)->acceptAttribute(RecordAttachmentTypes::STAFF_GALLERY),
        ])->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function createCategory(Request $request, User $user, StaffMediaAccess $mediaAccess): RedirectResponse|JsonResponse
    {
        $this->assertAuthorized($request, $user, $mediaAccess);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $user->staffGalleryCategories()->create([
            'title' => trim($validated['title']),
            'description' => trim($validated['description'] ?? ''),
            'sort_order' => (int) $user->staffGalleryCategories()->max('sort_order') + 1,
        ]);

        return $this->galleryActionResponse($request, $user, null, 'Gallery category created.');
    }

    public function updateCategory(
        Request $request,
        User $user,
        int $category,
        StaffMediaAccess $mediaAccess,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $this->assertAuthorized($request, $user, $mediaAccess);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);
        $categoryModel = $user->staffGalleryCategories()->whereKey($category)->firstOrFail();
        abort_unless($galleryAccess->canViewStaffCategory($categoryModel, $request), 403);
        $categoryModel->update([
            'title' => trim($validated['title']),
            'description' => trim($validated['description'] ?? ''),
        ]);

        return $this->galleryActionResponse($request, $user, (string) $category, 'Gallery category updated.');
    }

    public function deleteCategory(
        Request $request,
        User $user,
        int $category,
        StaffMediaAccess $mediaAccess,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $this->assertAuthorized($request, $user, $mediaAccess);
        $categoryModel = $user->staffGalleryCategories()->whereKey($category)->firstOrFail();
        abort_unless($galleryAccess->canViewStaffCategory($categoryModel, $request), 403);
        $categoryModel->delete();

        return $this->galleryActionResponse($request, $user, 'uncategorized', 'Gallery category deleted.');
    }

    public function assignCategories(
        Request $request,
        User $user,
        int $image,
        StaffMediaAccess $mediaAccess,
        GalleryCategoryAccess $galleryAccess,
    ): RedirectResponse|JsonResponse {
        $this->assertAuthorized($request, $user, $mediaAccess);
        if ($request->has('category_ids')) {
            $validated = $request->validate([
                'category_ids' => ['present', 'array'],
                'category_ids.*' => ['integer', 'distinct'],
            ]);
            $categoryIds = array_map('intval', $validated['category_ids']);
        } elseif ($request->expectsJson()) {
            $categoryIds = [];
        } else {
            $request->validate(['category_ids' => ['present', 'array']]);
            $categoryIds = [];
        }
        $categories = $user->staffGalleryCategories()->whereIn('id', $categoryIds)->get();
        abort_unless($categories->count() === count($categoryIds), 404);
        foreach ($categories as $category) {
            abort_unless($galleryAccess->canViewStaffCategory($category, $request), 403);
        }

        $media = $user->staffGalleryImages()->whereKey($image)->firstOrFail();
        abort_unless($galleryAccess->canViewStaffImage($media, $request), 403);
        DB::transaction(fn () => $media->categories()->sync($categoryIds));

        $currentCategory = $request->query('category');
        $redirectCategory = $currentCategory !== null && in_array((int) $currentCategory, $categoryIds, true)
            ? $currentCategory
            : ($categoryIds[0] ?? 'uncategorized');

        return $this->galleryActionResponse($request, $user, $redirectCategory, 'Media categories updated.');
    }

    public function image(
        Request $request,
        User $user,
        int $image,
        GalleryCategoryAccess $galleryAccess,
        StaffMediaAccess $mediaAccess,
        bool $download = false,
        bool $thumbnail = false,
    ): mixed {
        $this->assertAuthorized($request, $user, $mediaAccess);
        $media = $user->staffGalleryImages()->with('file')->whereKey($image)->firstOrFail();
        abort_unless($galleryAccess->canViewStaffImage($media, $request), 403);
        $file = $media->file;
        abort_unless($file !== null, 404);

        if ($media->type === 'video') {
            return $download
                ? app(FileController::class)->download($file)
                : ($file->video_playable_path
                    ? app(FileController::class)->videoRendition($file)
                    : app(FileController::class)->stream($request, $file));
        }

        abort_unless($media->type === 'image' && $file->category === 'image', 404);
        if ($thumbnail) {
            $disk = Storage::disk(config('vault.upload_disk', 'vault'));
            $thumbnailPath = app(ImageThumbnailService::class)->thumbnailPath($file->stored_path);
            $path = $disk->exists($thumbnailPath) ? $thumbnailPath : $file->stored_path;

            return app(FileController::class)->streamPrivatePath(
                $request,
                $path,
                $path === $thumbnailPath ? 'image/jpeg' : null,
                $file->original_name,
            );
        }

        return $download
            ? app(FileController::class)->download($file)
            : app(FileController::class)->stream($request, $file);
    }

    public function poster(
        Request $request,
        User $user,
        StaffGalleryImage $image,
        GalleryCategoryAccess $galleryAccess,
        StaffMediaAccess $mediaAccess,
    ): SymfonyResponse {
        $this->assertAuthorized($request, $user, $mediaAccess);
        abort_unless($image->user_id === $user->getKey() && $image->type === 'video', 404);
        abort_unless($galleryAccess->canViewStaffImage($image, $request), 403);
        $path = $image->file?->video_poster_path;
        abort_unless(is_string($path) && Storage::disk(config('vault.upload_disk', 'vault'))->exists($path), 404);

        return app(FileController::class)->streamPrivatePath(
            $request,
            $path,
            'image/jpeg',
            pathinfo($image->original_name, PATHINFO_FILENAME).'-poster.jpg',
        );
    }

    private function galleryActionResponse(Request $request, User $user, string|int|null $category, string $message): RedirectResponse|JsonResponse
    {
        $authToken = $request->input('auth', $request->query('auth'));
        if (! is_string($authToken) || $authToken === '') {
            $authToken = 'unauthed';
        }
        $parameters = ['user' => $user, 'auth' => $authToken];
        if ($category !== null) {
            $parameters['category'] = $category;
        }

        $redirectUrl = route($this->galleryRoutePrefix().'.index', $parameters);
        if ($request->expectsJson()) {
            return response()->json(['redirect_url' => $redirectUrl, 'message' => $message]);
        }

        return redirect()->to($redirectUrl)->with('status', $message);
    }

    private function authorized(Request $request, User $user, StaffMediaAccess $mediaAccess): bool
    {
        $token = $request->query('auth');

        return $mediaAccess->allows($request, $user->getKey(), is_string($token) ? $token : null);
    }

    private function assertAuthorized(Request $request, User $user, StaffMediaAccess $mediaAccess): void
    {
        abort_unless($this->authorized($request, $user, $mediaAccess), 403);
    }

    private function assertStaffRecord(User $user): void
    {
        abort_unless($user->department !== null, 404);
    }

    private function canManageStaffMedia(Request $request, GalleryCategoryAccess $galleryAccess): bool
    {
        if ($galleryAccess->isAdministrator($request)) {
            return true;
        }

        $actor = $request->user('web');

        return $actor instanceof User
            && $actor->department === 'sm'
            && $actor->account_status === 'active';
    }

    private function staffRoutePrefix(): string
    {
        return request()->routeIs('sm.*') ? 'sm.staff' : 'admin.staff';
    }

    private function galleryRoutePrefix(): string
    {
        return $this->staffRoutePrefix().'.gallery';
    }
}
