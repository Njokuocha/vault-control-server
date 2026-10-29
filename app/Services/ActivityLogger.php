<?php

namespace App\Services;

use App\Events\SecurityActivityCreated;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public function record(
        string $category,
        string $action,
        string $summary,
        array $details = [],
        ?Authenticatable $actor = null,
        ?Request $request = null,
    ): ActivityLog {
        $request ??= app()->bound('request') ? request() : null;
        $actor ??= Auth::guard('admin')->user() ?? Auth::guard('web')->user();
        $configuredAdminEmail = strtolower((string) config('vault.admin.email'));
        $legacyAdmin = $actor instanceof User
            && $actor->department === null
            && $configuredAdminEmail !== ''
            && hash_equals($configuredAdminEmail, strtolower((string) $actor->email));
        $accountType = match (true) {
            $actor instanceof Admin => 'admin',
            $legacyAdmin => 'admin',
            $actor instanceof User => 'staff',
            default => 'system',
        };

        $entry = ActivityLog::query()->create([
            'category' => $category,
            'action' => $action,
            'actor_type' => $accountType,
            'actor_id' => $actor?->getAuthIdentifier() === null ? null : (string) $actor->getAuthIdentifier(),
            'actor_name' => $actor === null ? 'System' : (string) ($actor->name ?? $actor->getAuthIdentifier()),
            'summary' => mb_substr($summary, 0, 255),
            'details' => $details === [] ? null : $details,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 512) : null,
            'occurred_at' => now(),
        ]);
        event(new SecurityActivityCreated($entry));

        return $entry;
    }
}
