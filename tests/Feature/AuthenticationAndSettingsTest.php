<?php

use App\Livewire\LoginForm;
use App\Livewire\SettingsImagePin;
use App\Livewire\SettingsPassword;
use App\Livewire\SettingsUploadKey;
use App\Livewire\SettingsUploadPolicy;
use App\Livewire\Admin\DepartmentManagement;
use App\Models\Admin;
use App\Models\Department;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\AdminSeeder;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page before accessing the archive or settings', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('Vault Intelligence Agency')
        ->assertSee(route('cfm.login'))
        ->assertSee(route('login'))
        ->assertSee('agency-network__connections', false)
        ->assertSee('data-portal-navigation="Case File Management"', false)
        ->assertDontSee('ONE APPLICATION · ONE SERVER · CENTRAL MANAGEMENT');
    $this->get(route('dashboard.index'))->assertRedirect(route('login'));
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

test('the login page uses the configured application name', function () {
    config(['app.name' => 'Secure Records']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Secure Records')
        ->assertSee('OPERATOR AUTHENTICATION')
        ->assertSeeLivewire(LoginForm::class)
        ->assertSee('x-on:submit.prevent', false);
});

test('authenticated users can access the upload and calculator workspaces', function () {
    actingAsLegacyAdmin($this);

    $this->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('wire:navigate', false)
        ->assertSee('data-video-upload-widget', false);

    $this->get(route('uploads.create'))
        ->assertOk()
        ->assertSee('Add files to the vault')
        ->assertSee('wire:navigate.hover', false)
        ->assertSee('Calculator')
        ->assertSee('data-shell-layer', false)
        ->assertSee('shell-window-template', false);

    $this->get(route('calculator.index'))
        ->assertOk()
        ->assertSee('Precision')
        ->assertSee('SCIENTIFIC')
        ->assertSee('localStorage')
        ->assertSee('data-shell-layer', false);

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('vault-confirm-backdrop', false)
        ->assertSee('data-settings-key-rotate', false)
        ->assertSee('data-shell-layer', false);
});

test('an administrator can authenticate and sign out', function () {
    config(['vault.admin.email' => 'admin@example.test']);

    $admin = Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->post(route('auth.attempt'), [
        'email' => 'ADMIN@example.test',
        'password' => 'a-secure-password',
    ])->assertRedirect(route('dashboard.index'));

    $this->assertGuest('web');
    $this->assertAuthenticatedAs($admin, 'admin');
    $this->get(route('home'))->assertRedirect('/admin');
    $this->get(route('dashboard.index'))
        ->assertOk()
        ->assertSee('SHELL')
        ->assertSee('Ctrl+Shift+X')
        ->assertSee('data-shell-window', false)
        ->assertSee('action="'.route('auth.logout').'" x-data x-on:submit.prevent="window.vaultConfirm', false)
        ->assertSee('Sign out of your administrator session?', false);

    $this->post(route('auth.logout'))->assertRedirect(route('home'));
    $this->assertGuest();
    $this->assertGuest('admin');
});

test('an admin-only session can reach staff management and create a case manager', function () {
    config(['vault.admin.email' => 'admin@example.test']);
    $admin = Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->post(route('auth.attempt'), [
        'email' => $admin->email,
        'password' => 'a-secure-password',
    ])->assertRedirect(route('dashboard.index'));
    $this->assertGuest('web');
    $this->assertAuthenticatedAs($admin, 'admin');

    $this->get(route('admin.staff.index'))
        ->assertOk()
        ->assertSee('Create staff account')
        ->assertSee('MANAGE DEPARTMENTS');
    $this->get(route('admin.departments.index'))
        ->assertOk()
        ->assertSee('Department Management')
        ->assertSee('SAVE CHANGES')
        ->assertSee('Add department row');

    Livewire::test(\App\Livewire\Admin\StaffManagement::class)
        ->set('name', 'Case Officer')
        ->set('email', 'case.officer@example.test')
        ->set('username', 'case_officer')
        ->set('departmentId', (string) Department::query()->where('code', 'cfm')->value('id'))
        ->set('password', 'case-officer-password')
        ->call('save')
        ->assertHasNoErrors();

    $staff = User::query()->where('username', 'case_officer')->firstOrFail();
    expect($staff->department)->toBe('cfm')
        ->and($staff->account_status)->toBe('active')
        ->and(User::query()->where('email', 'admin@example.test')->exists())->toBeFalse();
});

test('authenticated staff are redirected from the public landing page to their department', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'department_id' => Department::query()->where('code', 'pm')->value('id'),
        'account_status' => 'active',
    ]);

    $this->actingAs($staff, 'web')->get(route('home'))->assertRedirect('/pm');
});

