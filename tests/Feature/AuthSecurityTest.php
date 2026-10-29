<?php

use App\Events\SecurityActivityCreated;
use App\Events\SecurityMetricsUpdated;
use App\Livewire\Admin\BlacklistedIpIndex;
use App\Livewire\Admin\WhitelistedIpIndex;
use App\Livewire\SecuritySettings;
use App\Livewire\WhitelistIpPolicyToggle;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\BlacklistedIp;
use App\Models\BruteForceRecord;
use App\Models\SecurityLocation;
use App\Models\SecurityMetricsSnapshot;
use App\Models\SecuritySessionPresence;
use App\Models\User;
use App\Models\WhitelistedIp;
use App\Services\AdminLoginService;
use App\Services\AdminTwoFactorService;
use App\Services\AuthSecurityService;
use App\Services\DepartmentLoginService;
use App\Services\SecuritySessionMetrics;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Event::fake([SecurityActivityCreated::class, SecurityMetricsUpdated::class]);
});

test('authentication attempts are audited and repeated failures create brute-force and blacklist records by IP', function () {
    $security = app(AuthSecurityService::class);

    foreach (range(1, 5) as $attempt) {
        $security->recordAttempt(
            'web',
            null,
            null,
            '203.0.113.81',
            18 + $attempt,
            false,
            'Invalid credentials.',
            'Security test client',
        );
    }

    expect(AuthEvent::query()->count())->toBe(5)
        ->and(AuthEvent::query()->first()->account_name)->toBe('Unknown')
        ->and(AuthEvent::query()->first()->account_id)->toBeNull()
        ->and(AuthEvent::query()->where('is_brute_force', true)->count())->toBe(3)
        ->and(BruteForceRecord::query()->count())->toBe(3)
        ->and(BlacklistedIp::query()->where('ip_address', '203.0.113.81')->exists())->toBeTrue()
        ->and(BlacklistedIp::query()->where('ip_address', '203.0.113.81')->value('source'))->toBe('automatic')
        ->and(ActivityLog::query()->where('category', 'authentication')->count())->toBe(5);
});

test('whitelist enforcement is shared, supports empty-list fallback, and never overrides blacklists', function () {
    $security = app(AuthSecurityService::class);
    $settings = app(SettingService::class);
    $allowedIp = '203.0.113.91';
    $unknownIp = '203.0.113.92';
    $blockedIp = '203.0.113.93';

    $settings->set('whitelist_ip_enforcement', '1');
    expect($security->isIpAllowed($unknownIp))->toBeTrue();

    WhitelistedIp::query()->create(['ip_address' => $allowedIp]);
    expect($security->isIpAllowed($allowedIp))->toBeTrue()
        ->and($security->isIpAllowed($unknownIp))->toBeFalse();

    BlacklistedIp::query()->create([
        'ip_address' => $allowedIp,
        'failed_attempts' => null,
        'triggered_at' => null,
        'reason' => 'Manual security block.',
        'source' => 'manual',
    ]);
    expect($security->isIpAllowed($allowedIp))->toBeFalse();

    $settings->set('whitelist_ip_enforcement', '0');
    expect($security->isIpAllowed($unknownIp))->toBeTrue()
        ->and($security->isIpAllowed($allowedIp))->toBeFalse();

    WhitelistedIp::query()->delete();
    $settings->set('whitelist_ip_enforcement', '1');
    expect($security->isIpAllowed($unknownIp))->toBeTrue();

    BlacklistedIp::query()->create([
        'ip_address' => $blockedIp,
        'failed_attempts' => null,
        'triggered_at' => null,
        'reason' => 'Manual security block.',
        'source' => 'manual',
    ]);
    expect($security->isIpAllowed($blockedIp))->toBeFalse();
});

