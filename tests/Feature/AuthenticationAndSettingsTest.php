<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;

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
        ->assertSee('OPERATOR AUTHENTICATION');
});

test('authenticated users can access the upload and calculator workspaces', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('uploads.create'))
        ->assertOk()
        ->assertSee('Add files to the vault')
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
