<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Services\ReportAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SidebarUnreadCounts
{
    public function reports(Authenticatable $actor, int $lastSeenId): int
    {
        $actorKey = hash('sha256', $actor::class.':'.$actor->getAuthIdentifier());

        return app(VersionedCache::class)->remember(
            'reports',
            "sidebar-unread:{$actorKey}:{$lastSeenId}",
            now()->addSeconds(5),
            function () use ($actor, $lastSeenId): int {
                if (! Schema::hasTable('reports')) {
                    return 0;
                }

                return app(ReportAccess::class)
                    ->visibleQuery($actor)
                    ->where('id', '>', $lastSeenId)
                    ->count();
            },
        );
    }

    public function securityActivity(Authenticatable $actor, int $lastSeenId): int
    {
        $actorKey = hash('sha256', $actor::class.':'.$actor->getAuthIdentifier());
        $cacheKey = "vault:sidebar:security-unread:{$actorKey}:{$lastSeenId}";

        return Cache::remember($cacheKey, now()->addSeconds(5), function () use ($lastSeenId): int {
            if (! Schema::hasTable('activity_logs')) {
                return 0;
            }

            return ActivityLog::query()->where('id', '>', $lastSeenId)->count();
        });
    }
}
