<?php

use App\Livewire\Admin\StaffManagement;
use App\Livewire\DepartmentLoginForm;
use App\Livewire\DepartmentPassword;
use App\Models\Admin;
use App\Models\Department;
use App\Models\File as VaultFile;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('department entry points offer separate username login surfaces', function () {
    foreach ([
        'cfm' => 'Case File Management',
        'pm' => 'Profile Management',
        'fm' => 'File Management',
    ] as $department => $name) {
        $this->get(route($department.'.login'))
            ->assertOk()
            ->assertSee($name)
            ->assertSee('USERNAME')
            ->assertSee('wire:navigate', false);
    }
});

test('a staff account can only authenticate into its assigned department', function () {
    $staff = User::factory()->create([
        'username' => 'case_operator',
        'department' => 'cfm',
        'account_status' => 'active',
        'email' => 'case.operator@example.test',
        'password' => 'case-manager-password',
    ]);

    Livewire::test(DepartmentLoginForm::class, ['department' => 'cfm'])
        ->call('authenticate', 'CASE_OPERATOR', 'case-manager-password', true)
        ->assertRedirect('/cfm');

    $this->assertAuthenticatedAs($staff, 'web');
    $this->get('/cfm')
        ->assertOk()
        ->assertSee('Case File Management')
        ->assertSee('action="'.route('cfm.logout').'" x-data x-on:submit.prevent="window.vaultConfirm', false)
        ->assertSee('Sign out of your Case File Management session?', false);
    $this->get('/cfm/profile')->assertOk()->assertSee('case_operator')->assertSee('case.operator@example.test');
    $this->get('/pm')->assertForbidden();
    $this->get(route('admin.staff.index'))->assertForbidden();
});

test('frozen and disabled staff accounts cannot authenticate', function () {
    foreach (['frozen', 'disabled'] as $status) {
        User::factory()->create([
            'username' => 'staff.'.$status,
            'department' => 'pm',
            'account_status' => $status,
            'frozen_until' => $status === 'frozen' ? now()->addMinutes(30) : null,
            'password' => 'profile-manager-password',
        ]);

        Livewire::test(DepartmentLoginForm::class, ['department' => 'pm'])
            ->call('authenticate', 'staff.'.$status, 'profile-manager-password', false)
            ->assertHasErrors('username')
            ->assertSee($status === 'frozen'
                ? 'This account has been frozen. Contact your admin.'
                : 'This account has been disabled. Contact your admin.');

        $this->assertGuest('web');
    }
});

test('an expired staff freeze is cleared during login and normal access resumes', function () {
    $staff = User::factory()->create([
        'username' => 'staff.expired-freeze',
        'department' => 'pm',
        'account_status' => 'frozen',
        'frozen_until' => now()->subSecond(),
        'password' => 'profile-manager-password',
    ]);

    Livewire::test(DepartmentLoginForm::class, ['department' => 'pm'])
        ->call('authenticate', 'staff.expired-freeze', 'profile-manager-password', false)
        ->assertHasNoErrors();

    $this->assertAuthenticatedAs($staff, 'web');
    expect($staff->fresh()->account_status)->toBe('active')
        ->and($staff->fresh()->frozen_until)->toBeNull();
});

test('the central admin can create, update, reset, and delete department accounts', function () {
    $legacy = User::factory()->create(['email' => 'admin@example.test']);
    config(['vault.admin.email' => $legacy->email]);
    $admin = Admin::query()->create([
        'name' => $legacy->name,
        'email' => $legacy->email,
        'password' => $legacy->password,
    ]);
    $this->actingAs($legacy, 'web');
    $this->actingAs($admin, 'admin');

    $component = Livewire::test(StaffManagement::class)
        ->set('name', 'Case Manager')
        ->set('email', 'case.manager@example.test')
        ->set('username', 'case_manager')
        ->set('departmentId', (string) Department::query()->where('code', 'cfm')->value('id'))
        ->set('password', 'initial-case-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success');

    $staff = User::query()->where('username', 'case_manager')->firstOrFail();
    expect($staff->department)->toBe('cfm')
        ->and($staff->departmentRecord->name)->toBe('Case File Management')
        ->and($staff->account_status)->toBe('active')
        ->and(Hash::check('initial-case-password', $staff->password))->toBeTrue();

    $component->set('search', 'case_manager')
        ->set('departmentFilter', (string) $staff->department_id)
        ->assertSee('case_manager')
        ->assertSee('RESET PASSWORD');

    $component->call('edit', $staff->id)
        ->set('name', 'Updated Case Manager')
        ->call('save')
        ->assertHasNoErrors();
    expect($staff->fresh()->name)->toBe('Updated Case Manager')
        ->and($staff->fresh()->account_status)->toBe('active');

    expect($staff->fresh()->account_status)->toBe('active');

    $component->call('openPasswordReset', $staff->id)
        ->assertSee('Set a new password')
        ->assertSee('Confirm new password')
        ->set('newPassword', 'replacement-staff-password')
        ->set('newPasswordConfirmation', 'replacement-staff-password')
        ->call('resetPassword')
        ->assertHasNoErrors();
    expect(Hash::check('replacement-staff-password', $staff->fresh()->password))->toBeTrue();

    $component->call('openDeleteConfirmation', $staff->id)
        ->assertSee('Delete staff account?')
        ->call('delete', $staff->id)
        ->assertHasNoErrors();
    expect(User::query()->find($staff->id))->toBeNull();
});

