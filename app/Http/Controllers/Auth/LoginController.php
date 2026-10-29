<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use App\Services\AdminLoginService;
use App\Services\SecuritySessionMetrics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AdminLoginService $login): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $requiresOtp = $login->attempt(
            $credentials['email'],
            $credentials['password'],
            $request->boolean('remember'),
            (string) $request->ip(),
        );
        if ($requiresOtp) {
            return redirect()->route('login');
        }

        $request->session()->regenerate();
        $request->session()->put('security_activity_last_seen_id', (int) ActivityLog::query()->max('id'));

        return redirect()->intended(route('dashboard.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $sessionId = $request->session()->getId();
        $actor = Auth::guard('admin')->user() ?? Auth::guard('web')->user();
        app(ActivityLogger::class)->record(
            'authentication',
            'auth.logout',
            'An authenticated account signed out.',
            ['guard' => Auth::guard('admin')->check() ? 'admin' : 'web'],
            $actor,
            $request,
        );

        Auth::guard('admin')->logout();
        Auth::guard('web')->logout();
        app(SecuritySessionMetrics::class)->forgetSession($sessionId);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
