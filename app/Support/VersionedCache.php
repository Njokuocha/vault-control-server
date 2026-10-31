<?php

namespace App\Support;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class VersionedCache
{
    public function remember(
        string $resource,
        string $key,
        DateTimeInterface $expiresAt,
        Closure $resolver,
        array $dependencies = [],
    ): mixed {
        $versionKey = $this->versionKey($resource);
        $version = (int) Cache::get($versionKey, 0);
        $dependencyVersions = collect($dependencies)
            ->map(fn (string $dependency): string => $dependency.'-v'.(int) Cache::get($this->versionKey($dependency), 0))
            ->implode(':');
        $dependencyKey = $dependencyVersions === '' ? '' : ":{$dependencyVersions}";
        $cacheKey = "vault:{$resource}:v{$version}{$dependencyKey}:{$key}";
        $missing = new \stdClass;
        $cached = Cache::get($cacheKey, $missing);

        if ($cached !== $missing) {
            return $cached;
        }

        $lock = Cache::lock("vault:lock:{$cacheKey}", 15);
        $waitUntil = hrtime(true) + 100_000_000;

        do {
            if ($lock->get()) {
                try {
                    $cached = Cache::get($cacheKey, $missing);
                    if ($cached !== $missing) {
                        return $cached;
                    }

                    $resolved = $resolver();
                    Cache::put($cacheKey, $resolved, $expiresAt);

                    return $resolved;
                } finally {
                    $lock->release();
                }
            }

            $cached = Cache::get($cacheKey, $missing);
            if ($cached !== $missing) {
                return $cached;
            }

            if (hrtime(true) >= $waitUntil) {
                return $resolver();
            }

            usleep(20_000);
        } while (true);
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
