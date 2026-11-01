<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\GalleryCategoryAccess;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\Response;

class FileManagerController extends Controller
{
    public function dashboard(): View
    {
        return view('departments.fm.dashboard');
    }

    public function personMediaIndex(GalleryCategoryAccess $galleryAccess): View
    {
        $mediaGroups = Person::query()
            ->where(function ($query): void {
                $query->whereNotNull('main_image_path')
                    ->orWhereNotNull('main_image_file_id')
                    ->orWhereHas('images', fn ($images) => $images->whereNull('type')->orWhereIn('type', ['image', 'video']));
            })
            ->select(['id', 'main_image_path', 'main_image_file_id'])
            ->withCount(['images as media_count' => function ($images) use ($galleryAccess): void {
                $images->where(fn ($query) => $query->whereNull('type')->orWhereIn('type', ['image', 'video']));
                if (! $galleryAccess->isAdministrator(request())) {
                    $images->where(function ($query): void {
                        $query->where(function ($uncategorized): void {
                            $uncategorized->whereNull('gallery_category_id')
                                ->whereDoesntHave('galleryCategories');
                        })->orWhereHas('galleryCategories', fn ($categories) => $categories->where('is_locked', false))
                            ->orWhereHas('galleryCategory', fn ($category) => $category->where('is_locked', false));
                    });
                }
            }])
            ->orderBy('id')
            ->paginate(24);

        return view('departments.fm.person-media-index', compact('mediaGroups'));
    }

    public function personMedia(Person $person, SettingService $settings, GalleryCategoryAccess $galleryAccess): View
    {
        $person->setVisible(['id', 'main_image_path', 'main_image_file_id']);
        $visibleCategoryIds = $galleryAccess->visiblePersonCategoryIds($person->getKey(), request());
        $person->load(['images' => fn ($images) => $images
            ->select(['id', 'person_id', 'type', 'poster_path', 'file_id'])
            ->where(fn ($query) => $query->whereNull('type')->orWhereIn('type', ['image', 'video']))
            ->where(function ($query) use ($visibleCategoryIds): void {
                $query->where(function ($query): void {
                    $query->whereNull('gallery_category_id')
                        ->whereDoesntHave('galleryCategories');
                });
                if ($visibleCategoryIds !== []) {
                    $query->orWhereIn('gallery_category_id', $visibleCategoryIds)
                        ->orWhereHas('galleryCategories', fn ($categories) => $categories->whereIn('person_gallery_categories.id', $visibleCategoryIds));
                }
            })
            ->orderBy('id'), 'images.file' => fn ($files) => $files->select(['id', 'video_poster_path'])]);

        abort_if(! $person->main_image_path && ! $person->main_image_file_id && $person->images->isEmpty(), 404);

        return view('departments.fm.person-media', [
            'person' => $person,
            'imagePinConfigured' => $settings->get('person_image_pin_hash') !== null,
        ]);
    }

    public function personMediaFile(
        Person $person,
        string $image,
        GalleryCategoryAccess $galleryAccess,
        bool $download = false,
        bool $thumbnail = false,
    ): Response {
        abort_unless(request()->session()->get('unlocked_person_images') === $person->getKey(), 403);

        if ($image !== 'main') {
            $mediaType = $person->images()->whereKey($image)->value('type');
            abort_unless($mediaType === null || in_array($mediaType, ['image', 'video'], true), 404);
        }

        return app(PersonController::class)->image($person, $image, $galleryAccess, $download, $thumbnail);
    }
}