test('the admin can manage private staff profile pictures', function () {
    Storage::fake('vault');
    $legacy = User::factory()->create(['email' => 'admin@example.test']);
    config(['vault.admin.email' => $legacy->email]);
    $admin = Admin::query()->create([
        'name' => $legacy->name,
        'email' => $legacy->email,
        'password' => $legacy->password,
    ]);
    $this->actingAs($legacy, 'web')->actingAs($admin, 'admin');

    Livewire::test(StaffManagement::class)
        ->set('name', 'Case Manager')
        ->set('email', 'case.manager@example.test')
        ->set('username', 'case_manager')
        ->set('departmentId', (string) Department::query()->where('code', 'cfm')->value('id'))
        ->set('password', 'initial-case-password')
        ->set('profilePhoto', UploadedFile::fake()->createWithContent(
            'portrait.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lREAAAAASUVORK5CYII=')
        ))
        ->call('save')
        ->assertHasNoErrors();

    $staff = User::query()->where('username', 'case_manager')->firstOrFail();
    expect($staff->profile_photo_path)->toStartWith('staff-profiles/'.$staff->id.'/');
    Storage::disk('vault')->assertExists($staff->profile_photo_path);

    $this->get(route('admin.staff.photo', $staff))->assertOk();
});

test('the profile page reveals only public staff identity fields', function () {
    $staff = User::factory()->create([
        'name' => 'Profile Officer',
        'username' => 'profile.officer',
        'email' => 'profile.officer@example.test',
        'department' => 'fm',
        'account_status' => 'active',
    ]);

    $this->actingAs($staff)->get('/fm/profile')
        ->assertOk()
        ->assertSee('Profile Officer')
        ->assertSee('profile.officer')
        ->assertSee('profile.officer@example.test')
        ->assertSee('Change password')
        ->assertDontSee('internal notes');
});

test('case file management uses the admin-style sidebar, live clock, and readable footer', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);

    $this->actingAs($staff)->get('/cfm')
        ->assertOk()
        ->assertSee('id="sidebar"', false)
        ->assertSee('class="nav-item', false)
        ->assertSee('data-clock', false)
        ->assertSee('CASE MANAGER')
        ->assertSee('RESTRICTED · AUTHORIZED PERSONNEL ONLY');
});

test('case manager profile presents readable identity details and a boxed username', function () {
    $staff = User::factory()->create([
        'name' => 'Case Profile Officer',
        'username' => 'case.profile',
        'email' => 'case.profile@example.test',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);

    $this->actingAs($staff)->get('/cfm/profile')
        ->assertOk()
        ->assertSee('cfm-profile-card', false)
        ->assertSee('cfm-profile-picture', false)
        ->assertSee('AGENCY USERNAME')
        ->assertSee('case.profile')
        ->assertSee('case.profile@example.test')
        ->assertSee('CASE FILE MANAGEMENT')
        ->assertSee('Change password')
        ->assertSee('wire:submit="save"', false);
});

test('case managers can change their password through the profile Livewire form', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
        'password' => 'current-case-password',
    ]);

    $this->actingAs($staff);

    Livewire::test(DepartmentPassword::class)
        ->set('currentPassword', 'incorrect-password')
        ->set('newPassword', 'updated-case-password')
        ->set('newPasswordConfirmation', 'updated-case-password')
        ->call('save')
        ->assertHasErrors('currentPassword')
        ->set('currentPassword', 'current-case-password')
        ->set('newPasswordConfirmation', 'different-password')
        ->call('save')
        ->assertHasErrors('newPassword')
        ->set('newPasswordConfirmation', 'updated-case-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success', message: 'Your password has been updated.');

    expect(Hash::check('updated-case-password', $staff->fresh()->password))->toBeTrue();
});

