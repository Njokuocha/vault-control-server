<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\StaffAccountStatusService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDepartment
{
    public function handle(Request $request, Closure $next, string $department): Response
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return redirect()->route($department.'.login');
        }

        abort_unless($user->department === $department, 403);

        $user = app(StaffAccountStatusService::class)->expireIfDue($user);
        if ($user->account_status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($department.'.login')->withErrors([
                'username' => $user->account_status === 'frozen'
                    ? 'This account has been frozen. Contact your admin.'
                    : 'This account has been disabled. Contact your admin.',
            ]);
        }

        return $next($request);
    }
}
