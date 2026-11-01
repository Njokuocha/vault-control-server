<?php

use App\Jobs\BuildImageThumbnailJob;
use App\Models\Admin;
use App\Models\File as VaultFile;
use App\Models\Person;
use App\Models\PersonGalleryCategory;
use App\Models\PersonImage;
use App\Models\User;
use App\Services\PersonMediaAccess;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function profileGalleryPerson(string $name): Person
{
    return Person::query()->create([
        'public_id' => 'USR-'.strtoupper(str()->random(8)),
        'name' => $name,
    ]);
}

function profileGalleryImage(Person $person, string $name, ?int $categoryId = null): PersonImage
{
    return $person->images()->create([
        'gallery_category_id' => $categoryId,
        'path' => 'people/gallery/'.str()->uuid().'.jpg',
        'original_name' => $name,
        'type' => 'image',
    ]);
}

function profileGalleryToken(Person $person): string
{
    $request = request();
    $request->setUserResolver(fn () => Auth::user());

    return app(PersonMediaAccess::class)->issue($request, $person)['token'];
}

beforeEach(function (): void {
    Bus::fake([BuildImageThumbnailJob::class]);
});

test('profile pages require a token and preserve it through Livewire gallery navigation', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Gallery Preview Subject');
    $category = $person->galleryCategories()->create(['title' => 'Archive', 'sort_order' => 1]);
    foreach (range(1, 7) as $index) {
        profileGalleryImage($person, "photo-{$index}.jpg", $category->id);
    }

    $this->actingAs($staff, 'web')
        ->get(route('pm.people.show', $person))
        ->assertRedirect(route('pm.people.show', ['person' => $person, 'auth' => 'unauthed']));
    $this->get(route('pm.people.show', ['person' => $person, 'auth' => 'unauthed']))
        ->assertOk()
        ->assertDontSee('photo-1.jpg');

    $token = profileGalleryToken($person);
    $galleryUrl = route('pm.people.gallery', ['person' => $person, 'auth' => $token]);
    $response = $this->get(route('pm.people.show', ['person' => $person, 'auth' => $token]));

    $response->assertOk()
        ->assertSee('Archive')
        ->assertSee('href="'.$galleryUrl.'" wire:navigate', false)
        ->assertSee('VIEW GALLERY', false);
    expect(substr_count($response->getContent(), 'class="profile-gallery-tile'))->toBe(4)
        ->and(substr_count($response->getContent(), 'MANAGE MEDIA'))->toBe(1);
});

