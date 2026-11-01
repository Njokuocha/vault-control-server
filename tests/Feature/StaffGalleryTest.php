<?php

use App\Livewire\StaffManager\StaffManagerDirectory;
use App\Models\Admin;
use App\Models\Department;
use App\Models\File as VaultFile;
use App\Models\User;
use App\Services\SettingService;
use App\Support\RecordAttachmentTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

uses(RefreshDatabase::class);

function staffGalleryProfile(string $name, string $department = 'pm'): User
{
    return User::factory()->create([
        'name' => $name,
        'department' => $department,
        'account_status' => 'active',
    ]);
}

function staffGalleryConfigurePin(string $pin = '4927'): void
{
    app(SettingService::class)->set('person_image_pin_hash', Hash::make($pin));
}

function staffGalleryUnlock(TestCase $test, User $profile, string $routePrefix = 'sm.staff.gallery', string $pin = '4927'): string
{
    $response = $test->postJson(route($routePrefix.'.unlock', ['user' => $profile]), ['pin' => $pin])
        ->assertOk()
        ->assertJsonPath('status', 'unlocked');

    return $response->json('token');
}

beforeEach(function (): void {
    Queue::fake();
});

test('staff galleries require PIN authorization and recover the active profile token', function () {
    $manager = staffGalleryProfile('Gallery Manager', 'sm');
    $profile = staffGalleryProfile('Gallery Subject');
    $category = $profile->staffGalleryCategories()->create(['title' => 'Field files']);
    $profile->staffGalleryImages()->create([
        'path' => 'staff-gallery/private-photo.jpg',
        'original_name' => 'private-photo.jpg',
        'type' => 'image',
    ])->categories()->attach($category);
    staffGalleryConfigurePin();

    $this->actingAs($manager, 'web')
        ->get(route('sm.staff.gallery.index', $profile))
        ->assertRedirect(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => 'unauthed']));

    $this->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => 'unauthed']))
        ->assertOk()
        ->assertSee('Protected gallery')
        ->assertSee('data-open-image-pin', false)
        ->assertDontSee('private-photo.jpg');

    $token = staffGalleryUnlock($this, $profile);
    expect(strlen($token))->toBe(32);

    $this->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]))
        ->assertOk()
        ->assertSee('Field files')
        ->assertSee('private-photo.jpg');
    $this->get(route('sm.staff.gallery.index', $profile))
        ->assertRedirect(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]));

    $this->get(route('sm.staff.gallery.uploads', ['user' => $profile, 'auth' => $token]))
        ->assertOk()
        ->assertSee('data-record-purpose="staff-gallery"', false)
        ->assertSee('data-commit-record-uploads="true"', false)
        ->assertSee('data-upload-on-demand="true"', false)
        ->assertSee('data-staff-gallery-upload-submit', false)
        ->assertSee('UPLOAD SELECTED FILES')
        ->assertSee('preview them before upload');
});

test('staff gallery tokens are scoped to actor and profile and expire', function () {
    $manager = staffGalleryProfile('Token Manager', 'sm');
    $otherManager = staffGalleryProfile('Other Token Manager', 'sm');
    $profile = staffGalleryProfile('Token Subject');
    staffGalleryConfigurePin();

    $this->actingAs($manager, 'web');
    $token = staffGalleryUnlock($this, $profile);
    $this->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]))->assertOk();

    $this->actingAs($otherManager, 'web')
        ->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]))
        ->assertOk()
        ->assertSee('Protected gallery');

    $this->travel(61)->minutes();
    $this->actingAs($manager, 'web')
        ->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]))
        ->assertOk()
        ->assertSee('Protected gallery');
});

