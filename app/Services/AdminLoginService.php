<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminLoginService
{
    public function attempt(string $email, string $password, bool $remember, string $ipAddress): bool
    {
        $startedAt = hrtime(true);
        $email = Str::lower(trim($email));
        $key = 'login:'.hash('sha256', $email.'|'.$ipAddress);
        $admin = Admin::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $security = app(AuthSecurityService::class);

        if (! $security->isIpAllowed($ipAddress)) {
            $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Source IP is restricted by the security policy.', request()->userAgent());
            throw ValidationException::withMessages([
                'email' => 'Due to a system-level authentication algorithm, you are unable to log in.',
            ]);
        }

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Account and IP rate limit exceeded.', request()->userAgent());
            throw ValidationException::withMessages([
                'email' => 'Too many authentication attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $isConfiguredAdmin = $email === Str::lower(trim((string) config('vault.admin.email')));
        $authenticated = $isConfiguredAdmin
            && $admin !== null
            && Hash::check($password, $admin->password);

        if (! $authenticated) {
            RateLimiter::hit($key, 60);
            $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Invalid administrator credentials.', request()->userAgent());

            throw ValidationException::withMessages([
                'email' => 'Access denied. Check your operator ID and access code.',
            ]);
        }

        RateLimiter::clear($key);
        if (app(AdminTwoFactorService::class)->enabled()) {
            try {
                app(AdminTwoFactorService::class)->begin($admin, $remember, $ipAddress);
            } catch (Throwable $exception) {
                $security->recordAttempt(
                    'admin',
                    $admin,
                    null,
                    $ipAddress,
                    $this->elapsedMilliseconds($startedAt),
                    false,
                    'Administrator verification email could not be delivered.',
                    request()->userAgent(),
                );

                throw $exception;
            }

            return true;
        }

        Auth::guard('admin')->login($admin, $remember);
        $security->recordAttempt('admin', $admin, null, $ipAddress, $this->elapsedMilliseconds($startedAt), true, 'Administrator authentication succeeded.', request()->userAgent());

        return false;
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
