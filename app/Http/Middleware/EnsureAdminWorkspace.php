<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminAuthenticated = Auth::guard('admin')->check();
        $legacyAdmin = Auth::guard('web')->user();
        $configuredAdminEmail = strtolower(trim((string) config('vault.admin.email')));
        $legacyAdminAuthenticated = $legacyAdmin instanceof User
            && $configuredAdminEmail !== ''
            && strtolower($legacyAdmin->email) === $configuredAdminEmail
            && $legacyAdmin->department === null
            && in_array($legacyAdmin->account_status, ['active', null], true);
        abort_unless($adminAuthenticated || $legacyAdminAuthenticated, 403);

        return $next($request);
    }
}
