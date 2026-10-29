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
        $startedAt = hrtime(true);
        $username = Str::lower(trim($username));
        $key = 'department-login:'.hash('sha256', $department.'|'.$username.'|'.$ipAddress);
        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->first();
        $security = app(AuthSecurityService::class);

        if (! $security->isIpAllowed($ipAddress)) {
            $security->recordAttempt('web', null, $user, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Source IP is restricted by the security policy.', request()->userAgent());
            throw ValidationException::withMessages([
                'username' => 'Due to a system-level authentication algorithm, you are unable to log in.',
            ]);
        }

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $security->recordAttempt('web', null, $user, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Account and IP rate limit exceeded.', request()->userAgent());
            throw ValidationException::withMessages([
                'username' => 'Too many authentication attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $authenticated = $user !== null
            && $user->department === $department
            && $user->account_status === 'active'
            && Hash::check($password, $user->password);
        if (! $authenticated) {
            RateLimiter::hit($key, 60);
            $security->recordAttempt('web', null, $user, $ipAddress, $this->elapsedMilliseconds($startedAt), false, 'Invalid department credentials or inactive account.', request()->userAgent());

            throw ValidationException::withMessages([
                'username' => 'Access denied. Check your username and access code.',
            ]);
        }

        Auth::guard('web')->login($user, $remember);
        RateLimiter::clear($key);
        $security->recordAttempt('web', null, $user, $ipAddress, $this->elapsedMilliseconds($startedAt), true, 'Department authentication succeeded.', request()->userAgent());
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
