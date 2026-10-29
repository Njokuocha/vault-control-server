<?php

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createDirectoryAdmin(): Admin
{
    return Admin::query()->create([
        'name' => 'Directory Administrator',
        'email' => 'directory-admin@example.test',
        'password' => 'a-secure-password',
    ]);
}

test('department views list only staff assigned to the selected department', function () {
    $department = Department::query()->where('code', 'cfm')->firstOrFail();
    $caseManager = User::factory()->create([
        'name' => 'Case Manager Visible',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    User::factory()->create([
        'name' => 'Profile Manager Hidden',
        'department' => 'pm',
        'account_status' => 'active',
    ]);
    $this->actingAs(createDirectoryAdmin(), 'admin')
        ->get(route('admin.departments.staff', $department))
        ->assertOk()
        ->assertSee('Case Manager Visible')
        ->assertSee(route('admin.staff.show', $caseManager), false)
        ->assertDontSee('Profile Manager Hidden');
});

test('department management and auth security show links to the department roster', function () {
    $department = Department::query()->where('code', 'cfm')->firstOrFail();
    $this->actingAs(createDirectoryAdmin(), 'admin')
        ->get(route('admin.departments.index'))
        ->assertOk()
        ->assertSee(route('admin.departments.staff', $department), false)
        ->assertSee('VIEW');

    $this->get(route('admin.security.index'))
        ->assertOk()
        ->assertSee(route('admin.departments.staff', $department), false);
});

test('staff profile includes staff-scoped audit previews and full history routes', function () {
    $staff = User::factory()->create([
        'name' => 'Profile Officer',
        'username' => 'profile.officer',
        'email' => 'profile.officer@example.test',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $otherStaff = User::factory()->create([
        'department' => 'pm',
        'account_status' => 'active',
    ]);

    AuthEvent::query()->create([
        'guard' => 'web',
        'account_type' => 'staff',
        'account_id' => (string) $staff->id,
        'account_name' => $staff->name,
        'ip_address' => '203.0.113.91',
        'duration_ms' => 28,
        'status' => 'passed',
        'is_brute_force' => false,
        'result_detail' => 'Department authentication succeeded.',
        'occurred_at' => now(),
    ]);
    AuthEvent::query()->create([
        'guard' => 'web',
        'account_type' => 'staff',
        'account_id' => (string) $otherStaff->id,
        'account_name' => $otherStaff->name,
        'ip_address' => '203.0.113.92',
        'duration_ms' => 30,
        'status' => 'failed',
        'is_brute_force' => false,
        'result_detail' => 'Other staff event.',
        'occurred_at' => now(),
    ]);
    ActivityLog::query()->create([
        'category' => 'case-management',
        'action' => 'case.created',
        'actor_type' => 'staff',
        'actor_id' => (string) $staff->id,
        'actor_name' => $staff->name,
        'summary' => 'Created a new case record.',
        'ip_address' => '203.0.113.91',
        'occurred_at' => now(),
    ]);

    $this->actingAs(createDirectoryAdmin(), 'admin')
        ->get(route('admin.staff.show', $staff))
        ->assertOk()
        ->assertSee('Profile Officer')
        ->assertSee('profile.officer')
        ->assertSee('Authentication history')
        ->assertSee('Activity log')
        ->assertSee('Created a new case record.')
        ->assertSee(route('admin.staff.history', [$staff, 'authentication-history']), false)
        ->assertSee(route('admin.staff.history', [$staff, 'activity']), false)
        ->assertDontSee('Other staff event.');

    $this->get(route('admin.staff.history', [$staff, 'authentication-history']))
        ->assertOk()
        ->assertSee('Department authentication succeeded.')
        ->assertDontSee('Other staff event.');

    $this->get(route('admin.staff.history', [$staff, 'activity']))
        ->assertOk()
        ->assertSee('Created a new case record.');
});

test('staff avatars open their profiles and manage account opens a username-filtered staff list', function () {
    $staff = User::factory()->create([
        'name' => 'Manageable Officer',
        'username' => 'manageable.officer',
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    User::factory()->create([
        'name' => 'Unrelated Officer',
        'username' => 'unrelated.officer',
        'department' => 'pm',
        'account_status' => 'active',
    ]);

    $this->actingAs(createDirectoryAdmin(), 'admin')
        ->get(route('admin.staff.index'))
        ->assertOk()
        ->assertSee('agency-staff-row__photo-link', false)
        ->assertSee(route('admin.staff.show', $staff), false);

    $this->get(route('admin.staff.show', $staff))
        ->assertOk()
        ->assertSee(route('admin.staff.index', ['search' => $staff->username]), false);

    \Livewire\Livewire::withQueryParams(['search' => $staff->username])
        ->test(\App\Livewire\Admin\StaffManagement::class)
        ->assertSet('search', $staff->username)
        ->assertSee($staff->name)
        ->assertDontSee('Unrelated Officer');
});

test('admin and department login screens expose working password visibility controls', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('data-password-toggle', false)
        ->assertSee('id="access-code"', false);

    $this->get(route('cfm.login'))
        ->assertOk()
        ->assertSee('data-password-toggle', false)
        ->assertSee('id="access-code"', false);
});
