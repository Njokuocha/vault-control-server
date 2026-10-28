<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminLoginService
{
    public function attempt(string $email, string $password, bool $remember, string $ipAddress): void
    {
        $email = Str::lower(trim($email));
        $key = 'login:'.hash('sha256', $email.'|'.$ipAddress);

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many authentication attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $isConfiguredAdmin = $email === Str::lower((string) config('vault.admin.email'));

        if (! $isConfiguredAdmin || ! Auth::guard('admin')->attempt(['email' => $email, 'password' => $password], $remember)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'Access denied. Check your operator ID and access code.',
            ]);
        }

        RateLimiter::clear($key);
    }
}