test('admin and profile manager preserve media tokens while navigating profiles and galleries with Livewire', function () {
    $person = profileGalleryPerson('SPA Gallery Subject');
    $person->galleryCategories()->create(['title' => 'Archive']);
    $admin = Admin::query()->create([
        'name' => 'Gallery SPA Administrator',
        'email' => 'gallery-spa-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);

    foreach ([
        [$admin, 'admin', 'people.show', 'people.gallery'],
        [$staff, 'web', 'pm.people.show', 'pm.people.gallery'],
    ] as [$user, $guard, $showRoute, $galleryRoute]) {
        $this->actingAs($user, $guard);
        $token = profileGalleryToken($person);
        $profileUrl = route($showRoute, ['person' => $person, 'auth' => $token]);
        $galleryUrl = route($galleryRoute, ['person' => $person, 'auth' => $token]);
        $profile = $this->get($profileUrl);
        $profile->assertOk()
            ->assertSee('href="'.$galleryUrl.'" wire:navigate', false);

        $gallery = $this->get($galleryUrl);
        $gallery->assertOk()
            ->assertSee('href="'.$profileUrl.'" wire:navigate', false);
    }
});

test('one-hour PIN tokens authorize profiles and galleries only for the issuing account and profile', function () {
    $person = profileGalleryPerson('PIN Gallery Subject');
    $firstCategory = $person->galleryCategories()->create(['title' => 'First']);
    $secondCategory = $person->galleryCategories()->create(['title' => 'Second']);
    $image = profileGalleryImage($person, 'private-gallery.png', $secondCategory->id);
    $otherPerson = profileGalleryPerson('Another PIN Gallery Subject');
    $otherCategory = $otherPerson->galleryCategories()->create(['title' => 'Separate']);
    $otherImage = profileGalleryImage($otherPerson, 'another-private-gallery.png', $otherCategory->id);
    Storage::fake(config('vault.upload_disk'));
    Storage::disk(config('vault.upload_disk'))->put($image->path, 'private media bytes');
    Storage::disk(config('vault.upload_disk'))->put($otherImage->path, 'other private media bytes');
    $documentPath = 'people/gallery/'.str()->uuid().'.pdf';
    Storage::disk(config('vault.upload_disk'))->put($documentPath, '%PDF-1.7 private document');
    $documentFile = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'private-document.pdf',
        'stored_path' => $documentPath,
        'extension' => 'pdf',
        'mime_type' => 'application/pdf',
        'category' => 'document',
        'size' => 26,
        'checksum' => hash('sha256', '%PDF-1.7 private document'),
    ]);
    $document = $person->images()->create([
        'gallery_category_id' => $secondCategory->id,
        'file_id' => $documentFile->id,
        'path' => $documentPath,
        'original_name' => 'private-document.pdf',
        'type' => 'document',
    ]);
    app(SettingService::class)->set('person_image_pin_hash', Hash::make('0427'));

    $admin = Admin::query()->create([
        'name' => 'Gallery PIN Administrator',
        'email' => 'gallery-pin-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $otherStaff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);

    foreach ([
        [$admin, 'admin', 'people.show', 'people.gallery', 'people.image'],
        [$staff, 'web', 'pm.people.show', 'pm.people.gallery', 'pm.people.image'],
    ] as [$user, $guard, $showRoute, $galleryRoute, $imageRoute]) {
        $this->actingAs($user, $guard);
        $this->get(route($galleryRoute, ['person' => $person, 'category' => $firstCategory->id]))
            ->assertRedirect(route($galleryRoute, [
                'person' => $person,
                'auth' => 'unauthed',
                'category' => $firstCategory->id,
            ]));
        $this->get(route($galleryRoute, [
            'person' => $person,
            'category' => $firstCategory->id,
            'auth' => 'unauthed',
        ]))->assertOk()
            ->assertSee('Protected gallery')
            ->assertDontSee('private-gallery.png');
        $this->get(route($imageRoute, ['person' => $person, 'image' => $image->id]))
            ->assertForbidden();

        $this->postJson(route(str_replace('.show', '.images.unlock', $showRoute), $person), ['pin' => '9999'])
            ->assertUnprocessable();
        $unlock = $this->postJson(route(str_replace('.show', '.images.unlock', $showRoute), $person), ['pin' => '0427'])
            ->assertOk()
            ->assertJsonPath('status', 'unlocked')
            ->assertJsonPath('redirect_url', fn (string $url): bool => str_contains($url, 'auth='));
        $token = $unlock->json('token');
        expect($token)->toMatch('/^[A-Za-z0-9]{32}$/');

        $this->get(route($showRoute, ['person' => $person, 'auth' => $token]))
            ->assertOk()
            ->assertSee('data-media-authorized="true"', false)
            ->assertSee('private-gallery.png');
        foreach ([$firstCategory->id, $secondCategory->id, $firstCategory->id] as $categoryId) {
            $this->get(route($galleryRoute, [
                'person' => $person,
                'category' => $categoryId,
                'auth' => $token,
            ]))->assertOk()
                ->assertSee('data-gallery-media-unlocked="true"', false)
                ->assertSee('auth='.$token);
        }
        $this->get(route($imageRoute, ['person' => $person, 'image' => $image->id, 'auth' => $token]))
            ->assertOk();
        $downloadRoute = str_replace('.image', '.images.download', $imageRoute);
        $this->get(route($downloadRoute, ['person' => $person, 'image' => $document->id, 'auth' => $token]))
            ->assertOk();
        $this->get(route($downloadRoute, ['person' => $person, 'image' => $document->id]))
            ->assertForbidden();
        if ($guard === 'admin') {
            $this->get(route('files.stream', ['file' => $documentFile->uuid, 'auth' => $token]))
                ->assertOk();
            $this->get(route('files.stream', ['file' => $documentFile->uuid]))
                ->assertForbidden();
        }
        $this->get(route($imageRoute, ['person' => $person, 'image' => $image->id]))
            ->assertForbidden();
        $this->get(route($galleryRoute, [
            'person' => $person,
            'category' => $firstCategory->id,
            'auth' => str_repeat('x', 32),
        ]))->assertOk()
            ->assertSee('Protected gallery')
            ->assertDontSee('private-gallery.png');
        $this->get(route($imageRoute, ['person' => $otherPerson, 'image' => $otherImage->id, 'auth' => $token]))
            ->assertForbidden();
        $this->get(route($galleryRoute, [
            'person' => $otherPerson,
            'category' => $otherCategory->id,
            'auth' => $token,
        ]))->assertOk()
            ->assertSee('Protected gallery')
            ->assertDontSee('another-private-gallery.png');

        if ($guard === 'admin') {
            $this->actingAs($otherStaff, 'web')
                ->get(route('pm.people.image', ['person' => $person, 'image' => $image->id, 'auth' => $token]))
                ->assertForbidden();
        } else {
            $this->actingAs($otherStaff, 'web')
                ->get(route($imageRoute, ['person' => $person, 'image' => $image->id, 'auth' => $token]))
                ->assertForbidden();
        }
    }

    $this->actingAs($admin, 'admin');
    $token = profileGalleryToken($person);
    $this->travel(61)->minutes();
    $this->get(route('people.show', ['person' => $person, 'auth' => $token]))
        ->assertRedirect(route('people.show', ['person' => $person, 'auth' => 'unauthed']));

    $this->travelBack();
    $this->actingAs($admin, 'admin');
    $token = profileGalleryToken($person);
    $this->post(route('settings.person-image-pin.update'), [
        'image_pin' => '3916',
        'image_pin_confirmation' => '3916',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $this->get(route('people.show', ['person' => $person, 'auth' => $token]))
        ->assertRedirect(route('people.show', ['person' => $person, 'auth' => 'unauthed']));
});

test('gallery categories and media are profile-scoped and legacy media remain uncategorized', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Primary Gallery Subject');
    $otherPerson = profileGalleryPerson('Other Gallery Subject');
    $category = $person->galleryCategories()->create(['title' => 'Evidence', 'description' => 'Context for this category']);
    $foreignCategory = $otherPerson->galleryCategories()->create(['title' => 'Foreign Category']);
    $categorized = profileGalleryImage($person, 'categorized.jpg', $category->id);
    $legacy = profileGalleryImage($person, 'legacy.jpg');
    $foreignImage = profileGalleryImage($otherPerson, 'foreign.jpg', $foreignCategory->id);

    $this->get(route('pm.people.gallery', $person))->assertRedirect();

    $this->actingAs($staff, 'web');
    $token = profileGalleryToken($person);
    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id, 'auth' => $token]))
        ->assertOk()
        ->assertSee('categorized.jpg')
        ->assertSee('IMG '.str_pad((string) $categorized->id, 2, '0', STR_PAD_LEFT))
        ->assertSee('CHANGE CATEGORY')
        ->assertSee('Context for this category')
        ->assertSee('name="description"', false)
        ->assertDontSee('legacy.jpg')
        ->assertDontSee('Foreign Category')
        ->assertDontSee('foreign.jpg');

    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => 'uncategorized', 'auth' => $token]))
        ->assertOk()
        ->assertSee('legacy.jpg')
        ->assertDontSee('categorized.jpg');

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $foreignImage->id]), [
        'category_id' => $category->id,
    ])->assertNotFound();

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $categorized->id]), [
        'category_id' => $foreignCategory->id,
    ])->assertNotFound();

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $legacy->id, 'auth' => $token]), [
        'category_id' => $category->id,
    ])->assertRedirect(route('pm.people.gallery', ['person' => $person, 'auth' => $token, 'category' => $category->id]));

    expect($legacy->fresh()->gallery_category_id)->toBe($category->id);

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $legacy->id, 'auth' => $token]), [
        'category_id' => null,
    ])->assertRedirect(route('pm.people.gallery', ['person' => $person, 'auth' => $token, 'category' => 'uncategorized']));

    expect($categorized->fresh()->gallery_category_id)->toBe($category->id)
        ->and($legacy->fresh()->gallery_category_id)->toBeNull();
});

