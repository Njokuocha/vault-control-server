<?php

use App\Jobs\BuildImageThumbnailJob;
use App\Models\Admin;
use App\Models\File as VaultFile;
use App\Models\Person;
use App\Models\PersonGalleryCategory;
use App\Models\PersonImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
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

beforeEach(function (): void {
    Bus::fake([BuildImageThumbnailJob::class]);
});

test('profile pages show one bounded gallery preview and link to the profile gallery', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Gallery Preview Subject');
    $category = $person->galleryCategories()->create(['title' => 'Archive', 'sort_order' => 1]);
    foreach (range(1, 7) as $index) {
        profileGalleryImage($person, "photo-{$index}.jpg", $category->id);
    }

    $response = $this->actingAs($staff, 'web')->get(route('pm.people.show', $person));

    $response->assertOk()
        ->assertSee('Archive')
        ->assertSee(route('pm.people.gallery', $person), false)
        ->assertSee('VIEW GALLERY', false);
    expect(substr_count($response->getContent(), 'class="profile-gallery-tile'))->toBe(4)
        ->and(substr_count($response->getContent(), 'MANAGE MEDIA'))->toBe(1);
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

    $this->actingAs($staff, 'web')
        ->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id]))
        ->assertOk()
        ->assertSee('categorized.jpg')
        ->assertSee('IMG '.str_pad((string) $categorized->id, 2, '0', STR_PAD_LEFT))
        ->assertSee('CHANGE CATEGORY')
        ->assertSee('Context for this category')
        ->assertSee('name="description"', false)
        ->assertDontSee('legacy.jpg')
        ->assertDontSee('Foreign Category')
        ->assertDontSee('foreign.jpg');

    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => 'uncategorized']))
        ->assertOk()
        ->assertSee('legacy.jpg')
        ->assertDontSee('categorized.jpg');

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $foreignImage->id]), [
        'category_id' => $category->id,
    ])->assertNotFound();

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $categorized->id]), [
        'category_id' => $foreignCategory->id,
    ])->assertNotFound();

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $legacy->id]), [
        'category_id' => $category->id,
    ])->assertRedirect(route('pm.people.gallery', ['person' => $person, 'category' => $category->id]));

    expect($legacy->fresh()->gallery_category_id)->toBe($category->id);

    $this->put(route('pm.people.gallery-images.category', ['person' => $person, 'image' => $legacy->id]), [
        'category_id' => null,
    ])->assertRedirect(route('pm.people.gallery', ['person' => $person, 'category' => 'uncategorized']));

    expect($categorized->fresh()->gallery_category_id)->toBe($category->id)
        ->and($legacy->fresh()->gallery_category_id)->toBeNull();
});

test('one profile media item can be assigned to multiple categories without reuploading', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Shared Media Subject');
    $firstCategory = $person->galleryCategories()->create(['title' => 'Field']);
    $secondCategory = $person->galleryCategories()->create(['title' => 'Archive']);
    $image = profileGalleryImage($person, 'shared-item.jpg', $firstCategory->id);

    $this->actingAs($staff, 'web')
        ->putJson(route('pm.people.gallery-images.category', [$person, $image]), [
            'category_ids' => [$firstCategory->id, $secondCategory->id],
        ])
        ->assertOk()
        ->assertJsonPath('redirect_url', route('pm.people.gallery', ['person' => $person, 'category' => $firstCategory->id]));

    expect($image->fresh()->galleryCategories()->pluck('person_gallery_categories.id')->all())
        ->toEqualCanonicalizing([$firstCategory->id, $secondCategory->id]);

    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $firstCategory->id]))
        ->assertOk()
        ->assertSee('shared-item.jpg');
    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $secondCategory->id]))
        ->assertOk()
        ->assertSee('shared-item.jpg');

    $this->putJson(route('pm.people.gallery-images.category', [$person, $image]), [])
        ->assertOk()
        ->assertJsonPath('redirect_url', route('pm.people.gallery', ['person' => $person, 'category' => 'uncategorized']));
    expect($image->fresh()->galleryCategories()->count())->toBe(0);
});

test('categories can be created renamed and deleted without deleting their media', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Category Management Subject');

    $this->actingAs($staff, 'web')
        ->post(route('pm.people.gallery-categories.store', $person), ['title' => 'Initial', 'description' => 'Initial context'])
        ->assertRedirect(route('pm.people.gallery', $person));

    $category = $person->galleryCategories()->firstOrFail();
    $image = profileGalleryImage($person, 'retained.jpg', $category->id);

    $this->put(route('pm.people.gallery-categories.update', [$person, $category]), [
        'title' => 'Renamed',
        'description' => 'Updated context',
    ])
        ->assertRedirect(route('pm.people.gallery', ['person' => $person, 'category' => $category->id]));
    expect($category->fresh()->title)->toBe('Renamed')
        ->and($category->fresh()->description)->toBe('Updated context');

    $this->delete(route('pm.people.gallery-categories.destroy', [$person, $category]))
        ->assertRedirect(route('pm.people.gallery', ['person' => $person, 'category' => 'uncategorized']));

    expect(PersonGalleryCategory::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and($image->fresh()->gallery_category_id)->toBeNull()
        ->and($image->fresh()->exists)->toBeTrue();
});

test('gallery categories and media are paginated and private previews stay PIN protected', function () {
    $staff = User::factory()->create(['department' => 'pm', 'account_status' => 'active']);
    $person = profileGalleryPerson('Paginated Gallery Subject');
    $category = $person->galleryCategories()->create(['title' => 'Large collection']);
    foreach (range(1, 26) as $index) {
        profileGalleryImage($person, "photo-{$index}.jpg", $category->id);
    }

    $this->actingAs($staff, 'web')
        ->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id]))
        ->assertOk()
        ->assertSee('photo-26.jpg')
        ->assertDontSee('photo-1.jpg')
        ->assertSee('Next')
        ->assertSee('data-image-pin-dialog', false);

    $this->get(route('pm.people.image', ['person' => $person, 'image' => $person->images()->first()->id]))
        ->assertForbidden();

    $this->withSession(['unlocked_person_images' => $person->id])
        ->get(route('pm.people.image', ['person' => $person, 'image' => $person->images()->first()->id]))
        ->assertNotFound();
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
    $this->get(route('people.gallery', ['person' => $person, 'category' => $category->id]))
        ->assertOk()
        ->assertSee('data-gallery-access-open', false)
        ->assertSee('ri-lock-line', false)
        ->assertSee('data-gallery-access-dialog', false);

    Auth::guard('admin')->logout();
    $this->actingAs($otherStaff, 'web')
        ->get(route('pm.people.show', $person))
        ->assertOk()
        ->assertDontSee('restricted-photo.jpg');
    $this->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id]))
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
    $this->actingAs($allowedStaff, 'web')
        ->withSession(['unlocked_person_images' => $person->id])
        ->get(route('pm.people.gallery', ['person' => $person, 'category' => $category->id]))
        ->assertOk()
        ->assertSee('restricted-photo.jpg');
    $this->get(route('pm.people.image', ['person' => $person, 'image' => $image->id]))->assertOk();

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
