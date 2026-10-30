<?php

namespace App\Console\Commands;

use App\Services\StaffAccountStatusService;
use Illuminate\Console\Command;

class ExpireFrozenStaffAccounts extends Command
{
    protected $signature = 'staff:expire-freezes';

    protected $description = 'Reactivate staff accounts whose freeze period has expired';

    public function handle(StaffAccountStatusService $service): int
    {
        $count = $service->expireAllDue();
        $this->info("Reactivated {$count} expired staff account freeze(s).");

        return self::SUCCESS;
    }
}
