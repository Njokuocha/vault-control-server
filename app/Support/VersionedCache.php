<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VersionedCache
{
    public function remember(string $resource, string $key, DateTimeInterface $expiresAt, Closure $resolver): mixed
    {
        $versionKey = $this->versionKey($resource);
        $version = (int) Cache::get($versionKey, 0);
        $cacheKey = "vault:{$resource}:v{$version}:{$key}";
        $missing = new \stdClass;
        $cached = Cache::get($cacheKey, $missing);

        if ($cached !== $missing) {
            return $cached;
        }

        return Cache::lock("vault:lock:{$cacheKey}", 15)->block(5, function () use ($cacheKey, $expiresAt, $resolver): mixed {
            return Cache::remember($cacheKey, $expiresAt, $resolver);
        });
    }

    public function bump(string $resource): void
    {
        $versionKey = $this->versionKey($resource);
        Cache::add($versionKey, 0, now()->addYears(10));
        Cache::increment($versionKey);
    }

    public function bumpAfterCommit(string $resource): void
    {
        DB::afterCommit(fn () => $this->bump($resource));
    }

    private function versionKey(string $resource): string
    {
        return "vault:cache-version:{$resource}";
    }
}