test('admins can create departments and assign staff through their department relationship', function () {
    $component = Livewire::test(DepartmentManagement::class)
        ->call('addRow')
        ->set('rows.3.name', 'Evidence Operations')
        ->set('rows.3.rank_short', 'EVO')
        ->set('rows.3.rank', 'Evidence Officer')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success');

    $department = Department::query()->where('code', 'evo')->firstOrFail();
    expect($department->name)->toBe('Evidence Operations')
        ->and($department->rank)->toBe('Evidence Officer')
        ->and($department->rank_short)->toBe('EVO');
    $this->get('/evo/login')
        ->assertOk()
        ->assertSee('Evidence Operations');

    $staff = User::factory()->create([
        'department' => $department->code,
        'account_status' => 'active',
    ]);

    expect($staff->department_id)->toBe($department->id);
    $this->actingAs($staff, 'web')
        ->get(route('home'))
        ->assertRedirect('/evo');
    $this->get('/evo')
        ->assertOk()
        ->assertSee('Evidence Operations')
        ->assertSee('This department workspace is active');
});

test('invalid login attempts are rejected', function () {
    config(['vault.admin.email' => 'admin@example.test']);

    Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->from(route('login'))
        ->post(route('auth.attempt'), [
            'email' => 'admin@example.test',
            'password' => 'incorrect-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('an administrator can authenticate through the Livewire login form', function () {
    config(['vault.admin.email' => 'admin@example.test']);
    $admin = Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::test(LoginForm::class)
        ->call('authenticate', 'ADMIN@example.test', 'a-secure-password', true)
        ->assertRedirect(route('dashboard.index'));

    $this->assertGuest('web');
    $this->assertAuthenticatedAs($admin, 'admin');
});

test('the Livewire login form reports invalid credentials without authenticating', function () {
    config(['vault.admin.email' => 'admin@example.test']);
    Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::test(LoginForm::class)
        ->call('authenticate', 'admin@example.test', 'incorrect-password', false)
        ->assertHasErrors('email')
        ->assertSee('ACCESS DENIED:')
        ->assertSee('Check your operator ID and access code.');

    $this->assertGuest();
});

test('the Livewire login form validates the submitted credentials', function () {
    Livewire::test(LoginForm::class)
        ->call('authenticate', 'not-an-email', '', false)
        ->assertHasErrors(['email' => 'email', 'password' => 'required']);

    $this->assertGuest();
});

test('accounts other than the configured administrator cannot authenticate', function () {
    User::factory()->create([
        'email' => 'another-user@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->from(route('login'))
        ->post(route('auth.attempt'), [
            'email' => 'another-user@example.test',
            'password' => 'a-secure-password',
        ])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('upload keys are encrypted at rest and can be rotated', function () {
    actingAsLegacyAdmin($this);

    $this->post(route('settings.key.reset'), [
        'upload_key' => 'custom-upload-key-value',
    ])->assertRedirect();

    $setting = Setting::query()->findOrFail('upload_key');

    expect($setting->value)->not->toBe('custom-upload-key-value')
        ->and(Crypt::decryptString($setting->value))->toBe('custom-upload-key-value');

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertSee('custom-upload-key-value')
        ->assertSee('API UPLOAD ENABLED')
        ->assertDontSee('HANDLER NOT CONFIGURED');
});

test('the administrator can update the upload size and API access policy', function () {
    actingAsLegacyAdmin($this);

    $this->post(route('settings.upload-policy.update'), [
            'max_upload_mb' => 25,
            'max_video_upload_mb' => 12,
            'api_upload_enabled' => '1',
        ])
        ->assertRedirect();

    $size = Setting::query()->findOrFail('max_upload_mb');
    $videoSize = Setting::query()->findOrFail('max_video_upload_mb');
    $apiAccess = Setting::query()->findOrFail('api_upload_enabled');

    expect(Crypt::decryptString($size->value))->toBe('25')
        ->and(Crypt::decryptString($videoSize->value))->toBe('12')
        ->and(Crypt::decryptString($apiAccess->value))->toBe('1');
});

test('upload policy settings save through Livewire and are encrypted immediately', function () {
    actingAsLegacyAdmin($this);

    Livewire::test(SettingsUploadPolicy::class)
        ->set('maxUploadMb', 34)
        ->set('maxVideoUploadMb', 18)
        ->set('apiUploadsEnabled', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success', message: 'Upload policy updated.');

    expect(Crypt::decryptString(Setting::query()->findOrFail('max_upload_mb')->value))->toBe('34')
        ->and(Crypt::decryptString(Setting::query()->findOrFail('max_video_upload_mb')->value))->toBe('18')
        ->and(Crypt::decryptString(Setting::query()->findOrFail('api_upload_enabled')->value))->toBe('0');
});

test('upload key rotation is connected to Livewire and persists only encrypted data', function () {
    actingAsLegacyAdmin($this);

    Livewire::test(SettingsUploadKey::class)
        ->assertSee('data-settings-key-rotate', false)
        ->assertDontSee('wire:confirm=', false)
        ->set('customKey', 'livewire-custom-upload-key')
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success', message: 'Upload key updated. The previous key is no longer valid.')
        ->assertSet('customKey', '');

    $setting = Setting::query()->findOrFail('upload_key');
    expect(Crypt::decryptString($setting->value))->toBe('livewire-custom-upload-key')
        ->and($setting->value)->not->toBe('livewire-custom-upload-key');
});

test('the Generate action creates and rotates to a secure upload key', function () {
    actingAsLegacyAdmin($this);

    Livewire::test(SettingsUploadKey::class)
        ->call('generate')
        ->assertSet('customKey', fn (string $key): bool => strlen($key) === 64)
        ->assertDispatched('alert', type: 'success', message: 'A secure key was generated. Save it to rotate the active key.')
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertDispatched('alert');

    $setting = Setting::query()->findOrFail('upload_key');
    expect(strlen(Crypt::decryptString($setting->value)))->toBe(64);
});

test('rotating the upload key without a custom value automatically generates a replacement', function () {
    actingAsLegacyAdmin($this);
    app(SettingService::class)->set('upload_key', 'previous-upload-key');

    Livewire::test(SettingsUploadKey::class)
        ->assertSee('Leave blank to generate a secure key')
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertDispatched('alert');

    $setting = Setting::query()->findOrFail('upload_key');
    $newKey = Crypt::decryptString($setting->value);

    expect(strlen($newKey))->toBe(64)
        ->and($newKey)->not->toBe('previous-upload-key');
});

test('image PIN and administrator password update through Livewire', function () {
    $user = actingAsLegacyAdmin($this, ['password' => 'old-secure-password']);
    $admin = Admin::query()->where('email', $user->email)->firstOrFail();

    Livewire::test(SettingsImagePin::class)
        ->set('imagePin', '0427')
        ->set('imagePinConfirmation', '0427')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success', message: 'Person image access PIN updated.');

    $pinHash = Crypt::decryptString(Setting::query()->findOrFail('person_image_pin_hash')->value);
    expect(Hash::check('0427', $pinHash))->toBeTrue();

    Livewire::test(SettingsPassword::class)
        ->set('currentPassword', 'old-secure-password')
        ->set('newPassword', 'new-secure-password')
        ->set('newPasswordConfirmation', 'new-secure-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success', message: 'Administrator password updated.');

    expect(Hash::check('new-secure-password', $admin->fresh()->password))->toBeTrue()
        ->and(Hash::check('old-secure-password', $user->fresh()->password))->toBeTrue();
});

test('private broadcast authorization is restricted to the configured administrator', function () {
    config(['vault.admin.email' => 'admin@example.test']);
    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $otherUser = User::factory()->create(['email' => 'other@example.test']);
    $channels = app(BroadcastManager::class)
        ->connection()
        ->getChannels();

    expect($channels['admin.vault']($admin))->toBeTrue()
        ->and($channels['admin.vault']($otherUser))->toBeFalse();

    $this->get('/broadcasting/auth')->assertRedirect(route('login'));
});

test('the administrator can update their password after verifying the current password', function () {
    $admin = Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'old-secure-password',
    ]);
    config(['vault.admin.email' => $admin->email]);

    $this->actingAs($admin, 'admin')->post(route('settings.password.update'), [
        'current_password' => 'old-secure-password',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertRedirect();

    expect(Hash::check('new-secure-password', $admin->fresh()->password))->toBeTrue();
    $this->assertGuest('web');
    $this->assertAuthenticatedAs($admin->fresh(), 'admin');
});

test('password changes require a valid current password and confirmation', function () {
    $admin = Admin::query()->create([
        'name' => 'Vault Administrator',
        'email' => 'admin@example.test',
        'password' => 'old-secure-password',
    ]);
    config(['vault.admin.email' => $admin->email]);

    $this->actingAs($admin, 'admin')->from(route('settings.index'))
        ->post(route('settings.password.update'), [
            'current_password' => 'incorrect-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'different-password',
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors(['current_password', 'password']);

    expect(Hash::check('old-secure-password', $admin->fresh()->password))->toBeTrue();
});

test('seeding creates the configured administrator and an encrypted upload key', function () {
    config([
        'vault.admin.name' => 'Vault Administrator',
        'vault.admin.email' => 'vault-admin@example.test',
        'vault.admin.password' => 'a-long-seed-password',
        'vault.initial_upload_key' => 'a-configured-initial-upload-key',
    ]);

    $this->seed(AdminSeeder::class);

    $centralAdmin = Admin::query()->where('email', 'vault-admin@example.test')->firstOrFail();
    $setting = Setting::query()->findOrFail('upload_key');

    expect($centralAdmin->name)->toBe('Vault Administrator')
        ->and(Hash::check('a-long-seed-password', $centralAdmin->password))->toBeTrue()
        ->and(User::query()->where('email', 'vault-admin@example.test')->exists())->toBeFalse()
        ->and(Crypt::decryptString($setting->value))->toBe('a-configured-initial-upload-key');
});