test('administrators can manually blacklist an IP and the nullable automatic fields render clearly', function () {
    $admin = Admin::query()->create([
        'name' => 'Security Administrator',
        'email' => 'security-admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(BlacklistedIpIndex::class)
        ->call('openAddModal')
        ->set('ipAddress', '203.0.113.94')
        ->set('reason', 'Investigation requested')
        ->call('add')
        ->assertHasNoErrors();

    $record = BlacklistedIp::query()->where('ip_address', '203.0.113.94')->firstOrFail();
    expect($record->source)->toBe('manual')
        ->and($record->failed_attempts)->toBeNull()
        ->and($record->triggered_at)->toBeNull()
        ->and($record->reason)->toBe('Investigation requested');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.security.blacklisted-ips'))
        ->assertOk()
        ->assertSee('Manually added')
        ->assertSee('Unknown');
});

test('whitelist management and settings use the same administrator toggle', function () {
    $admin = Admin::query()->create([
        'name' => 'Security Administrator',
        'email' => 'security-admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(WhitelistedIpIndex::class)
        ->call('openAddModal')
        ->set('ipAddress', '203.0.113.95')
        ->call('add')
        ->assertHasNoErrors();

    Livewire::actingAs($admin, 'admin')
        ->test(WhitelistIpPolicyToggle::class)
        ->call('toggle')
        ->assertSet('enabled', true);

    expect(app(AuthSecurityService::class)->isIpAllowed('203.0.113.95'))->toBeTrue()
        ->and(app(AuthSecurityService::class)->isIpAllowed('203.0.113.96'))->toBeFalse();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.security.whitelisted-ips'))
        ->assertOk()
        ->assertSee('Whitelisted IP Addresses')
        ->assertSee('203.0.113.95');

    $this->actingAs($admin, 'admin')
        ->get(route('settings.index'))
        ->assertOk()
        ->assertSee('WHITELIST-ONLY AUTHENTICATION');

    $record = WhitelistedIp::query()->where('ip_address', '203.0.113.95')->firstOrFail();
    Livewire::actingAs($admin, 'admin')
        ->test(WhitelistedIpIndex::class)
        ->call('confirmRemove', $record->id)
        ->call('remove', $record->id)
        ->assertHasNoErrors();

    expect(WhitelistedIp::query()->find($record->id))->toBeNull()
        ->and(app(AuthSecurityService::class)->isIpAllowed('203.0.113.96'))->toBeTrue();
});

test('non-whitelisted admin, department, and OTP sign-ins share a generic failure', function () {
    config(['vault.admin.email' => 'operator@example.test']);
    $admin = Admin::query()->create([
        'name' => 'Security Administrator',
        'email' => 'operator@example.test',
        'password' => 'a-secure-password',
    ]);
    $staff = User::factory()->create([
        'username' => 'officer',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    app(SettingService::class)->set('whitelist_ip_enforcement', '1');
    WhitelistedIp::query()->create(['ip_address' => '203.0.113.97']);
    $deniedIp = '203.0.113.98';
    $expectedMessage = 'Due to a system-level authentication algorithm, you are unable to log in.';

    try {
        app(AdminLoginService::class)->attempt($admin->email, 'a-secure-password', false, $deniedIp);
        test()->fail('Expected Admin sign-in to be denied.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['email'][0])->toBe($expectedMessage);
    }

    try {
        app(DepartmentLoginService::class)->attempt('officer', 'a-secure-password', 'cfm', false, $deniedIp);
        test()->fail('Expected department sign-in to be denied.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['username'][0])->toBe($expectedMessage);
    }

    session(['admin_otp_challenge' => [
        'id' => 'security-test-challenge',
        'admin_id' => $admin->id,
        'code_hash' => Hash::make('123456'),
        'expires_at' => now()->addMinute()->timestamp,
        'remember' => false,
    ]]);
    try {
        app(AdminTwoFactorService::class)->verify('123456', $deniedIp);
        test()->fail('Expected OTP verification to be denied.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['otpCode'][0])->toBe($expectedMessage);
    }

    expect(AuthEvent::query()->where('ip_address', $deniedIp)->where('status', 'passed')->exists())->toBeFalse()
        ->and(session()->has('admin_otp_challenge'))->toBeFalse();
});

test('successful authentication resets consecutive brute-force detection while preserving the audit trail', function () {
    $security = app(AuthSecurityService::class);
    $ip = '203.0.113.82';

    $security->recordAttempt('web', null, null, $ip, 12, false, 'Invalid credentials.', null);
    $security->recordAttempt('web', null, null, $ip, 13, false, 'Invalid credentials.', null);
    $security->recordAttempt('web', null, null, $ip, 14, true, 'Authentication succeeded.', null);
    $lastFailure = $security->recordAttempt('web', null, null, $ip, 15, false, 'Invalid credentials.', null);

    expect($lastFailure->is_brute_force)->toBeFalse()
        ->and(BruteForceRecord::query()->where('ip_address', $ip)->exists())->toBeFalse()
        ->and(AuthEvent::query()->where('ip_address', $ip)->count())->toBe(4);
});

test('admin login records matched identities, results, and durations without credential values', function () {
    config(['vault.admin.email' => 'operator@example.test']);
    $admin = Admin::query()->create([
        'name' => 'Agency Operator',
        'email' => 'operator@example.test',
        'password' => 'a-strong-password',
    ]);

    expect(fn () => app(AdminLoginService::class)->attempt(
        $admin->email,
        'incorrect-password',
        false,
        '203.0.113.87',
    ))->toThrow(ValidationException::class);

    app(AdminLoginService::class)->attempt($admin->email, 'a-strong-password', false, '203.0.113.87');

    $events = AuthEvent::query()->where('ip_address', '203.0.113.87')->orderBy('id')->get();
    expect($events)->toHaveCount(2)
        ->and($events[0]->account_name)->toBe('Agency Operator')
        ->and($events[0]->account_id)->toBe((string) $admin->id)
        ->and($events[0]->status)->toBe('failed')
        ->and($events[1]->status)->toBe('passed')
        ->and($events->max('duration_ms'))->toBeGreaterThanOrEqual(0)
        ->and(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain('a-strong-password')
        ->and(json_encode(ActivityLog::query()->get()->toArray()))->not->toContain('incorrect-password');
});

test('auth security routes are admin-only and render the command center', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);

    $this->actingAs($staff, 'web')
        ->get(route('admin.security.index'))
        ->assertForbidden();

    $admin = Admin::query()->create([
        'name' => 'Security Administrator',
        'email' => 'security-admin@example.test',
        'password' => 'a-secure-password',
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.security.index'))
        ->assertOk()
        ->assertSee('Auth Security')
        ->assertSee('data-security-activity-badge data-count="0">0</span>', false)
        ->assertSee('Signal sweep')
        ->assertSee('Blacklisted IPs');

    $event = AuthEvent::query()->create([
        'guard' => 'web',
        'account_name' => 'Unknown',
        'ip_address' => '203.0.113.83',
        'duration_ms' => 17,
        'status' => 'failed',
        'is_brute_force' => false,
        'result_detail' => 'Invalid credentials.',
        'occurred_at' => now(),
    ]);
    $log = ActivityLog::query()->create([
        'category' => 'testing',
        'action' => 'test.recorded',
        'actor_name' => 'System',
        'summary' => 'Test activity record.',
        'occurred_at' => now(),
    ]);
    $bruteForce = BruteForceRecord::query()->create([
        'auth_event_id' => $event->id,
        'account_name' => 'Unknown',
        'ip_address' => $event->ip_address,
        'failed_attempts' => 3,
        'window_seconds' => 120,
        'detected_at' => now(),
    ]);

    $this->get(route('admin.security.auth-history'))->assertOk()->assertSee('Authentication History');
    $this->get(route('admin.security.activity'))->assertOk()->assertSee('Activity Logs');
    $this->get(route('admin.security.brute-force'))->assertOk()->assertSee('Brute-Force Attempts');
    $this->get(route('admin.security.blacklisted-ips'))->assertOk()->assertSee('Blacklisted IP Addresses');
    $this->get(route('admin.security.auth-event', $event))->assertOk()->assertSee('Authentication record');
    $this->get(route('admin.security.activity-detail', $log))->assertOk()->assertSee('Test activity record.');
    $this->get(route('admin.security.brute-force-detail', $bruteForce))->assertOk()->assertSee('Detected authentication pattern');
});

test('security settings safely extract map coordinates and allow removal', function () {
    $admin = Admin::query()->create([
        'name' => 'Security Administrator',
        'email' => 'security-admin@example.test',
        'password' => 'a-secure-password',
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(SecuritySettings::class)
        ->set('mapUrl', 'https://www.google.com/maps/@37.422000,-122.084000,15z')
        ->call('saveMap')
        ->assertHasNoErrors();

    $location = SecurityLocation::query()->findOrFail(1);
    expect($location->latitude)->toBe('37.422000')
        ->and($location->longitude)->toBe('-122.084000')
        ->and($location->embed_url)->toBe('https://www.google.com/maps?q=37.422000%2C-122.084000&output=embed');

    Livewire::actingAs($admin, 'admin')
        ->test(SecuritySettings::class)
        ->set('mapUrl', 'https://example.test/maps/@37.422,-122.084')
        ->call('saveMap')
        ->assertHasErrors('mapUrl');

    Livewire::actingAs($admin, 'admin')
        ->test(SecuritySettings::class)
        ->set('mapUrl', '')
        ->call('saveMap')
        ->assertHasNoErrors();

    expect(SecurityLocation::query()->find(1))->toBeNull();
});

test('active session snapshots count only fresh authenticated database sessions', function () {
    config(['session.driver' => 'database', 'session.table' => 'sessions']);
    $sessionId = 'security-session-test';
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => null,
        'ip_address' => '203.0.113.84',
        'user_agent' => 'Security test client',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);
    SecuritySessionPresence::query()->create([
        'session_id' => $sessionId,
        'guard' => 'web',
        'account_type' => 'staff',
        'account_id' => '42',
        'last_seen_at' => now(),
    ]);

    $this->artisan('security:snapshot-sessions')->assertExitCode(0);
    expect(SecurityMetricsSnapshot::query()->findOrFail(1)->active_accounts)->toBe(1);
    Event::assertDispatched(SecurityMetricsUpdated::class);

    DB::table('sessions')->where('id', $sessionId)->update([
        'last_activity' => now()->subMinutes(5)->timestamp,
    ]);
    SecuritySessionPresence::query()->where('session_id', $sessionId)->update([
        'last_seen_at' => now()->subMinutes(5),
    ]);
    $this->artisan('security:snapshot-sessions')->assertExitCode(0);
    expect(SecurityMetricsSnapshot::query()->findOrFail(1)->active_accounts)->toBe(1);

    DB::table('sessions')->where('id', $sessionId)->update([
        'last_activity' => now()->subSeconds(((int) config('session.lifetime', 120) * 60) + 1)->timestamp,
    ]);
    $this->artisan('security:snapshot-sessions')->assertExitCode(0);
    expect(SecurityMetricsSnapshot::query()->findOrFail(1)->active_accounts)->toBe(0);
});

test('authenticated presence refreshes session totals and emits metrics for login and logout', function () {
    config([
        'session.driver' => 'database',
        'session.table' => 'sessions',
        'vault.admin.email' => 'live-admin@example.test',
    ]);
    $admin = Admin::query()->create([
        'name' => 'Live Metrics Administrator',
        'email' => 'live-admin@example.test',
        'password' => 'a-strong-password',
    ]);

    $this->post(route('auth.attempt'), [
        'email' => $admin->email,
        'password' => 'a-strong-password',
    ])->assertRedirect(route('dashboard.index'));
    $this->get(route('dashboard.index'))->assertOk();

    $presence = SecuritySessionPresence::query()->where('account_id', (string) $admin->id)->firstOrFail();
    expect(SecuritySessionPresence::query()->where('account_id', (string) $admin->id)->exists())->toBeTrue()
        ->and(SecurityMetricsSnapshot::query()->findOrFail(1)->active_accounts)->toBe(1);
    Event::assertDispatched(SecurityMetricsUpdated::class, fn (SecurityMetricsUpdated $event): bool => $event->activeAccounts === 1);

    app(SecuritySessionMetrics::class)->forgetSession($presence->session_id);

    expect(SecuritySessionPresence::query()->where('session_id', $presence->session_id)->exists())->toBeFalse()
        ->and(SecurityMetricsSnapshot::query()->findOrFail(1)->active_accounts)->toBe(0);
    Event::assertDispatched(SecurityMetricsUpdated::class, fn (SecurityMetricsUpdated $event): bool => $event->activeAccounts === 0);
});

test('recent activity uses a custom paginator when more than ten records exist', function () {
    $admin = Admin::query()->create([
        'name' => 'Security Administrator',
        'email' => 'security-admin@example.test',
        'password' => 'a-secure-password',
    ]);
    foreach (range(1, 12) as $index) {
        ActivityLog::query()->create([
            'category' => 'testing',
            'action' => 'test.recorded',
            'actor_type' => 'system',
            'actor_name' => 'System',
            'summary' => "Test activity {$index}.",
            'occurred_at' => now()->subSeconds($index),
        ]);
    }

    $this->actingAs($admin, 'admin')
        ->get(route('admin.security.index'))
        ->assertOk()
        ->assertSee('Recent activity pagination')
        ->assertSee('Showing 1–10 of 12');
});

test('security cleanup prunes activity and threat records after their retention windows', function () {
    $expiredAuthEvent = AuthEvent::query()->create([
        'guard' => 'web',
        'account_name' => 'Unknown',
        'ip_address' => '203.0.113.85',
        'duration_ms' => 17,
        'status' => 'failed',
        'is_brute_force' => false,
        'occurred_at' => now()->subDays(31),
    ]);
    $expiredBruteForce = BruteForceRecord::query()->create([
        'auth_event_id' => $expiredAuthEvent->id,
        'account_name' => 'Unknown',
        'ip_address' => $expiredAuthEvent->ip_address,
        'failed_attempts' => 3,
        'window_seconds' => 120,
        'detected_at' => now()->subDays(31),
    ]);
    $expiredBlacklist = BlacklistedIp::query()->create([
        'ip_address' => '203.0.113.86',
        'failed_attempts' => 5,
        'triggered_at' => now()->subDays(31),
        'reason' => 'Expired test record.',
    ]);
    $expiredLog = ActivityLog::query()->create([
        'category' => 'testing',
        'action' => 'test.expired',
        'actor_name' => 'System',
        'summary' => 'Expired test activity.',
        'occurred_at' => now()->subDays(15),
    ]);

    $this->artisan('security:prune-records')->assertExitCode(0);

    expect(AuthEvent::query()->find($expiredAuthEvent->id))->toBeNull()
        ->and(BruteForceRecord::query()->find($expiredBruteForce->id))->toBeNull()
        ->and(BlacklistedIp::query()->find($expiredBlacklist->id))->toBeNull()
        ->and(ActivityLog::query()->find($expiredLog->id))->toBeNull();
});
