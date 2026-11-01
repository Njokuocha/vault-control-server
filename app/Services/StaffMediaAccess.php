<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StaffMediaAccess
{
    private const TOKEN_LENGTH = 32;

    private const TOKEN_TTL_MINUTES = 60;

    /**
     * @return array{token: string, expires_at: int}
     */
    public function issue(Request $request, User $staff): array
    {
        $actor = $request->user();
        abort_unless($actor instanceof Admin || $this->isActiveStaffManager($actor), 403);

        $expiresAt = now()->addMinutes(self::TOKEN_TTL_MINUTES);
        $record = [
            'actor_type' => $actor::class,
            'actor_id' => (string) $actor->getAuthIdentifier(),
            'staff_id' => (string) $staff->getKey(),
            'version' => $this->version(),
            'expires_at' => $expiresAt->timestamp,
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = Str::random(self::TOKEN_LENGTH);
            if (Cache::add($this->tokenCacheKey($token), $record, $expiresAt)) {
                if (! Cache::put($this->activeTokenCacheKey($actor, $staff->getKey()), $token, $expiresAt)) {
                    Cache::forget($this->tokenCacheKey($token));

                    throw new \RuntimeException('Unable to store the active staff gallery access token.');
                }

                return ['token' => $token, 'expires_at' => $expiresAt->timestamp];
            }
        }

        throw new \RuntimeException('Unable to issue a unique staff gallery access token.');
    }

    public function activeToken(Request $request, int $staffId): ?string
    {
        $actor = $request->user();
        if ($actor === null) {
            return null;
        }

        $cacheKey = $this->activeTokenCacheKey($actor, $staffId);
        $token = Cache::get($cacheKey);
        if (! is_string($token) || ! $this->allows($request, $staffId, $token)) {
            Cache::forget($cacheKey);

            return null;
        }

        return $token;
    }

    public function allows(Request $request, int $staffId, ?string $token = null): bool
    {
        $token ??= $request->query('auth');
        $actor = $request->user();
        if (
            ! is_string($token)
            || ! preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token)
            || ! ($actor instanceof Admin || $this->isActiveStaffManager($actor))
        ) {
            return false;
        }

        $record = Cache::get($this->tokenCacheKey($token));

        return is_array($record)
            && ($record['actor_type'] ?? null) === $actor::class
            && ($record['actor_id'] ?? null) === (string) $actor->getAuthIdentifier()
            && ($record['staff_id'] ?? null) === (string) $staffId
            && ($record['version'] ?? null) === $this->version()
            && ($record['expires_at'] ?? 0) > now()->timestamp;
    }

    public function expiresAt(Request $request, int $staffId, ?string $token = null): ?int
    {
        if (! $this->allows($request, $staffId, $token)) {
            return null;
        }

        $token ??= $request->query('auth');
        $record = Cache::get($this->tokenCacheKey($token));

        return is_array($record) && is_numeric($record['expires_at'] ?? null)
            ? (int) $record['expires_at']
            : null;
    }

    public function invalidateAll(): void
    {
        Cache::forever($this->versionCacheKey(), Str::random(32));
    }

    private function version(): string
    {
        $key = $this->versionCacheKey();
        $version = Cache::get($key);
        if (is_string($version)) {
            return $version;
        }

        Cache::add($key, Str::random(32));
        $version = Cache::get($key);
        if (! is_string($version)) {
            throw new \RuntimeException('Unable to initialize staff gallery access token version.');
        }

        return $version;
    }

    private function versionCacheKey(): string
    {
        return 'staff-media-access:version';
    }

    private function tokenCacheKey(string $token): string
    {
        return 'staff-media-access:token:'.hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function activeTokenCacheKey(Authenticatable $actor, int $staffId): string
    {
        $scope = $actor::class.':'.$actor->getAuthIdentifier().':'.$staffId;

        return 'staff-media-access:active:'.hash_hmac('sha256', $scope, (string) config('app.key'));
    }

    private function isActiveStaffManager(mixed $actor): bool
    {
        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->department === 'sm' && $actor->account_status === 'active') {
            return true;
        }

        $configuredAdminEmail = strtolower(trim((string) config('vault.admin.email')));

        return $configuredAdminEmail !== ''
            && strtolower($actor->email) === $configuredAdminEmail
            && $actor->department === null
            && in_array($actor->account_status, ['active', null], true);
    }
}