test('profile management gets an isolated profile workspace with complete person CRUD', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-PM00001',
        'name' => 'Profile Subject',
    ]);
    $case = InvestigationCase::query()->create([
        'public_id' => 'CSE-PM00001',
        'title' => 'Restricted Case Title',
    ]);
    $person->cases()->attach($case);
    $this->actingAs($staff, 'web');

    $this->get('/pm')
        ->assertOk()
        ->assertSee('PROFILE MANAGEMENT WORKSPACE')
        ->assertSee('ALL PROFILES')
        ->assertSee('action="'.route('pm.logout').'" x-data x-on:submit.prevent="window.vaultConfirm', false)
        ->assertSee('x-on:submit.prevent', false)
        ->assertSee('destructive: true', false)
        ->assertSee('Sign out of your Profile Management session?', false);
    $this->get(route('pm.people.index'))
        ->assertOk()
        ->assertSee($person->public_id)
        ->assertSee('GALLERY ITEM')
        ->assertDontSee('LINKED CASE');
    $this->get(route('pm.people.show', $person))
        ->assertOk()
        ->assertSee('Profile Subject')
        ->assertDontSee('Linked case files')
        ->assertDontSee('Restricted Case Title');
    $this->get(route('pm.people.edit', $person))
        ->assertOk()
        ->assertSee('SAVING PROFILE…')
        ->assertSee(route('pm.people.update', $person), false);

    $this->put(route('pm.people.update', $person), ['name' => 'Updated Profile Subject'])
        ->assertRedirect(route('pm.people.show', $person));
    expect($person->fresh()->name)->toBe('Updated Profile Subject');

    $response = $this->post(route('pm.people.store'), ['name' => 'New Profile Subject']);
    $created = Person::query()->where('name', 'New Profile Subject')->firstOrFail();
    $response->assertRedirect(route('pm.people.show', $created));
    $this->assertAuthenticatedAs($staff, 'web');

    $this->get('/pm/cases')->assertNotFound();
    $this->get(route('cases.index'))->assertForbidden();
    $this->get(route('people.index'))->assertForbidden();
    $this->get('/cfm')->assertForbidden();
});

test('profile management dashboard distinguishes creations from later profile edits', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $existing = Person::query()->create([
        'public_id' => 'USR-PM00002',
        'name' => 'Existing Profile',
    ]);
    $existing->forceFill([
        'created_at' => now()->subMonth(),
        'updated_at' => now()->subMonth(),
    ])->saveQuietly();
    Person::query()->create([
        'public_id' => 'USR-PM00003',
        'name' => 'New Profile',
    ]);
    $this->actingAs($staff, 'web');

    $this->get('/pm')->assertViewHas('summary', fn (array $summary): bool => $summary['created_this_month'] === 1
        && $summary['updated_this_month'] === 0);

    $this->put(route('pm.people.update', $existing), ['name' => 'Edited Existing Profile'])
        ->assertRedirect(route('pm.people.show', $existing));

    $this->get('/pm')->assertViewHas('summary', fn (array $summary): bool => $summary['created_this_month'] === 1
        && $summary['updated_this_month'] === 1);
});

test('profile managers can change their password through the Livewire profile form', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
        'password' => 'current-profile-password',
    ]);

    $this->actingAs($staff, 'web')->get('/pm/profile')
        ->assertOk()
        ->assertSee('PROFILE MANAGER')
        ->assertSee('Change password')
        ->assertSee('wire:submit="save"', false);

    Livewire::test(DepartmentPassword::class)
        ->set('currentPassword', 'current-profile-password')
        ->set('newPassword', 'updated-profile-password')
        ->set('newPasswordConfirmation', 'updated-profile-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success');

    expect(Hash::check('updated-profile-password', $staff->fresh()->password))->toBeTrue();
});

