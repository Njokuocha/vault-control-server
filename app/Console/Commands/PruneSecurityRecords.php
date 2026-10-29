<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\BlacklistedIp;
use App\Models\BruteForceRecord;
use App\Models\SecuritySessionPresence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PruneSecurityRecords extends Command
{
    protected $signature = 'security:prune-records';

    protected $description = 'Remove expired security activity, authentication, and brute-force records.';

    public function handle(): int
    {
        $logsBefore = now()->subDays(14);
        $securityBefore = now()->subDays(max(1, (int) config('auth_security.brute_force_retention_days', 30)));

        ActivityLog::query()->where('occurred_at', '<', $logsBefore)->delete();
        BruteForceRecord::query()->where('detected_at', '<', $securityBefore)->delete();
        BlacklistedIp::query()->where('triggered_at', '<', $securityBefore)->delete();
        AuthEvent::query()->where('occurred_at', '<', $securityBefore)->delete();
        SecuritySessionPresence::query()->where('last_seen_at', '<', now()->subDay())->delete();
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') === 'database' && Schema::hasTable($sessionTable)) {
            DB::table($sessionTable)
                ->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
                ->delete();
        }

        return self::SUCCESS;
    }
}
