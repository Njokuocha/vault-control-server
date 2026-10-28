<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

class VersionedCache
{
    public function remember(string $resource, string $key, DateTimeInterface $expiresAt, Closure $resolver): mixed
    {
        $versionKey = $this->versionKey($resource);
        $version = (int) Cache::get($versionKey, 0);
        $cacheKey = "vault:{$resource}:v{$version}:{$key}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
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

    private function versionKey(string $resource): string
    {
        return "vault:cache-version:{$resource}";
    }
}
