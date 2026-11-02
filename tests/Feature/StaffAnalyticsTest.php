<?php

use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\BruteForceRecord;
use App\Models\StaffSessionHistory;
use App\Models\User;
use App\Services\StaffSessionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createStaffAnalyticsAdmin(): Admin
{
    return Admin::query()->create([
        'name' => 'Analytics Administrator',
        'email' => 'analytics-admin@example.test',
        'password' => 'a-secure-password',
    ]);
}

function createStaffAnalyticsAuthEvent(User $staff, string $status, Carbon $occurredAt): AuthEvent
{
    return AuthEvent::query()->create([
        'guard' => 'web',
        'account_type' => 'staff',
        'account_id' => (string) $staff->getKey(),
        'account_name' => $staff->name,
        'ip_address' => '203.0.113.41',
        'duration_ms' => 28,
        'status' => $status,
        'is_brute_force' => false,
        'result_detail' => 'Staff analytics test event.',
        'occurred_at' => $occurredAt,
    ]);
}

test('staff management workspace shows security stat cards without analytics charts', function () {
    $manager = User::factory()->create([
        'department' => 'sm',
        'account_status' => 'active',
    ]);
    $active = User::factory()->create([
        'name' => 'Recently Authenticated Officer',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    User::factory()->create(['department' => 'pm', 'account_status' => 'frozen']);
    User::factory()->create(['department' => 'fm', 'account_status' => 'disabled']);

    createStaffAnalyticsAuthEvent($active, 'passed', now()->subMinutes(4));
    createStaffAnalyticsAuthEvent($active, 'failed', now()->subMinutes(3));
    BruteForceRecord::query()->create([
        'account_type' => 'staff',
        'account_id' => (string) $active->getKey(),
        'account_name' => $active->name,
        'ip_address' => '203.0.113.88',
        'failed_attempts' => 7,
        'window_seconds' => 120,
        'user_agent' => 'Security test client',
        'detected_at' => now()->subMinute(),
    ]);

    $this->actingAs($manager, 'web')
        ->get(route('sm.dashboard'))
        ->assertOk()
        ->assertSee('REGISTERED PERSONNEL')
        ->assertSee('FROZEN ACCOUNTS')
        ->assertSee('DISABLED ACCOUNTS')
        ->assertSee('REGISTERED DEPARTMENTS')
        ->assertSee('STAFF WITH ACTIVE SESSIONS')
        ->assertSee('LAST AUTHENTICATED STAFF')
        ->assertSee('Recently Authenticated Officer')
        ->assertSee('LATEST STAFF BRUTE-FORCE SIGNAL')
        ->assertSee('staff-manager-dashboard', false)
        ->assertSee('203.0.113.88')
        ->assertSee('Security test client')
        ->assertSee('OPEN STAFF DIRECTORY')
        ->assertSee('Session and authentication indicators reflect recorded activity; incident IP details are restricted to authenticated Staff Management personnel.')
        ->assertSee('<article class="glass-panel staff-overview-card', false)
        ->assertDontSee('<a class="glass-panel staff-overview-card', false)
        ->assertDontSee('<a class="staff-overview-person"', false)
        ->assertDontSee('staff-analytics-grid', false)
        ->assertDontSee('Highest time in application');
});

test('admin full staff display links to analytics and presents security charts', function () {
    config(['session.driver' => 'database']);

    $admin = createStaffAnalyticsAdmin();
    $activeStaff = User::factory()->create([
        'name' => 'Current Session Officer',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $historicalStaff = User::factory()->create([
        'name' => 'Long Session Officer',
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    createStaffAnalyticsAuthEvent($activeStaff, 'passed', now()->subHours(3));
    createStaffAnalyticsAuthEvent($historicalStaff, 'failed', now()->subDays(2));

    $sessionId = Str::random(40);
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $activeStaff->getKey(),
        'ip_address' => '203.0.113.18',
        'user_agent' => 'Analytics test session',
        'payload' => '',
        'last_activity' => now()->getTimestamp(),
    ]);
    DB::table('security_session_presence')->insert([
        'session_id' => $sessionId,
        'guard' => 'web',
        'account_type' => 'staff',
        'account_id' => (string) $activeStaff->getKey(),
        'last_seen_at' => now(),
        'created_at' => now()->subHours(3),
        'updated_at' => now(),
    ]);
    StaffSessionHistory::query()->create([
        'session_key' => hash('sha256', $sessionId),
        'staff_id' => $activeStaff->getKey(),
        'started_at' => now()->subHours(3),
        'last_seen_at' => now(),
        'ended_at' => null,
    ]);
    StaffSessionHistory::query()->create([
        'session_key' => hash('sha256', 'historical-staff-session'),
        'staff_id' => $historicalStaff->getKey(),
        'started_at' => now()->subDays(2)->subHours(5),
        'last_seen_at' => now()->subDays(2),
        'ended_at' => now()->subDays(2),
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.staff.index', ['display' => 'full']))
        ->assertOk()
        ->assertSee('agency-staff-grid--full', false)
        ->assertSee('VIEW ANALYTICS')
        ->assertSee(route('admin.staff.analytics'), false);

    $this->get(route('admin.staff.analytics'))
        ->assertOk()
        ->assertSee('Highest time in application')
        ->assertSee('Current Session Officer')
        ->assertSee('LOGGED IN NOW')
        ->assertSee('Passed vs. failed attempts')
        ->assertSee('Account status distribution')
        ->assertSee('staff-overview-grid', false)
        ->assertSee('staff-analytics-grid', false)
        ->assertSee('<article class="glass-panel staff-overview-card', false)
        ->assertDontSee('<a class="glass-panel staff-overview-card', false)
        ->assertDontSee('<a class="staff-overview-person"', false)
        ->assertDontSee('203.0.113.18');
});

test('staff session tracking records login duration and closes it on logout', function () {
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $sessionId = Str::random(40);
    $startedAt = now()->subHours(2);
    $history = app(StaffSessionHistoryService::class);

    $history->track($staff, $sessionId, $startedAt, now()->subMinute());
    $history->track($staff, $sessionId, $startedAt->copy()->subHour(), now());

    $session = StaffSessionHistory::query()->findOrFail(hash('sha256', $sessionId));
    expect($session->staff_id)->toBe($staff->getKey())
        ->and($session->started_at->toDateTimeString())->toBe($startedAt->toDateTimeString())
        ->and($session->ended_at)->toBeNull();

    $history->close($sessionId);

    expect($session->fresh()->ended_at)->not->toBeNull()
        ->and($session->fresh()->last_seen_at->toDateTimeString())->toBe($session->fresh()->ended_at->toDateTimeString());
});
