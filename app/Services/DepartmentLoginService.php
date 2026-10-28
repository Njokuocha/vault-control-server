<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DepartmentLoginService
{
    public function attempt(string $username, string $password, string $department, bool $remember, string $ipAddress): void
    {
        $username = Str::lower(trim($username));
        $key = 'department-login:'.hash('sha256', $department.'|'.$username.'|'.$ipAddress);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'username' => 'Too many authentication attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->where('department', $department)
            ->where('account_status', 'active')
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'username' => 'Access denied. Check your username and access code.',
            ]);
        }

        Auth::guard('web')->login($user, $remember);
        RateLimiter::clear($key);
    }
}
