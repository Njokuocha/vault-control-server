<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AdminLoginService;
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

        $login->attempt(
            $credentials['email'],
            $credentials['password'],
            $request->boolean('remember'),
            (string) $request->ip(),
        );
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
