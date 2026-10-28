<?php

namespace App\Http\Middleware;

use App\Models\User;
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

        if ($user->account_status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route($department.'.login')->withErrors([
                'username' => 'This account is suspended or disabled. Contact an administrator.',
            ]);
        }

        abort_unless($user->department === $department, 403);

        return $next($request);
    }
}
