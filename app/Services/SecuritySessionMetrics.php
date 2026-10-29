<?php

namespace App\Services;

use App\Events\SecurityMetricsUpdated;
use App\Models\Admin;
use App\Models\SecurityMetricsSnapshot;
use App\Models\SecuritySessionPresence;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SecuritySessionMetrics
{
    public function capture(): SecurityMetricsSnapshot
    {
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') !== 'database' || ! Schema::hasTable($sessionTable)) {
            throw new RuntimeException('Auth Security session snapshots require the configured database session table.');
        }

        $sessionLifetimeSeconds = max(1, (int) config('session.lifetime', 120)) * 60;
        $activeSessionSeconds = max(
            $sessionLifetimeSeconds,
            max(30, (int) config('auth_security.active_session_seconds', 120)),
        );
        $cutoff = now()->subSeconds($activeSessionSeconds);
        $activeAccounts = DB::table('security_session_presence as presence')
            ->leftJoin($sessionTable.' as sessions', 'sessions.id', '=', 'presence.session_id')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('sessions.id')
                    ->orWhere('sessions.last_activity', '>=', $cutoff->getTimestamp());
            })
            ->where('presence.last_seen_at', '>=', $cutoff)
            ->select('presence.guard', 'presence.account_id')
            ->distinct()
            ->get()
            ->count();

        $snapshot = SecurityMetricsSnapshot::query()->updateOrCreate(
            ['id' => 1],
            ['active_accounts' => (int) $activeAccounts, 'captured_at' => now()],
        );

        event(new SecurityMetricsUpdated(
            (int) $snapshot->active_accounts,
            $snapshot->captured_at->toIso8601String(),
        ));

        return $snapshot;
    }

    public function trackRequest(Request $request): void
    {
        $admin = Auth::guard('admin')->user();
        $staff = Auth::guard('web')->user();
        $actor = $admin ?? $staff;

        if (! ($actor instanceof Admin) && ! ($actor instanceof User)) {
            return;
        }

        $sessionId = $request->session()->getId();
        $presence = SecuritySessionPresence::query()->find($sessionId);
        $shouldRefresh = $presence === null
            || $presence->last_seen_at?->lessThan(now()->subSeconds(30));
        $legacyAdmin = $actor instanceof User
            && $actor->department === null
            && strtolower((string) $actor->email) === strtolower((string) config('vault.admin.email'));
        $isAdmin = $admin instanceof Admin || $legacyAdmin;

        SecuritySessionPresence::query()->updateOrCreate(
            ['session_id' => $sessionId],
            [
                'guard' => $isAdmin ? 'admin' : 'web',
                'account_type' => $isAdmin ? 'admin' : 'staff',
                'account_id' => (string) $actor->getAuthIdentifier(),
                'last_seen_at' => now(),
            ],
        );

        if ($shouldRefresh && config('session.driver') === 'database') {
            $this->capture();
        }
    }

    public function forgetSession(string $sessionId): void
    {
        SecuritySessionPresence::query()->where('session_id', $sessionId)->delete();

        if (config('session.driver') === 'database') {
            $this->capture();
        }
    }
}
