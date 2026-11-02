<?php

namespace App\Services;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class StaffSessionHistoryService
{
    public function track(User $staff, string $sessionId, DateTimeInterface $startedAt, DateTimeInterface $lastSeenAt): void
    {
        if ($staff->department === null) {
            return;
        }

        $sessionKey = hash('sha256', $sessionId);
        $now = now();

        DB::table('staff_session_history')->insertOrIgnore([
            'session_key' => $sessionKey,
            'staff_id' => $staff->getKey(),
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'last_seen_at' => $lastSeenAt->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('staff_session_history')
            ->where('session_key', $sessionKey)
            ->whereNull('ended_at')
            ->where('last_seen_at', '<', $now->copy()->subSeconds(30))
            ->update([
                'last_seen_at' => $lastSeenAt->format('Y-m-d H:i:s'),
                'updated_at' => $now,
            ]);
    }

    public function close(string $sessionId): void
    {
        $this->closeMany([$sessionId]);
    }

    public function closeMany(iterable $sessionIds): void
    {
        $sessionKeys = collect($sessionIds)
            ->filter(fn (mixed $sessionId): bool => is_string($sessionId) && $sessionId !== '')
            ->map(fn (string $sessionId): string => hash('sha256', $sessionId))
            ->values()
            ->all();
        if ($sessionKeys === []) {
            return;
        }

        $now = now();
        DB::table('staff_session_history')
            ->whereIn('session_key', $sessionKeys)
            ->whereNull('ended_at')
            ->update([
                'last_seen_at' => $now,
                'ended_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
