<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('guests are redirected to the login page before accessing the archive or settings', function () {
    $this->get('/')->assertRedirect(route('login'));
    $this->get(route('settings.index'))->assertRedirect(route('login'));
});

test('the login page uses the configured application name', function () {
    config(['app.name' => 'Secure Records']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Secure Records')
        ->assertSee('OPERATOR AUTHENTICATION')
        ->assertSeeLivewire(\App\Livewire\LoginForm::class)
        ->assertSee('x-on:submit.prevent', false);
});

test('authenticated users can access the upload and calculator workspaces', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard.index'))
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
        ->assertSee('data-shell-layer', false);
});

test('an administrator can authenticate and sign out', function () {
    config(['vault.admin.email' => 'admin@example.test']);

    $user = User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->post(route('auth.attempt'), [
        'email' => 'ADMIN@example.test',
        'password' => 'a-secure-password',
    ])->assertRedirect(route('dashboard.index'));

    $this->assertAuthenticatedAs($user);
    $this->get('/')
        ->assertOk()
        ->assertSee('SHELL')
        ->assertSee('Ctrl+Shift+X')
        ->assertSee('data-shell-window', false);

    $this->post(route('auth.logout'))->assertRedirect(route('login'));
    $this->assertGuest();
});

test('invalid login attempts are rejected', function () {
    config(['vault.admin.email' => 'admin@example.test']);

    User::factory()->create([
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
    $user = User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::test(\App\Livewire\LoginForm::class)
        ->call('authenticate', 'ADMIN@example.test', 'a-secure-password', true)
        ->assertRedirect(route('dashboard.index'));

    $this->assertAuthenticatedAs($user);
});

test('the Livewire login form reports invalid credentials without authenticating', function () {
    config(['vault.admin.email' => 'admin@example.test']);
    User::factory()->create([
        'email' => 'admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::test(\App\Livewire\LoginForm::class)
        ->call('authenticate', 'admin@example.test', 'incorrect-password', false)
        ->assertHasErrors('email')
        ->assertSee('ACCESS DENIED:')
        ->assertSee('Check your operator ID and access code.');

    $this->assertGuest();
});

test('the Livewire login form validates the submitted credentials', function () {
    Livewire::test(\App\Livewire\LoginForm::class)
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
    $this->actingAs(User::factory()->create());

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
    $this->actingAs(User::factory()->create())
        ->post(route('settings.upload-policy.update'), [
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
    $this->actingAs(User::factory()->create());

    Livewire::test(\App\Livewire\SettingsUploadPolicy::class)
        ->set('maxUploadMb', 34)
        ->set('maxVideoUploadMb', 18)
        ->set('apiUploadsEnabled', false)
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert');

    expect(Crypt::decryptString(Setting::query()->findOrFail('max_upload_mb')->value))->toBe('34')
        ->and(Crypt::decryptString(Setting::query()->findOrFail('max_video_upload_mb')->value))->toBe('18')
        ->and(Crypt::decryptString(Setting::query()->findOrFail('api_upload_enabled')->value))->toBe('0');
});

test('upload key rotation is connected to Livewire and persists only encrypted data', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(\App\Livewire\SettingsUploadKey::class)
        ->assertSee('wire:click="rotate"', false)
        ->assertSee('wire:confirm=', false)
        ->set('customKey', 'livewire-custom-upload-key')
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertDispatched('alert')
        ->assertSet('customKey', '');

    $setting = Setting::query()->findOrFail('upload_key');
    expect(Crypt::decryptString($setting->value))->toBe('livewire-custom-upload-key')
        ->and($setting->value)->not->toBe('livewire-custom-upload-key');
});

test('the Generate action creates and rotates to a secure upload key', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(\App\Livewire\SettingsUploadKey::class)
        ->call('generate')
        ->assertSet('customKey', fn (string $key): bool => strlen($key) === 64)
        ->call('rotate')
        ->assertHasNoErrors()
        ->assertDispatched('alert');

    $setting = Setting::query()->findOrFail('upload_key');
    expect(strlen(Crypt::decryptString($setting->value)))->toBe(64);
});

test('rotating the upload key without a custom value automatically generates a replacement', function () {
    $this->actingAs(User::factory()->create());
    app(\App\Services\SettingService::class)->set('upload_key', 'previous-upload-key');

    Livewire::test(\App\Livewire\SettingsUploadKey::class)
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
    $user = User::factory()->create(['password' => 'old-secure-password']);
    $this->actingAs($user);

    Livewire::test(\App\Livewire\SettingsImagePin::class)
        ->set('imagePin', '0427')
        ->set('imagePinConfirmation', '0427')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert');

    $pinHash = Crypt::decryptString(Setting::query()->findOrFail('person_image_pin_hash')->value);
    expect(Hash::check('0427', $pinHash))->toBeTrue();

    Livewire::test(\App\Livewire\SettingsPassword::class)
        ->set('currentPassword', 'old-secure-password')
        ->set('newPassword', 'new-secure-password')
        ->set('newPasswordConfirmation', 'new-secure-password')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('alert');

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
});

test('private broadcast authorization is restricted to the configured administrator', function () {
    config(['vault.admin.email' => 'admin@example.test']);
    $admin = User::factory()->create(['email' => 'admin@example.test']);
    $otherUser = User::factory()->create(['email' => 'other@example.test']);
    $channels = app(\Illuminate\Broadcasting\BroadcastManager::class)
        ->connection()
        ->getChannels();

    expect($channels['admin.vault']($admin))->toBeTrue()
        ->and($channels['admin.vault']($otherUser))->toBeFalse();

    $this->get('/broadcasting/auth')->assertRedirect(route('login'));
});

test('the administrator can update their password after verifying the current password', function () {
    $user = User::factory()->create([
        'password' => 'old-secure-password',
    ]);

    $this->actingAs($user)->post(route('settings.password.update'), [
        'current_password' => 'old-secure-password',
        'password' => 'new-secure-password',
        'password_confirmation' => 'new-secure-password',
    ])->assertRedirect();

    expect(Hash::check('new-secure-password', $user->fresh()->password))->toBeTrue();
    $this->assertAuthenticatedAs($user);
});

test('password changes require a valid current password and confirmation', function () {
    $user = User::factory()->create([
        'password' => 'old-secure-password',
    ]);

    $this->actingAs($user)->from(route('settings.index'))
        ->post(route('settings.password.update'), [
            'current_password' => 'incorrect-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'different-password',
        ])
        ->assertRedirect(route('settings.index'))
        ->assertSessionHasErrors(['current_password', 'password']);

    expect(Hash::check('old-secure-password', $user->fresh()->password))->toBeTrue();
});

test('seeding creates the configured administrator and an encrypted upload key', function () {
    config([
        'vault.admin.name' => 'Vault Administrator',
        'vault.admin.email' => 'vault-admin@example.test',
        'vault.admin.password' => 'a-long-seed-password',
        'vault.initial_upload_key' => 'a-configured-initial-upload-key',
    ]);

    $this->seed(\Database\Seeders\AdminSeeder::class);

    $admin = User::query()->where('email', 'vault-admin@example.test')->firstOrFail();
    $setting = Setting::query()->findOrFail('upload_key');

    expect($admin->name)->toBe('Vault Administrator')
        ->and(Hash::check('a-long-seed-password', $admin->password))->toBeTrue()
        ->and(Crypt::decryptString($setting->value))->toBe('a-configured-initial-upload-key');
});
