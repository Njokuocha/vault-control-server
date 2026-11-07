<?php

use App\Models\User;
use App\Support\SidebarUnreadCounts;
use App\Support\VersionedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('request instrumentation logs aggregate timing without query contents', function () {
    config(['performance.enabled' => true]);
    $admin = \App\Models\Admin::query()->create([
        'name' => 'Performance Test Administrator',
        'email' => 'performance-test@example.test',
        'password' => 'a-strong-password',
    ]);
    Log::spy();

    $this->actingAs($admin, 'admin')->get(route('dashboard.index'))->assertOk();

    Log::shouldHaveReceived('info')
        ->once()
        ->with('performance.request', Mockery::on(fn (array $context): bool => (
            $context['method'] === 'GET'
            && $context['route'] === 'dashboard.index'
            && $context['status'] === 200
            && $context['duration_ms'] >= 0
            && $context['db_query_count'] >= 1
            && $context['db_query_time_ms'] >= 0
            && ! array_key_exists('sql', $context)
            && ! array_key_exists('url', $context)
        )));
});

test('versioned cache coordinates rebuilds and bounds waiting behind a competing lock', function () {
    config(['cache.default' => 'database']);
    Cache::flush();

    $lock = Cache::lock('vault:lock:vault:reports:v0:entry', 15);
    expect($lock->get())->toBeTrue();

    $calls = 0;
    $cache = app(VersionedCache::class);
    try {
        $startedAt = hrtime(true);
        expect($cache->remember('reports', 'entry', now()->addMinute(), function () use (&$calls): string {
            $calls++;

            return 'resolved';
        }))->toBe('resolved');
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        expect($elapsedMs)->toBeLessThan(1_000)
            ->and(Cache::get('vault:reports:v0:entry', new stdClass))->toBeInstanceOf(stdClass::class)
            ->and($calls)->toBe(1);
    } finally {
        $lock->release();
    }

    expect($cache->remember('reports', 'entry', now()->addMinute(), function () use (&$calls): string {
        $calls++;

        return 'cached';
    }))->toBe('cached')
        ->and($cache->remember('reports', 'entry', now()->addMinute(), function () use (&$calls): string {
            $calls++;

            return 'unexpected';
        }))->toBe('cached')
        ->and($calls)->toBe(2);
});

test('sidebar unread count results are reused briefly for the same actor and watermark', function () {
    config(['cache.default' => 'array']);
    Cache::flush();

    $staff = User::factory()->create([
        'department' => 'cfm',
        'account_status' => 'active',
    ]);
    $counts = app(SidebarUnreadCounts::class);
    DB::connection()->enableQueryLog();

    $counts->reports($staff, 0);
    $counts->reports($staff, 0);
    $counts->securityActivity($staff, 0);
    $counts->securityActivity($staff, 0);

    $countQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->map(fn (string $query): string => strtolower($query))
        ->filter(fn (string $query): bool => str_contains($query, 'count(*)'));

    expect($countQueries->filter(fn (string $query): bool => str_contains($query, 'reports'))->count())->toBe(1)
        ->and($countQueries->filter(fn (string $query): bool => str_contains($query, 'activity_logs'))->count())->toBe(1);
});
