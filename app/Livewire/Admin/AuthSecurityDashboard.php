<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\BlacklistedIp;
use App\Models\BruteForceRecord;
use App\Models\Department;
use App\Models\SecurityLocation;
use App\Models\SecurityMetricsSnapshot;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class AuthSecurityDashboard extends Component
{
    use WithPagination;

    #[On('refresh-auth-security')]
    public function refreshSecurityData(): void
    {
        $this->resetPage();
        session()->put('security_activity_last_seen_id', (int) ActivityLog::query()->max('id'));
    }

    #[On('refresh-security-metrics')]
    public function refreshSessionMetrics(): void {}

    public function render(): View
    {
        $snapshot = SecurityMetricsSnapshot::query()->find(1);
        $snapshotFresh = $snapshot?->captured_at?->greaterThanOrEqualTo(now()->subSeconds(180)) ?? false;
        $activeAccounts = $snapshotFresh ? (int) $snapshot->active_accounts : 0;
        $totalAccounts = User::query()->whereNotNull('department')->count() + Admin::query()->count();
        $since24Hours = now()->subDay();
        $today = now()->startOfDay();
        $connectedToDatabase = true;
        $bruteForceWindow = app(SettingService::class)->get('auth_brute_force_window_seconds')
            ?? config('auth_security.brute_force_window_seconds', 120);

        try {
            DB::connection()->getPdo();
        } catch (Throwable) {
            $connectedToDatabase = false;
        }

        return view('livewire.admin.auth-security-dashboard', [
            'activeAccounts' => $activeAccounts,
            'totalAccounts' => $totalAccounts,
            'activeRatio' => $totalAccounts === 0 ? 0 : min(100, (int) round($activeAccounts / $totalAccounts * 100)),
            'snapshotAt' => $snapshotFresh ? $snapshot?->captured_at : null,
            'connectedToDatabase' => $connectedToDatabase,
            'logs24Hours' => ActivityLog::query()->where('occurred_at', '>=', $since24Hours)->count(),
            'authAttemptsToday' => AuthEvent::query()->where('occurred_at', '>=', $today)->count(),
            'failedToday' => AuthEvent::query()->where('status', 'failed')->where('occurred_at', '>=', $today)->count(),
            'passedToday' => AuthEvent::query()->where('status', 'passed')->where('occurred_at', '>=', $today)->count(),
            'bruteForce30Days' => BruteForceRecord::query()->where('detected_at', '>=', now()->subDays(30))->count(),
            'departmentCount' => Department::query()->count(),
            'recentDepartments' => Department::query()->latest('created_at')->limit(3)->get(),
            'blacklistedIps' => BlacklistedIp::query()->latest('triggered_at')->limit(5)->get(),
            'authHistory' => AuthEvent::query()->where('occurred_at', '>=', $today)->latest('occurred_at')->limit(7)->get(),
            'activityLogs' => ActivityLog::query()->latest('occurred_at')->paginate(10, ['*'], 'activityPage'),
            'location' => SecurityLocation::query()->find(1),
            'bruteForceWindow' => (int) $bruteForceWindow,
            'isAdminAuthenticated' => Auth::guard('admin')->check() || Auth::guard('web')->check(),
        ])->extends('layouts.app', [
            'page_title' => 'Auth Security — '.config('app.name'),
            'page_label' => 'AUTH SECURITY',
        ])->section('content');
    }
}
