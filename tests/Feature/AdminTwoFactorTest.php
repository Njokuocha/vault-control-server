<?php

use App\Livewire\Admin\TwoFactorSettings;
use App\Livewire\LoginForm;
use App\Mail\AdminLoginCodeMail;
use App\Models\Admin;
use App\Models\User;
use App\Services\AdminTwoFactorService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vault.admin.email' => 'operator@example.test',
        'mail.default' => 'smtp',
        'mail.mailers.smtp.transport' => 'smtp',
        'mail.from.address' => 'vault@example.test',
        'mail.from.name' => 'Vault Agency',
    ]);
});

test('administrator can toggle email two-factor authentication from system settings', function () {
    $admin = Admin::query()->create([
        'name' => 'Two Factor Administrator',
        'email' => 'operator@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('settings.index'))
        ->assertOk()
        ->assertSee('ADMINISTRATOR SIGN-IN SECURITY')
        ->assertSee('Password-only sign-in is active')
        ->assertSee('aria-checked="false"', false);

    Livewire::actingAs($admin, 'admin')
        ->test(TwoFactorSettings::class)
        ->call('toggle')
        ->assertSet('enabled', true)
        ->assertDispatched('alert', type: 'success');

    expect(app(AdminTwoFactorService::class)->enabled())->toBeTrue();

    Livewire::actingAs($admin, 'admin')
        ->test(TwoFactorSettings::class)
        ->call('toggle')
        ->assertSet('enabled', false);
    expect(app(AdminTwoFactorService::class)->enabled())->toBeFalse();
});

test('two-factor settings are no longer a separate route or sidebar item', function () {
    $admin = Admin::query()->create([
        'name' => 'Two Factor Administrator',
        'email' => 'operator@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->actingAs($admin, 'admin')
        ->get('/admin/auth-security/two-factor')
        ->assertNotFound();

    $this->get(route('settings.index'))
        ->assertOk()
        ->assertDontSee('Two-factor access')
        ->assertSee('ADMINISTRATOR SIGN-IN SECURITY');
});

test('admin password sign-in requires an emailed code which survives a page refresh', function () {
    Mail::fake();
    $admin = Admin::query()->create([
        'name' => 'Two Factor Administrator',
        'email' => 'operator@example.test',
        'password' => 'a-secure-password',
    ]);
    app(SettingService::class)->set('admin_two_factor_enabled', '1');

    $login = Livewire::test(LoginForm::class)
        ->call('authenticate', $admin->email, 'a-secure-password', false)
        ->assertHasNoErrors()
        ->assertSet('otpPending', true);

    $this->assertGuest('admin');
    expect(session('admin_otp_challenge.expires_at'))->toBeGreaterThan(now()->timestamp)
        ->toBeLessThanOrEqual(now()->addMinutes(3)->timestamp);

    $code = null;
    Mail::assertSent(AdminLoginCodeMail::class, function (AdminLoginCodeMail $message) use (&$code): bool {
        $code = $message->code;

        return $message->hasTo('operator@example.test');
    });
    expect($code)->toMatch('/^\d{6}$/');

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Verify your identity')
        ->assertSee('data-otp-expiry', false)
        ->assertSee('o*******@example.test');

    Livewire::test(LoginForm::class)
        ->set('otpCode', $code)
        ->call('verifyOtp')
        ->assertRedirect(route('dashboard.index'));

    $this->assertAuthenticatedAs($admin, 'admin');
    expect(session()->has('admin_otp_challenge'))->toBeFalse();
});

test('incorrect and expired verification codes cannot authenticate the administrator', function () {
    Mail::fake();
    $admin = Admin::query()->create([
        'name' => 'Two Factor Administrator',
        'email' => 'operator@example.test',
        'password' => 'a-secure-password',
    ]);
    app(SettingService::class)->set('admin_two_factor_enabled', '1');

    $component = Livewire::test(LoginForm::class)
        ->call('authenticate', $admin->email, 'a-secure-password', false)
        ->assertSet('otpPending', true);

    $component->set('otpCode', '111111')
        ->call('verifyOtp')
        ->assertHasErrors('otpCode')
        ->assertSet('otpPending', true);
    $this->assertGuest('admin');

    $this->travel(4)->minutes();
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('EXPIRED');

    Livewire::test(LoginForm::class)
        ->set('otpCode', '111111')
        ->call('verifyOtp')
        ->assertHasErrors('otpCode')
        ->assertSet('otpPending', false);
    $this->assertGuest('admin');
});

test('traditional login requests also stop at the emailed code and mail misconfiguration fails closed', function () {
    Mail::fake();
    $admin = Admin::query()->create([
        'name' => 'Two Factor Administrator',
        'email' => 'operator@example.test',
        'password' => 'a-secure-password',
    ]);
    app(SettingService::class)->set('admin_two_factor_enabled', '1');

    $this->post(route('auth.attempt'), [
        'email' => $admin->email,
        'password' => 'a-secure-password',
    ])->assertRedirect(route('login'));
    Mail::assertSentTimes(AdminLoginCodeMail::class, 1);
    $this->assertGuest('admin');
    $this->get(route('login'))->assertSee('Verify your identity');

    $this->post(route('auth.logout'));
    config(['mail.default' => 'log']);
    $second = Admin::query()->create([
        'name' => 'Second Administrator',
        'email' => 'second@example.test',
        'password' => 'a-secure-password',
    ]);
    config(['vault.admin.email' => $second->email]);
    $this->post(route('auth.attempt'), [
        'email' => $second->email,
        'password' => 'a-secure-password',
    ])->assertSessionHasErrors('email');
    $this->assertGuest('admin');
    expect(session()->has('admin_otp_challenge'))->toBeFalse();
    Mail::assertSentTimes(AdminLoginCodeMail::class, 1);
});

test('legacy administrators and staff cannot manage the admin two-factor control', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $this->actingAs($staff, 'web')
        ->get('/admin/auth-security/two-factor')
        ->assertNotFound();

    $legacyAdmin = User::factory()->create([
        'department' => null,
        'account_status' => 'active',
        'email' => 'operator@example.test',
    ]);
    $this->actingAs($legacyAdmin, 'web')
        ->get('/admin/auth-security/two-factor')
        ->assertNotFound();
});
