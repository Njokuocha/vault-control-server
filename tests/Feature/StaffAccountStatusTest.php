<?php

use App\Livewire\Admin\InactiveStaffIndex;
use App\Livewire\Admin\StaffProfile;
use App\Models\Admin;
use App\Models\SecuritySessionPresence;
use App\Models\User;
use App\Services\StaffAccountStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createStaffStatusAdmin(): Admin
{
    return Admin::query()->create([
        'name' => 'Status Administrator',
        'email' => 'status-admin@example.test',
        'password' => 'a-secure-password',
    ]);
}

test('administrator can freeze staff for a half hour and revoke tracked database sessions', function () {
    config(['session.driver' => 'database']);
    $admin = createStaffStatusAdmin();
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $sessionId = 'staff-session-for-status-test';
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $staff->id,
        'ip_address' => '203.0.113.8',
        'user_agent' => 'Feature test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
    SecuritySessionPresence::query()->create([
        'session_id' => $sessionId,
        'guard' => 'web',
        'account_type' => 'staff',
        'account_id' => (string) $staff->id,
        'last_seen_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(StaffProfile::class, ['user' => $staff])
        ->call('openStatusModal')
        ->assertSee('Change account status')
        ->set('statusSelection', 'frozen')
        ->set('freezeHours', '0.5')
        ->call('saveStatus')
        ->assertHasNoErrors()
        ->assertDispatched('alert', type: 'success')
        ->assertDispatched('staff-status-close');

    expect($staff->fresh()->account_status)->toBe('frozen')
        ->and(now()->diffInMinutes($staff->fresh()->frozen_until))->toBeGreaterThanOrEqual(29)
        ->and(now()->diffInMinutes($staff->fresh()->frozen_until))->toBeLessThanOrEqual(30);
    $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
    $this->assertDatabaseMissing('security_session_presence', ['session_id' => $sessionId]);
});

test('staff freezes accept up to hundredths of an hour and reject shorter or more precise durations', function () {
    config(['session.driver' => 'database']);
    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);

    $component = Livewire::actingAs(createStaffStatusAdmin(), 'admin')
        ->test(StaffProfile::class, ['user' => $staff])
        ->assertSee('Applying account status… Please wait.')
        ->assertSee('wire:loading wire:target="saveStatus"', false)
        ->assertSee('min="0.1"', false)
        ->assertSee('step="0.01"', false)
        ->assertSee('data-freeze-hours-input', false)
        ->assertSee('Math.round(Number(freezeHours) * 60)', false)
        ->call('openStatusModal')
        ->set('statusSelection', 'frozen')
        ->set('freezeHours', '0.05')
        ->call('saveStatus')
        ->assertHasErrors('freezeHours');

    $component->set('freezeHours', '0.001')
        ->call('saveStatus')
        ->assertHasErrors('freezeHours');

    $component->set('freezeHours', '0.15')
        ->call('saveStatus')
        ->assertHasNoErrors();

    $secondsUntilExpiry = $staff->fresh()->frozen_until->getTimestamp() - now()->getTimestamp();
    expect($staff->fresh()->account_status)->toBe('frozen')
        ->and($secondsUntilExpiry)->toBeGreaterThanOrEqual(539)
        ->and($secondsUntilExpiry)->toBeLessThanOrEqual(540);
});

test('expired freezes are restored to active and clear their deadline', function () {
    $staff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'frozen',
        'frozen_until' => now()->subSecond(),
    ]);

    $updated = app(StaffAccountStatusService::class)->expireIfDue($staff);

    expect($updated->account_status)->toBe('active')
        ->and($updated->frozen_until)->toBeNull()
        ->and($staff->fresh()->account_status)->toBe('active');
});

test('non-active account directory lists and changes frozen and disabled staff', function () {
    config(['session.driver' => 'database']);
    $admin = createStaffStatusAdmin();
    $frozen = User::factory()->create([
        'name' => 'Frozen Officer',
        'department' => 'cfm',
        'account_status' => 'frozen',
        'frozen_until' => now()->addMinutes(30),
    ]);
    User::factory()->create([
        'name' => 'Disabled Officer',
        'department' => 'pm',
        'account_status' => 'disabled',
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.staff.inactive'))
        ->assertOk()
        ->assertSee('Frozen Officer')
        ->assertSee('NON-ACTIVE ACCOUNTS');

    $component = Livewire::actingAs($admin, 'admin')
        ->test(InactiveStaffIndex::class)
        ->call('openStatusModal', $frozen->id)
        ->set('statusSelection', 'active')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect($frozen->fresh()->account_status)->toBe('active');

    $component->call('openStatusModal', $frozen->id)
        ->set('statusSelection', 'frozen')
        ->set('freezeHours', '0.15')
        ->call('saveStatus')
        ->assertHasNoErrors();

    expect($frozen->fresh()->account_status)->toBe('frozen')
        ->and($frozen->fresh()->frozen_until->getTimestamp() - now()->getTimestamp())->toBeGreaterThanOrEqual(539);
});
