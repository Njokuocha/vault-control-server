<?php

namespace App\Services;

use App\Models\SecuritySessionPresence;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

class StaffAccountStatusService
{
    public const MAX_FREEZE_HOURS = 8760;

    public function change(User $staff, string $status, ?float $freezeHours = null): User
    {
        if ($staff->department === null) {
            throw new InvalidArgumentException('Account status can only be changed for department staff.');
        }

        if (! in_array($status, ['active', 'frozen', 'disabled'], true)) {
            throw new InvalidArgumentException('The requested staff account status is invalid.');
        }

        $frozenUntil = null;
        if ($status === 'frozen') {
            $hundredthsOfAnHour = $freezeHours === null ? null : $freezeHours * 100;
            if ($freezeHours === null
                || ! is_finite($freezeHours)
                || $freezeHours < 0.1
                || $freezeHours > self::MAX_FREEZE_HOURS
                || abs($hundredthsOfAnHour - round($hundredthsOfAnHour)) > 0.000001) {
                throw new InvalidArgumentException('Freeze duration must be between 0.1 and 8,760 hours with at most two decimal places.');
            }

            $frozenUntil = now()->addSeconds((int) round($freezeHours * 3600));
        }

        return DB::transaction(function () use ($staff, $status, $frozenUntil): User {
            $lockedStaff = User::query()->lockForUpdate()->findOrFail($staff->getKey());
            if ($status !== 'active') {
                $this->assertSessionRevocationAvailable();
                $this->revokeSessions($lockedStaff);
            }

            $previousStatus = $lockedStaff->account_status;
            $lockedStaff->account_status = $status;
            $lockedStaff->frozen_until = $frozenUntil;
            $lockedStaff->save();

            app(ActivityLogger::class)->record(
                'staff-management',
                'staff.status-changed',
                "Staff account {$lockedStaff->username} was changed to {$status}.",
                [
                    'staff_id' => $lockedStaff->getKey(),
                    'username' => $lockedStaff->username,
                    'previous_status' => $previousStatus,
                    'account_status' => $status,
                    'frozen_until' => $frozenUntil?->toIso8601String(),
                ],
            );

            return $lockedStaff;
        });
    }

    public function expireIfDue(User $staff): User
    {
        if ($staff->account_status !== 'frozen' || $staff->frozen_until === null || $staff->frozen_until->isFuture()) {
            return $staff;
        }

        return DB::transaction(function () use ($staff): User {
            $lockedStaff = User::query()->lockForUpdate()->findOrFail($staff->getKey());
            if ($lockedStaff->account_status !== 'frozen' || $lockedStaff->frozen_until === null || $lockedStaff->frozen_until->isFuture()) {
                return $lockedStaff;
            }

            $lockedStaff->account_status = 'active';
            $lockedStaff->frozen_until = null;
            $lockedStaff->save();

            app(ActivityLogger::class)->record(
                'staff-management',
                'staff.freeze-expired',
                "The freeze period for {$lockedStaff->username} expired and the account was reactivated.",
                ['staff_id' => $lockedStaff->getKey(), 'username' => $lockedStaff->username],
            );

            return $lockedStaff;
        });
    }

    public function expireAllDue(): int
    {
        $expired = User::query()
            ->whereNotNull('department')
            ->where('account_status', 'frozen')
            ->whereNotNull('frozen_until')
            ->where('frozen_until', '<=', now())
            ->pluck('id');

        $count = 0;
        foreach ($expired as $id) {
            $staff = User::query()->find($id);
            if ($staff === null) {
                continue;
            }

            $wasExpired = $staff->account_status === 'frozen' && $staff->frozen_until?->isPast();
            $updatedStaff = $this->expireIfDue($staff);
            $count += $wasExpired && $updatedStaff->account_status === 'active' ? 1 : 0;
        }

        return $count;
    }

    public function revokeSessions(User $staff): void
    {
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') !== 'database' || ! Schema::hasTable($sessionTable) || ! Schema::hasTable('security_session_presence')) {
            return;
        }

        $sessionIds = SecuritySessionPresence::query()
            ->where('guard', 'web')
            ->where('account_type', 'staff')
            ->where('account_id', (string) $staff->getKey())
            ->pluck('session_id');

        if ($sessionIds->isEmpty()) {
            return;
        }

        DB::table($sessionTable)->whereIn('id', $sessionIds)->delete();
        SecuritySessionPresence::query()->whereIn('session_id', $sessionIds)->delete();
    }

    private function assertSessionRevocationAvailable(): void
    {
        $sessionTable = (string) config('session.table', 'sessions');
        if (config('session.driver') !== 'database' || ! Schema::hasTable($sessionTable) || ! Schema::hasTable('security_session_presence')) {
            throw new RuntimeException('Immediate staff logout requires the configured database session store and session-presence table.');
        }
    }
}
