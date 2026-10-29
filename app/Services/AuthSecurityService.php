<?php

namespace App\Services;

use App\Events\SecurityActivityCreated;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\AuthEvent;
use App\Models\BlacklistedIp;
use App\Models\BruteForceRecord;
use App\Models\User;
use App\Models\WhitelistedIp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AuthSecurityService
{
    public function isBlacklisted(string $ip): bool
    {
        return BlacklistedIp::query()->where('ip_address', $this->normalizeIp($ip))->exists();
    }

    public function isIpAllowed(string $ip): bool
    {
        if ($this->isBlacklisted($ip)) {
            return false;
        }

        $settings = app(SettingService::class);
        $enforcement = $settings->get('whitelist_ip_enforcement');
        if ($enforcement !== null && ! in_array($enforcement, ['0', '1'], true)) {
            throw new \UnexpectedValueException('The whitelist IP enforcement setting is invalid.');
        }

        if ($enforcement !== '1' || ! WhitelistedIp::query()->exists()) {
            return true;
        }

        return WhitelistedIp::query()->where('ip_address', $this->normalizeIp($ip))->exists();
    }

    public function recordAttempt(
        string $guard,
        ?Admin $admin,
        ?User $staff,
        string $ip,
        int $durationMs,
        bool $passed,
        string $detail,
        ?string $userAgent,
    ): AuthEvent {
        $ip = $this->normalizeIp($ip);

        return Cache::lock('auth-security:ip:'.hash('sha256', $ip), 15)
            ->block(5, fn (): AuthEvent => DB::transaction(function () use ($guard, $admin, $staff, $ip, $durationMs, $passed, $detail, $userAgent): AuthEvent {
                $account = $admin ?? $staff;
                $accountType = $admin ? 'admin' : ($staff ? 'staff' : null);
                $accountName = $account?->name ?? 'Unknown';
                $accountId = $account === null ? null : (string) $account->getAuthIdentifier();
                $blacklist = BlacklistedIp::query()->where('ip_address', $ip)->lockForUpdate()->first();
                $ipAllowed = $this->isIpAllowed($ip);
                $passed = $passed && $ipAllowed;

                if ($blacklist !== null) {
                    $blacklist->forceFill(['last_attempt_at' => now()])->save();
                    $detail = 'Source IP is blocked by the security policy.';
                } elseif (! $ipAllowed) {
                    $detail = 'Source IP is restricted by the security policy.';
                }

                $event = AuthEvent::query()->create([
                    'guard' => $guard,
                    'account_type' => $accountType,
                    'account_id' => $accountId,
                    'account_name' => $accountName,
                    'ip_address' => $ip,
                    'duration_ms' => max(0, $durationMs),
                    'status' => $passed ? 'passed' : 'failed',
                    'is_brute_force' => false,
                    'result_detail' => $detail,
                    'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                    'occurred_at' => now(),
                ]);

                if ($passed) {
                    $this->logAuthentication($event);

                    return $event;
                }

                $bruteThreshold = $this->settingInt('auth_brute_force_attempts', 'brute_force_attempts', 3, 1, 50);
                $bruteWindow = $this->settingInt('auth_brute_force_window_seconds', 'brute_force_window_seconds', 120, 10, 3600);
                $bruteStart = now()->subSeconds($bruteWindow);
                $lastSuccessId = AuthEvent::query()
                    ->where('ip_address', $ip)
                    ->where('status', 'passed')
                    ->latest('id')
                    ->value('id');
                $failedSinceSuccess = AuthEvent::query()
                    ->where('ip_address', $ip)
                    ->where('status', 'failed')
                    ->where('occurred_at', '>=', $bruteStart)
                    ->when($lastSuccessId !== null, fn ($query) => $query->where('id', '>', $lastSuccessId))
                    ->count();
                $activeIncident = BruteForceRecord::query()
                    ->where('ip_address', $ip)
                    ->where('detected_at', '>=', $bruteStart)
                    ->exists();
                $isBruteForce = $activeIncident || $failedSinceSuccess >= $bruteThreshold;

                if ($isBruteForce) {
                    $event->forceFill(['is_brute_force' => true])->save();
                    BruteForceRecord::query()->create([
                        'auth_event_id' => $event->id,
                        'account_type' => $accountType,
                        'account_id' => $accountId,
                        'account_name' => $accountName,
                        'ip_address' => $ip,
                        'failed_attempts' => $failedSinceSuccess,
                        'window_seconds' => $bruteWindow,
                        'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
                        'detected_at' => now(),
                    ]);
                }

                $blacklistThreshold = $this->settingInt('auth_ip_blacklist_attempts', 'blacklist_attempts', 5, 1, 100);
                $blacklistWindow = $this->settingInt('auth_ip_blacklist_window_seconds', 'blacklist_window_seconds', 300, 10, 86400);
                $blacklistFailures = AuthEvent::query()
                    ->where('ip_address', $ip)
                    ->where('status', 'failed')
                    ->where('occurred_at', '>=', now()->subSeconds($blacklistWindow))
                    ->count();

                if ($blacklist === null && $blacklistFailures >= $blacklistThreshold) {
                    BlacklistedIp::query()->create([
                        'ip_address' => $ip,
                        'failed_attempts' => $blacklistFailures,
                        'triggered_at' => now(),
                        'last_attempt_at' => now(),
                        'reason' => "{$blacklistFailures} failed sign-in attempts within {$blacklistWindow} seconds.",
                        'source' => 'automatic',
                    ]);
                    app(ActivityLogger::class)->record(
                        'security',
                        'ip.blacklisted',
                        "IP {$ip} was added to the authentication blacklist.",
                        ['ip_address' => $ip, 'failed_attempts' => $blacklistFailures, 'window_seconds' => $blacklistWindow],
                    );
                }

                $this->logAuthentication($event);

                return $event;
            }));
    }

    private function settingInt(string $key, string $configKey, int $default, int $min, int $max): int
    {
        $setting = app(SettingService::class)->get($key);
        $value = $setting === null ? (int) config("auth_security.{$configKey}", $default) : (int) $setting;

        return min($max, max($min, $value));
    }

    private function normalizeIp(string $ip): string
    {
        $packed = inet_pton($ip);

        return $packed === false ? $ip : inet_ntop($packed);
    }

    private function logAuthentication(AuthEvent $event): void
    {
        $log = ActivityLog::query()->create([
            'category' => 'authentication',
            'action' => 'auth.'.($event->status === 'passed' ? 'passed' : 'failed'),
            'actor_type' => $event->account_type,
            'actor_id' => $event->account_id,
            'actor_name' => $event->account_name,
            'summary' => ($event->status === 'passed' ? 'Successful' : 'Failed')." {$event->guard} authentication for {$event->account_name}.",
            'details' => [
                'auth_event_id' => $event->id,
                'ip_address' => $event->ip_address,
                'status' => $event->status,
                'duration_ms' => $event->duration_ms,
                'brute_force' => $event->is_brute_force,
                'detail' => $event->result_detail,
            ],
            'ip_address' => $event->ip_address,
            'user_agent' => $event->user_agent,
            'occurred_at' => $event->occurred_at,
        ]);
        event(new SecurityActivityCreated($log));
    }
}