test('updating the gallery PIN revokes staff tokens and does not throttle incorrect attempts', function () {
    $manager = staffGalleryProfile('PIN Change Manager', 'sm');
    $profile = staffGalleryProfile('PIN Change Subject');
    $admin = Admin::query()->create([
        'name' => 'PIN Change Administrator',
        'email' => 'pin-change-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    staffGalleryConfigurePin();

    $this->actingAs($manager, 'web');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $this->postJson(route('sm.staff.gallery.unlock', $profile), ['pin' => '0000'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The PIN is incorrect.');
    }

    $token = staffGalleryUnlock($this, $profile);
    $this->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]))
        ->assertOk()
        ->assertSee('gallery');

    $this->actingAs($admin, 'admin')
        ->post(route('settings.person-image-pin.update'), [
            'image_pin' => '8174',
            'image_pin_confirmation' => '8174',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->actingAs($manager, 'web')
        ->get(route('sm.staff.gallery.index', ['user' => $profile, 'auth' => $token]))
        ->assertOk()
        ->assertSee('Protected gallery');
});

test('direct staff media endpoints require a valid token and enforce category grants', function () {
    Storage::fake('vault');
    $manager = staffGalleryProfile('Allowed Staff Manager', 'sm');
    $otherManager = staffGalleryProfile('Unapproved Staff Manager', 'sm');
    $profile = staffGalleryProfile('Private Media Subject');
    $category = $profile->staffGalleryCategories()->create(['title' => 'Restricted', 'is_locked' => true]);
    $path = 'staff-gallery/private-photo.jpg';
    $bytes = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAABP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AI8A/9k=');
    Storage::disk('vault')->put($path, $bytes);
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'private-photo.jpg',
        'stored_path' => $path,
        'extension' => 'jpg',
        'mime_type' => 'image/jpeg',
        'category' => 'image',
        'size' => strlen($bytes),
        'checksum' => hash('sha256', $bytes),
    ]);
    $image = $profile->staffGalleryImages()->create([
        'file_id' => $file->id,
        'path' => $path,
        'original_name' => 'private-photo.jpg',
        'type' => 'image',
    ]);
    $image->categories()->attach($category);
    $admin = Admin::query()->create([
        'name' => 'Gallery Access Administrator',
        'email' => 'staff-gallery-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    staffGalleryConfigurePin();

    $this->actingAs($admin, 'admin')
        ->putJson(route('admin.gallery-access.staff-gallery.lock', [$profile, $category]), ['locked' => true])
        ->assertOk()
        ->assertJsonPath('locked', true);
    $this->postJson(route('admin.gallery-access.staff-gallery.grant', [$profile, $category, $manager]))
        ->assertOk()
        ->assertJsonPath('granted', true);

    $this->actingAs($manager, 'web');
    $token = staffGalleryUnlock($this, $profile);
    $imageUrl = route('sm.staff.gallery.image', ['user' => $profile, 'image' => $image, 'auth' => $token]);
    $this->get(route('sm.staff.gallery.image', ['user' => $profile, 'image' => $image]))
        ->assertForbidden();
    $this->get($imageUrl)->assertOk();

    $this->actingAs($otherManager, 'web')
        ->get($imageUrl)
        ->assertForbidden();
});

test('staff managers may create limited staff accounts but cannot change credentials after creation', function () {
    $manager = staffGalleryProfile('Identity Manager', 'sm');
    $departmentId = Department::query()->where('code', 'cfm')->value('id');

    Livewire::actingAs($manager)
        ->test(StaffManagerDirectory::class)
        ->set('name', 'New Case Manager')
        ->set('email', 'new-case-manager@example.test')
        ->set('username', 'new-case-manager')
        ->set('departmentId', (string) $departmentId)
        ->set('password', 'initial-password-123')
        ->call('save')
        ->assertHasNoErrors();

    $created = User::query()->where('username', 'new-case-manager')->firstOrFail();
    expect($created->department)->toBe('cfm')
        ->and($created->account_status)->toBe('active')
        ->and(Hash::check('initial-password-123', $created->password))->toBeTrue();

    Livewire::actingAs($manager)
        ->test(StaffManagerDirectory::class)
        ->set('editingId', $created->id)
        ->set('name', 'Renamed Case Manager')
        ->set('email', 'new-case-manager@example.test')
        ->set('username', 'new-case-manager')
        ->set('password', 'replacement-password-123')
        ->call('save')
        ->assertHasErrors('password');

    $staffManagerDepartmentId = Department::query()->where('code', 'sm')->value('id');
    Livewire::actingAs($manager)
        ->test(StaffManagerDirectory::class)
        ->set('name', 'Second Staff Manager')
        ->set('email', 'second-staff-manager@example.test')
        ->set('username', 'second-staff-manager')
        ->set('departmentId', (string) $staffManagerDepartmentId)
        ->set('password', 'initial-password-456')
        ->call('save')
        ->assertHasErrors('departmentId');

    expect($created->fresh()->department)->toBe('cfm')
        ->and(Hash::check('initial-password-123', $created->fresh()->password))->toBeTrue();
});

test('Staff Management has its department workspace and excludes Admin controls', function () {
    $manager = staffGalleryProfile('Workspace Manager', 'sm');

    $this->actingAs($manager, 'web')
        ->get(route('sm.dashboard'))
        ->assertOk()
        ->assertSee('Staff Management')
        ->assertSee('My profile')
        ->assertSee('Report System')
        ->assertDontSee('Manage Departments')
        ->assertDontSee('System settings');
});

test('admin full staff directory keeps its non-active route and search progress indicator', function () {
    $admin = Admin::query()->create([
        'name' => 'Directory Administrator',
        'email' => 'directory-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    staffGalleryProfile('Directory Staff');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.staff.index', ['display' => 'full']))
        ->assertOk()
        ->assertSee('COMPACT VIEW')
        ->assertSee('NON-ACTIVE ACCOUNTS')
        ->assertSee('agency-staff-search-spinner', false)
        ->assertSee('Directory Staff');
});

test('staff-gallery upload transfers accept image and video intents only for staff managers', function () {
    $manager = staffGalleryProfile('Transfer Manager', 'sm');
    $profile = staffGalleryProfile('Upload Subject');
    $profileManager = staffGalleryProfile('Profile Manager', 'pm');

    $this->actingAs($manager, 'web')
        ->postJson(route('upload-transfers.initialize'), [
            'name' => 'staff-photo.jpg',
            'size' => 100,
            'attachment' => [
                'purpose' => RecordAttachmentTypes::STAFF_GALLERY,
                'record_id' => $profile->id,
                'commit_immediately' => true,
            ],
        ])
        ->assertCreated();

    $this->postJson(route('upload-transfers.initialize'), [
        'name' => 'staff-document.pdf',
        'size' => 100,
        'attachment' => [
            'purpose' => RecordAttachmentTypes::STAFF_GALLERY,
            'record_id' => $profile->id,
            'commit_immediately' => true,
        ],
    ])->assertUnprocessable();

    $this->actingAs($profileManager, 'web')
        ->postJson(route('upload-transfers.initialize'), [
            'name' => 'unauthorized.jpg',
            'size' => 100,
            'attachment' => [
                'purpose' => RecordAttachmentTypes::STAFF_GALLERY,
                'record_id' => $profile->id,
                'commit_immediately' => true,
            ],
        ])
        ->assertForbidden();
});