test('file managers can access their restricted archive, upload, media and shell tools', function () {
    config(['vault.max_upload_mb' => 10]);
    app(SettingService::class)->set('max_upload_mb', '10');
    Storage::fake('local');
    Storage::fake('vault');

    $staff = User::factory()->create([
        'department' => 'fm',
        'account_status' => 'active',
    ]);

    $person = Person::query()->create([
        'public_id' => 'USR-FM-001',
        'name' => 'Restricted Person Name',
        'statement' => 'Confidential dossier statement',
        'main_image_path' => 'people/private/main.png',
    ]);
    Storage::disk('vault')->put('people/private/main.png', 'private image bytes');
    $person->images()->create([
        'path' => 'people/private/gallery.png',
        'original_name' => 'gallery-image.png',
        'type' => 'image',
    ]);
    Storage::disk('vault')->put('people/private/gallery.png', 'private gallery bytes');
    $fileContents = 'private archive file';
    Storage::disk('vault')->put('archive/private-note.txt', $fileContents);
    $file = VaultFile::query()->create([
        'uuid' => (string) Str::uuid(),
        'original_name' => 'private-note.txt',
        'stored_path' => 'archive/private-note.txt',
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'category' => 'text',
        'size' => strlen($fileContents),
        'checksum' => hash('sha256', $fileContents),
        'is_editable' => true,
        'version' => 1,
    ]);

    $this->actingAs($staff, 'web')
        ->getJson(route('fm.archive.files'))
        ->assertOk()
        ->assertJsonPath('records.0.preview_url', route('fm.files.preview', $file->uuid))
        ->assertJsonPath('records.0.download_url', route('fm.files.download', $file->uuid));

    $dashboard = $this->actingAs($staff, 'web')
        ->get('/fm')
        ->assertOk()
        ->assertSee('File archive')
        ->assertSee('TOTAL FILES')
        ->assertSee('private-note.txt')
        ->assertSee('Manual upload')
        ->assertSee('action="'.route('fm.logout').'" x-data x-on:submit.prevent="window.vaultConfirm', false)
        ->assertSee('Sign out of your File Management session?', false);
    $dashboard->assertSee(route('fm.files.download', $file->uuid), false);
    $this->get(route('fm.uploads.create'))
        ->assertOk()
        ->assertSee('data-transfer-initialize-url', false)
        ->assertSee(route('fm.uploads.create'), false);
    $this->get(route('fm.person-media.index'))
        ->assertOk()
        ->assertSee('MEDIA COLLECTION')
        ->assertSee('2 protected item(s)')
        ->assertDontSee('Restricted Person Name')
        ->assertDontSee('Confidential dossier statement');
    $this->get(route('fm.person-media.show', $person))
        ->assertOk()
        ->assertSee('Viewables')
        ->assertSee('Protected image')
        ->assertSee('data-vault-image-viewer', false)
        ->assertSee('data-vault-image-zoom-in', false)
        ->assertSee('data-vault-image-grayscale', false)
        ->assertDontSee('Restricted Person Name')
        ->assertDontSee('Confidential dossier statement');
    $this->getJson(route('fm.files.preview', $file->uuid))
        ->assertOk()
        ->assertJsonPath('download_url', route('fm.files.download', $file->uuid))
        ->assertJsonPath('stream_url', route('fm.files.stream', $file->uuid));
    $this->get(route('fm.files.stream', $file->uuid))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    $this->get(route('files.preview', $file->uuid))->assertForbidden();
    $this->getJson(route('fm.shell.directory', ['path' => '/root']))
        ->assertOk()
        ->assertJsonPath('path', '/root');
    $this->getJson(route('shell.directory', ['path' => '/root']))->assertForbidden();
    $this->get('/fm/cases')->assertNotFound();
    $this->get('/pm')->assertForbidden();
    $this->get(route('cases.index'))->assertForbidden();
    $this->get(route('people.index'))->assertForbidden();

    Livewire::test(DepartmentPassword::class)
        ->set('currentPassword', 'password')
        ->set('newPassword', 'updated-file-manager-password')
        ->set('newPasswordConfirmation', 'updated-file-manager-password')
        ->call('save')
        ->assertHasNoErrors();

    expect(Hash::check('updated-file-manager-password', $staff->fresh()->password))->toBeTrue();
});

test('file managers can unlock only protected person media without dossier details', function () {
    Storage::fake('vault');
    $staff = User::factory()->create([
        'department' => 'fm',
        'account_status' => 'active',
    ]);
    $person = Person::query()->create([
        'public_id' => 'USR-FM-002',
        'name' => 'Private Identity Subject',
        'statement' => 'Do not expose dossier content.',
        'main_image_path' => 'people/protected/main.png',
    ]);
    $document = $person->images()->create([
        'path' => 'people/protected/private-document.pdf',
        'original_name' => 'sensitive-dossier.pdf',
        'type' => 'document',
    ]);
    Storage::disk('vault')->put('people/protected/main.png', 'protected-image');
    app(SettingService::class)->set('person_image_pin_hash', Hash::make('4826'));

    $this->actingAs($staff, 'web');
    $imageUrl = route('fm.person-media.image', ['person' => $person->id, 'image' => 'main']);
    $this->get($imageUrl)->assertForbidden();
    $this->postJson(route('fm.person-media.unlock', $person), ['pin' => '4826'])
        ->assertOk()
        ->assertJsonPath('status', 'unlocked');
    $this->get($imageUrl)->assertOk();
    $this->get(route('fm.person-media.image', ['person' => $person->id, 'image' => $document->id]))->assertNotFound();
    $this->get(route('fm.person-media.show', $person))
        ->assertOk()
        ->assertDontSee('Private Identity Subject')
        ->assertDontSee('Do not expose dossier content.')
        ->assertDontSee('sensitive-dossier.pdf');
});

test('an unassigned non-admin user cannot enter the admin workspace', function () {
    config(['vault.admin.email' => 'configured-admin@example.test']);
    $unassigned = User::factory()->create([
        'email' => 'unassigned@example.test',
        'department' => null,
    ]);

    $this->actingAs($unassigned, 'web')->get('/admin')->assertForbidden();
});
