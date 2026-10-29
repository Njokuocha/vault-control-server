<?php

namespace App\Console\Commands;

use App\Services\SecuritySessionMetrics;
use Illuminate\Console\Command;

class SnapshotSecuritySessions extends Command
{
    protected $signature = 'security:snapshot-sessions';

    protected $description = 'Count authenticated accounts with active database sessions.';

    public function handle(SecuritySessionMetrics $metrics): int
    {
        $snapshot = $metrics->capture();
        $this->info("Active sessions snapshot updated: {$snapshot->active_accounts} authenticated account(s).");

        return self::SUCCESS;
    }
}