test('one profile media item can be assigned to multiple categories without reuploading', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Shared Media Subject');
    $firstCategory = $person->galleryCategories()->create(['title' => 'Field']);
    $secondCategory = $person->galleryCategories()->create(['title' => 'Archive']);
    $image = profileGalleryImage($person, 'shared-item.jpg', $firstCategory->id);

    $this->actingAs($staff, 'web');
    $token = profileGalleryToken($person);
    $this->putJson(route('pm.people.gallery-images.category', [
            'person' => $person,
            'image' => $image,
            'auth' => $token,
        ]), [
            'category_ids' => [$firstCategory->id, $secondCategory->id],
        ])
        ->assertOk()
        ->assertJsonPath('redirect_url', route('pm.people.gallery', ['person' => $person, 'auth' => $token, 'category' => $firstCategory->id]));

    expect($image->fresh()->galleryCategories()->pluck('person_gallery_categories.id')->all())
        ->toEqualCanonicalizing([$firstCategory->id, $secondCategory->id]);

    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $firstCategory->id, 'auth' => $token]))
        ->assertOk()
        ->assertSee('shared-item.jpg');
    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $secondCategory->id, 'auth' => $token]))
        ->assertOk()
        ->assertSee('shared-item.jpg');

    $this->putJson(route('pm.people.gallery-images.category', [
        'person' => $person,
        'image' => $image,
        'auth' => $token,
    ]), [])
        ->assertOk()
        ->assertJsonPath('redirect_url', route('pm.people.gallery', ['person' => $person, 'auth' => $token, 'category' => 'uncategorized']));
    expect($image->fresh()->galleryCategories()->count())->toBe(0);
});

