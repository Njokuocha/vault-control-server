<?php

namespace App\Services;

use App\Models\AuthEvent;
use App\Models\BruteForceRecord;
use App\Models\Department;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StaffManagementMetrics
{
    public function overview(): array
    {
        $staff = User::query()->whereNotNull('department');
        $activeStaffIds = $this->activeStaffSessions()
            ->pluck('account_id')
            ->map(fn (string $id): int => (int) $id)
            ->unique()
            ->values();
        $latestAuthentication = AuthEvent::query()
            ->where('guard', 'web')
            ->where('account_type', 'staff')
            ->where('status', 'passed')
            ->latest('occurred_at')
            ->first();
        $lastAuthenticatedStaff = $latestAuthentication !== null && ctype_digit((string) $latestAuthentication->account_id)
            ? User::query()->with('departmentRecord')->whereNotNull('department')->find((int) $latestAuthentication->account_id)
            : null;
        $latestBruteForce = BruteForceRecord::query()
            ->where('account_type', 'staff')
            ->latest('detected_at')
            ->first();
        $authWindow = now()->subDay();
        $bruteForceWindow = now()->subDays(30);

        return [
            'totalStaff' => (clone $staff)->count(),
            'activeStaff' => (clone $staff)->where(function ($query): void {
                $query->whereNull('account_status')->orWhere('account_status', 'active');
            })->count(),
            'frozenStaff' => (clone $staff)->where('account_status', 'frozen')->count(),
            'disabledStaff' => (clone $staff)->whereIn('account_status', ['disabled', 'suspended'])->count(),
            'departmentCount' => Department::query()->count(),
            'activeStaffCount' => $activeStaffIds->count(),
            'passedAuth24Hours' => AuthEvent::query()
                ->where('guard', 'web')->where('account_type', 'staff')->where('status', 'passed')
                ->where('occurred_at', '>=', $authWindow)->count(),
            'failedAuth24Hours' => AuthEvent::query()
                ->where('guard', 'web')->where('account_type', 'staff')->where('status', 'failed')
                ->where('occurred_at', '>=', $authWindow)->count(),
            'bruteForce30Days' => BruteForceRecord::query()
                ->where('account_type', 'staff')
                ->where('detected_at', '>=', $bruteForceWindow)
                ->count(),
            'lastAuthenticatedAt' => $latestAuthentication?->occurred_at,
            'lastAuthenticatedStaff' => $lastAuthenticatedStaff,
            'latestBruteForce' => $latestBruteForce,
            'activeStaffIds' => $activeStaffIds,
        ];
    }

    public function analytics(): array
    {
        $overview = $this->overview();

        return [
            ...$overview,
            'topSessions' => $this->topSessions(),
            'authenticationTimeline' => $this->authenticationTimeline(),
            'departmentPosture' => $this->departmentPosture(),
        ];
    }

    private function activeStaffSessions(): Collection
    {
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') !== 'database'
            || ! Schema::hasTable($sessionTable)
            || ! Schema::hasTable('security_session_presence')) {
            return collect();
        }

        $activeSessionSeconds = max(
            max(1, (int) config('session.lifetime', 120)) * 60,
            max(30, (int) config('auth_security.active_session_seconds', 120)),
        );
        $cutoff = now()->subSeconds($activeSessionSeconds);

        return DB::table('security_session_presence as presence')
            ->join($sessionTable.' as sessions', 'sessions.id', '=', 'presence.session_id')
            ->join('users', 'users.id', '=', 'presence.account_id')
            ->where('presence.guard', 'web')
            ->where('presence.account_type', 'staff')
            ->where('presence.last_seen_at', '>=', $cutoff)
            ->where('sessions.last_activity', '>=', $cutoff->getTimestamp())
            ->whereNotNull('users.department')
            ->where(function ($query): void {
                $query->whereNull('users.account_status')->orWhere('users.account_status', 'active');
            })
            ->select('presence.account_id', 'presence.session_id')
            ->distinct()
            ->get();
    }

    private function topSessions(): Collection
    {
        $activeSessionKeys = $this->activeStaffSessions()
            ->pluck('session_id')
            ->map(fn (string $id): string => hash('sha256', $id))
            ->values()
            ->all();
        $driver = DB::connection()->getDriverName();
        $durationBindings = [];
        $activeBindings = [];

        if ($activeSessionKeys === []) {
            $endTime = 'COALESCE(ended_at, last_seen_at)';
            $activeFlag = '0';
        } else {
            $placeholders = implode(', ', array_fill(0, count($activeSessionKeys), '?'));
            $activeCondition = "session_key IN ({$placeholders})";
            $endTime = "CASE WHEN ended_at IS NULL AND {$activeCondition} THEN ? ELSE COALESCE(ended_at, last_seen_at) END";
            $now = now()->format('Y-m-d H:i:s');
            $durationBindings = [...$activeSessionKeys, $now, ...$activeSessionKeys, $now];
            $activeFlag = "MAX(CASE WHEN {$activeCondition} THEN 1 ELSE 0 END)";
            $activeBindings = $activeSessionKeys;
        }

        $durationExpression = match ($driver) {
            'sqlite' => "(julianday({$endTime}) - julianday(started_at)) * 86400",
            'mysql', 'mariadb' => "TIMESTAMPDIFF(SECOND, started_at, {$endTime})",
            'pgsql' => "EXTRACT(EPOCH FROM ({$endTime} - started_at))",
            default => throw new \RuntimeException("Staff session analytics do not support the {$driver} database driver."),
        };
        $nonNegativeDuration = "CASE WHEN {$durationExpression} > 0 THEN {$durationExpression} ELSE 0 END";

        return DB::table('staff_session_history as history')
            ->join('users', 'users.id', '=', 'history.staff_id')
            ->whereNotNull('users.department')
            ->where('history.started_at', '>=', now()->subDays(30))
            ->groupBy('users.id', 'users.name', 'users.username', 'users.department')
            ->select('users.id', 'users.name', 'users.username', 'users.department')
            ->selectRaw("SUM({$nonNegativeDuration}) as duration_seconds", $durationBindings)
            ->selectRaw("{$activeFlag} as is_active", $activeBindings)
            ->orderByDesc('duration_seconds')
            ->limit(5)
            ->get()
            ->map(function (object $session): object {
                $session->duration_seconds = (int) round((float) $session->duration_seconds);
                $session->duration_label = $this->formatDuration($session->duration_seconds);
                $session->is_active = (bool) $session->is_active;

                return $session;
            });
    }

    private function authenticationTimeline(): Collection
    {
        $start = CarbonImmutable::today()->subDays(6);
        $counts = AuthEvent::query()
            ->where('guard', 'web')
            ->where('account_type', 'staff')
            ->where('occurred_at', '>=', $start)
            ->selectRaw('DATE(occurred_at) as event_day, status, COUNT(*) as event_count')
            ->groupByRaw('DATE(occurred_at), status')
            ->get()
            ->keyBy(fn (object $event): string => $event->event_day.'|'.$event->status);
        $days = collect(range(0, 6))->map(function (int $offset) use ($start, $counts): array {
            $day = $start->addDays($offset);
            $key = $day->toDateString();

            return [
                'label' => $day->format('D'),
                'passed' => (int) ($counts->get($key.'|passed')->event_count ?? 0),
                'failed' => (int) ($counts->get($key.'|failed')->event_count ?? 0),
            ];
        });
        $maximum = max(1, (int) $days->max(fn (array $day): int => $day['passed'] + $day['failed']));

        return $days->map(fn (array $day): array => [
            ...$day,
            'total' => $day['passed'] + $day['failed'],
            'passedHeight' => $day['passed'] === 0 ? 1 : max(3, (int) round($day['passed'] / $maximum * 100)),
            'failedHeight' => $day['failed'] === 0 ? 1 : max(3, (int) round($day['failed'] / $maximum * 100)),
        ]);
    }

    private function departmentPosture(): Collection
    {
        $staffByDepartment = User::query()
            ->whereNotNull('department')
            ->get(['department_id', 'account_status'])
            ->groupBy('department_id');

        return Department::query()->orderBy('name')->get()->map(function (Department $department) use ($staffByDepartment): array {
            $staff = $staffByDepartment->get($department->getKey(), collect());
            $total = $staff->count();
            $active = $staff->filter(fn (User $user): bool => in_array($user->account_status, [null, 'active'], true))->count();
            $frozen = $staff->where('account_status', 'frozen')->count();
            $disabled = $staff->filter(fn (User $user): bool => in_array($user->account_status, ['disabled', 'suspended'], true))->count();

            return [
                'name' => $department->name,
                'rankShort' => $department->rank_short,
                'total' => $total,
                'active' => $active,
                'frozen' => $frozen,
                'disabled' => $disabled,
                'activeWidth' => $total === 0 ? 0 : (int) round($active / $total * 100),
                'frozenWidth' => $total === 0 ? 0 : (int) round($frozen / $total * 100),
                'disabledWidth' => $total === 0 ? 0 : (int) round($disabled / $total * 100),
            ];
        });
    }

    private function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $days > 0
            ? "{$days}d {$hours}h"
            : ($hours > 0 ? "{$hours}h ".str_pad((string) $minutes, 2, '0', STR_PAD_LEFT).'m' : "{$minutes}m");
    }
}
