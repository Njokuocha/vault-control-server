<?php

namespace App\Services;

use App\Mail\AdminLoginCodeMail;
use App\Models\Admin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminTwoFactorService
{
    public function enabled(): bool
    {
        $value = app(SettingService::class)->get('admin_two_factor_enabled');

        if ($value === null) {
            return false;
        }
        if (! in_array($value, ['0', '1'], true)) {
            throw new \UnexpectedValueException('The administrator two-factor setting is invalid.');
        }

        return $value === '1';
    }

    public function begin(Admin $admin, bool $remember, string $ipAddress): void
    {
        $sendKey = $this->sendRateLimitKey($admin, $ipAddress);
        if (RateLimiter::tooManyAttempts($sendKey, 3)) {
            throw ValidationException::withMessages([
                'email' => 'Too many verification codes were requested. Try again in '.RateLimiter::availableIn($sendKey).' seconds.',
            ]);
        }
        RateLimiter::hit($sendKey, 900);

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(3);
        session()->put('admin_otp_challenge', [
            'id' => (string) Str::uuid(),
            'admin_id' => $admin->id,
            'code_hash' => Hash::make($code),
            'expires_at' => $expiresAt->timestamp,
            'remember' => $remember,
            'attempts' => 0,
            'email_hint' => $this->maskedEmail($admin->email),
        ]);
        session()->regenerate();

        try {
            app(MailTransportPolicy::class)->assertCanDeliver();
            Mail::to($admin->email)->send(new AdminLoginCodeMail(
                $code,
                $expiresAt->toDayDateTimeString(),
            ));
        } catch (Throwable $exception) {
            session()->forget('admin_otp_challenge');
            report($exception);

            throw ValidationException::withMessages([
                'email' => 'The verification email could not be sent. Check the configured mail transport and try again.',
            ]);
        }
    }

    public function pendingDetails(): ?array
    {
        $challenge = session('admin_otp_challenge');
        if (! is_array($challenge)
            || ! isset($challenge['id'], $challenge['admin_id'], $challenge['code_hash'], $challenge['expires_at'])
            || ! is_string($challenge['id'])
            || ! is_string($challenge['code_hash'])
            || ! is_numeric($challenge['expires_at'])) {
            if ($challenge !== null) {
                session()->forget('admin_otp_challenge');
            }

            return null;
        }

        return [
            'emailHint' => (string) ($challenge['email_hint'] ?? 'your administrator email'),
            'expiresAt' => (int) $challenge['expires_at'],
            'expired' => (int) $challenge['expires_at'] <= now()->timestamp,
        ];
    }

    public function verify(string $code, string $ipAddress): Admin
    {
        $challenge = session('admin_otp_challenge');
        if (! is_array($challenge) || ! isset($challenge['id'], $challenge['admin_id'], $challenge['code_hash'], $challenge['expires_at'])) {
            throw ValidationException::withMessages(['otpCode' => 'No active verification request was found. Sign in again.']);
        }

        if ((int) $challenge['expires_at'] <= now()->timestamp) {
            session()->forget('admin_otp_challenge');
            throw ValidationException::withMessages(['otpCode' => 'This verification code has expired. Sign in again to request a new code.']);
        }

        $security = app(AuthSecurityService::class);
        if (! $security->isIpAllowed($ipAddress)) {
            $security->recordAttempt(
                'admin',
                Admin::query()->find((int) $challenge['admin_id']),
                null,
                $ipAddress,
                0,
                false,
                'Source IP is restricted by the security policy.',
                request()->userAgent(),
            );
            session()->forget('admin_otp_challenge');
            throw ValidationException::withMessages(['otpCode' => 'Due to a system-level authentication algorithm, you are unable to log in.']);
        }

        $attemptKey = $this->attemptRateLimitKey((string) $challenge['id'], $ipAddress);
        if (RateLimiter::tooManyAttempts($attemptKey, 5)) {
            session()->forget('admin_otp_challenge');
            throw ValidationException::withMessages(['otpCode' => 'Too many incorrect codes. Sign in again to request a new code.']);
        }

        $admin = Admin::query()->find((int) $challenge['admin_id']);
        if ($admin === null
            || strtolower(trim($admin->email)) !== strtolower(trim((string) config('vault.admin.email')))
            || ! Hash::check($code, (string) $challenge['code_hash'])) {
            RateLimiter::hit($attemptKey, 180);
            $attempts = (int) ($challenge['attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                session()->forget('admin_otp_challenge');
            } else {
                session()->put('admin_otp_challenge.attempts', $attempts);
            }

            $security->recordAttempt(
                'admin',
                $admin,
                null,
                $ipAddress,
                0,
                false,
                'Invalid or expired administrator verification code.',
                request()->userAgent(),
            );

            throw ValidationException::withMessages([
                'otpCode' => $attempts >= 5
                    ? 'Too many incorrect codes. Sign in again to request a new code.'
                    : 'The verification code is incorrect.',
            ]);
        }

        $authEvent = $security->recordAttempt(
            'admin',
            $admin,
            null,
            $ipAddress,
            0,
            true,
            'Administrator sign-in completed with email verification.',
            request()->userAgent(),
        );
        if ($authEvent->status !== 'passed') {
            session()->forget('admin_otp_challenge');
            throw ValidationException::withMessages(['otpCode' => 'Due to a system-level authentication algorithm, you are unable to log in.']);
        }

        Auth::guard('admin')->login($admin, (bool) ($challenge['remember'] ?? false));
        session()->forget('admin_otp_challenge');
        RateLimiter::clear($attemptKey);

        return $admin;
    }

    public function cancel(): void
    {
        session()->forget('admin_otp_challenge');
    }

    private function sendRateLimitKey(Admin $admin, string $ipAddress): string
    {
        return 'admin-otp-send:'.hash('sha256', $admin->id.'|'.$ipAddress);
    }

    private function attemptRateLimitKey(string $challengeId, string $ipAddress): string
    {
        return 'admin-otp-attempt:'.hash('sha256', $challengeId.'|'.$ipAddress);
    }

    private function maskedEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, 1);

        return $visible.str_repeat('*', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }
}
