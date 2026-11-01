<?php

namespace App\Services;

use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PersonMediaAccess
{
    private const TOKEN_LENGTH = 32;

    private const TOKEN_TTL_MINUTES = 60;

    /**
     * @return array{token: string, expires_at: int}
     */
    public function issue(Request $request, Person $person): array
    {
        $actor = $request->user();
        abort_unless($actor !== null, 401);

        $issuedAt = now();
        $expiresAt = $issuedAt->copy()->addMinutes(self::TOKEN_TTL_MINUTES);
        $record = [
            'actor_type' => $actor::class,
            'actor_id' => (string) $actor->getAuthIdentifier(),
            'person_id' => (string) $person->getKey(),
            'version' => $this->version(),
            'expires_at' => $expiresAt->timestamp,
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = Str::random(self::TOKEN_LENGTH);
            if (Cache::add($this->tokenCacheKey($token), $record, $expiresAt)) {
                return [
                    'token' => $token,
                    'expires_at' => $expiresAt->timestamp,
                ];
            }
        }

        throw new \RuntimeException('Unable to issue a unique profile media access token.');
    }

    public function allows(Request $request, int $personId, ?string $token = null): bool
    {
        $token ??= $request->query('auth');
        $actor = $request->user();

        if (
            ! is_string($token)
            || ! preg_match('/^[A-Za-z0-9]{'.self::TOKEN_LENGTH.'}$/', $token)
            || $actor === null
        ) {
            return false;
        }

        $record = Cache::get($this->tokenCacheKey($token));
        if (! is_array($record)) {
            return false;
        }

        return ($record['actor_type'] ?? null) === $actor::class
            && ($record['actor_id'] ?? null) === (string) $actor->getAuthIdentifier()
            && ($record['person_id'] ?? null) === (string) $personId
            && ($record['version'] ?? null) === $this->version()
            && ($record['expires_at'] ?? 0) > now()->timestamp;
    }

    public function expiresAt(Request $request, int $personId, ?string $token = null): ?int
    {
        if (! $this->allows($request, $personId, $token)) {
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
            throw new \RuntimeException('Unable to initialize profile media access token version.');
        }

        return $version;
    }

    private function versionCacheKey(): string
    {
        return 'person-media-access:version';
    }

    private function tokenCacheKey(string $token): string
    {
        return 'person-media-access:token:'.hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
