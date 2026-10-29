<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminLoginService
{
    public function attempt(string $email, string $password, bool $remember, string $ipAddress): void
    {
        $startedAt = hrtime(true);
        $email = Str::lower(trim($email));
        $key = 'login:'.hash('sha256', $email.'|'.$ipAddress);
        $admin = Admin::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $security = app(AuthSecurityService::class);

        if ($security->isBlacklisted($ipAddress)) {
            $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Source IP is blacklisted.', request()->userAgent());
            throw ValidationException::withMessages([
                'email' => 'This network is temporarily blocked from authentication. Contact an administrator.',
            ]);
        }

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Account and IP rate limit exceeded.', request()->userAgent());
            throw ValidationException::withMessages([
                'email' => 'Too many authentication attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $isConfiguredAdmin = $email === Str::lower((string) config('vault.admin.email'));
        $authenticated = $isConfiguredAdmin && Auth::guard('admin')->attempt(['email' => $email, 'password' => $password], $remember);

        if (! $authenticated) {
            RateLimiter::hit($key, 60);
            $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Invalid administrator credentials.', request()->userAgent());

            throw ValidationException::withMessages([
                'email' => 'Access denied. Check your operator ID and access code.',
            ]);
        }

        RateLimiter::clear($key);
        $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), true, 'Administrator authentication succeeded.', request()->userAgent());
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
