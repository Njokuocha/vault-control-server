<?php

namespace App\Console\Commands;

use App\Models\SecurityMetricsSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SnapshotSecuritySessions extends Command
{
    protected $signature = 'security:snapshot-sessions';

    protected $description = 'Count authenticated accounts with active database sessions.';

    public function handle(): int
    {
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') !== 'database' || ! Schema::hasTable($sessionTable)) {
            throw new RuntimeException('Auth Security session snapshots require the configured database session table.');
        }

        $cutoff = now()->subSeconds(max(30, (int) config('auth_security.active_session_seconds', 120)));
        $sessionIds = DB::table('security_session_presence as presence')
            ->join($sessionTable.' as sessions', 'sessions.id', '=', 'presence.session_id')
            ->where('sessions.last_activity', '>=', $cutoff->getTimestamp())
            ->where('presence.last_seen_at', '>=', $cutoff)
            ->get(['presence.guard', 'presence.account_id']);
        $activeAccounts = $sessionIds
            ->map(fn (object $session): string => $session->guard.':'.$session->account_id)
            ->unique()
            ->count();

        SecurityMetricsSnapshot::query()->updateOrCreate(
            ['id' => 1],
            ['active_accounts' => $activeAccounts, 'captured_at' => now()],
        );

        return self::SUCCESS;
    }
}