test('categories can be created renamed and deleted without deleting their media', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Category Management Subject');

    $this->actingAs($staff, 'web');
    $token = profileGalleryToken($person);
    $this->post(route('pm.people.gallery-categories.store', ['person' => $person, 'auth' => $token]), ['title' => 'Initial', 'description' => 'Initial context'])
        ->assertRedirect(route('pm.people.gallery', ['person' => $person, 'auth' => $token]));

    $category = $person->galleryCategories()->firstOrFail();
    $image = profileGalleryImage($person, 'retained.jpg', $category->id);

    $this->put(route('pm.people.gallery-categories.update', ['person' => $person, 'category' => $category, 'auth' => $token]), [
        'title' => 'Renamed',
        'description' => 'Updated context',
    ])
        ->assertRedirect(route('pm.people.gallery', ['person' => $person, 'auth' => $token, 'category' => $category->id]));
    expect($category->fresh()->title)->toBe('Renamed')
        ->and($category->fresh()->description)->toBe('Updated context');

    $this->delete(route('pm.people.gallery-categories.destroy', ['person' => $person, 'category' => $category, 'auth' => $token]))
        ->assertRedirect(route('pm.people.gallery', ['person' => $person, 'auth' => $token, 'category' => 'uncategorized']));

    expect(PersonGalleryCategory::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and($image->fresh()->gallery_category_id)->toBeNull()
        ->and($image->fresh()->exists)->toBeTrue();
});

test('gallery categories and media are paginated and the token survives navigation and refresh', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Paginated Gallery Subject');
    $category = $person->galleryCategories()->create(['title' => 'Large collection']);
    foreach (range(1, 26) as $index) {
        profileGalleryImage($person, "photo-{$index}.jpg", $category->id);
    }

    Storage::fake(config('vault.upload_disk'));
    $firstImage = $person->images()->firstOrFail();
    Storage::disk(config('vault.upload_disk'))->put($firstImage->path, 'private image bytes');

    $this->actingAs($staff, 'web');
    $token = profileGalleryToken($person);
    $galleryUrl = route('pm.people.gallery', ['person' => $person, 'category' => $category->id, 'auth' => $token]);
    $this->get($galleryUrl)
        ->assertOk()
        ->assertSee('photo-26.jpg')
        ->assertDontSee('photo-1.jpg')
        ->assertSee('Next')
        ->assertSee('data-gallery-media-unlocked="true"', false)
        ->assertDontSee('data-image-pin-dialog', false)
        ->assertDontSee('Unlock viewables');

    $this->get(route('pm.people.image', ['person' => $person, 'image' => $person->images()->first()->id, 'auth' => $token]))
        ->assertOk();

    $this->get($galleryUrl)->assertOk()->assertSee('photo-26.jpg');
    $this->get(route('pm.people.show', ['person' => $person, 'auth' => $token]))->assertOk();
    $this->get(route('pm.people.image', ['person' => $person, 'image' => $firstImage->id]))
        ->assertForbidden();

    $this->withSession(['unlocked_person_images' => $person->id])
        ->get(route('pm.people.image', ['person' => $person, 'image' => $person->images()->first()->id]))
        ->assertForbidden();
});

