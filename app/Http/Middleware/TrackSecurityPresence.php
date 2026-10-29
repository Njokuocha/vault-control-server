<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Models\SecuritySessionPresence;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackSecurityPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin')->user();
        $staff = Auth::guard('web')->user();
        $actor = $admin ?? $staff;

        if ($actor instanceof Admin || $actor instanceof User) {
            $legacyAdmin = $actor instanceof User
                && $actor->department === null
                && strtolower((string) $actor->email) === strtolower((string) config('vault.admin.email'));
            SecuritySessionPresence::query()->updateOrCreate(
                ['session_id' => $request->session()->getId()],
                [
                    'guard' => $admin instanceof Admin || $legacyAdmin ? 'admin' : 'web',
                    'account_type' => $admin instanceof Admin || $legacyAdmin ? 'admin' : 'staff',
                    'account_id' => (string) $actor->getAuthIdentifier(),
                    'last_seen_at' => now(),
                ],
            );
        }

        return $next($request);
    }
}