test('locked profile categories require a department grant and unlock clears grants', function () {
    $admin = Admin::query()->create([
        'name' => 'Gallery Administrator',
        'email' => 'gallery-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    $allowedStaff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $otherStaff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $inactiveStaff = User::factory()->create(['department' => 'pm', 'account_status' => 'inactive']);
    $fmStaff = User::factory()->create(['department' => 'fm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Restricted Gallery Subject');
    $category = $person->galleryCategories()->create(['title' => 'Sensitive records']);
    $image = profileGalleryImage($person, 'restricted-photo.jpg', $category->id);
    Storage::disk('vault')->put($image->path, 'private image bytes');
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'restricted-photo.jpg',
        'stored_path' => $image->path,
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'category' => 'image',
        'size' => 19,
        'checksum' => hash('sha256', 'private image bytes'),
    ]);
    $image->update(['file_id' => $file->id]);

    $this->actingAs($admin, 'admin')
        ->putJson(route('admin.gallery-access.people.lock', [$person, $category]), ['locked' => true])
        ->assertOk()
        ->assertJsonPath('locked', true);

    $staffResponse = $this->getJson(route('admin.gallery-access.people.staff', [$person, $category]))
        ->assertOk();
    expect(collect($staffResponse->json('staff'))->pluck('id')->all())
        ->toContain($allowedStaff->id, $otherStaff->id)
        ->not->toContain($fmStaff->id, $inactiveStaff->id);
    $this->actingAs($admin, 'admin');
    $adminToken = profileGalleryToken($person);
    $this->get(route('people.gallery', ['person' => $person, 'category' => $category->id, 'auth' => $adminToken]))
        ->assertOk()
        ->assertSee('data-gallery-access-open', false)
        ->assertSee('ri-lock-line', false)
        ->assertSee('data-gallery-access-dialog', false);

    Auth::guard('admin')->logout();
    $this->actingAs($otherStaff, 'web');
    $otherStaffToken = profileGalleryToken($person);
    $this->get(route('pm.people.show', ['person' => $person, 'auth' => $otherStaffToken]))
        ->assertOk()
        ->assertSee('data-gallery-restricted', false)
        ->assertSee('data-gallery-restricted-dialog', false)
        ->assertSee('gallery-restricted-card__icon', false)
        ->assertSee('File restricted')
        ->assertSee('This file has been restricted and cannot be accessed.')
        ->assertDontSee('restricted-photo.jpg');
    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id, 'auth' => $otherStaffToken]))
        ->assertOk()
        ->assertSee('Restricted category')
        ->assertDontSee('restricted-photo.jpg');
    $this->withSession(['unlocked_person_images' => $person->id])
        ->get(route('pm.people.image', ['person' => $person, 'image' => $image->id]))
        ->assertForbidden();

    $this->actingAs($fmStaff, 'web')
        ->withSession(['unlocked_person_images' => $person->id])
        ->get(route('fm.files.stream', ['file' => $file->uuid]))
        ->assertForbidden();

    $this->actingAs($admin, 'admin')
        ->postJson(route('admin.gallery-access.people.grant', [$person, $category, $allowedStaff]))
        ->assertOk()
        ->assertJsonPath('granted', true);
    expect($category->authorizedStaff()->whereKey($allowedStaff->id)->exists())->toBeTrue();

    Auth::guard('admin')->logout();
    $this->actingAs($allowedStaff, 'web');
    $allowedStaffToken = profileGalleryToken($person);
    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id, 'auth' => $allowedStaffToken]))
        ->assertOk()
        ->assertSee('restricted-photo.jpg');
    $this->get(route('pm.people.image', ['person' => $person, 'image' => $image->id, 'auth' => $allowedStaffToken]))->assertOk();

    $this->actingAs($admin, 'admin')
        ->putJson(route('admin.gallery-access.people.lock', [$person, $category]), ['locked' => false])
        ->assertOk()
        ->assertJsonPath('locked', false)
        ->assertJsonPath('revoked_count', 1);
    expect($category->fresh()->authorizedStaff()->count())->toBe(0);
    $this->assertDatabaseHas('activity_logs', ['category' => 'gallery_access', 'action' => 'category_locked']);
    $this->assertDatabaseHas('activity_logs', ['category' => 'gallery_access', 'action' => 'staff_granted']);
    $this->assertDatabaseHas('activity_logs', ['category' => 'gallery_access', 'action' => 'category_unlocked']);
});
