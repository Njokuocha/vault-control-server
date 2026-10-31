<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RequestPerformanceMetrics
{
    public const REQUEST_ATTRIBUTE = 'vault.request-performance-metrics';

    private int $queryCount = 0;

    private float $queryTimeMs = 0;

    public function record(QueryExecuted $query): void
    {
        $this->queryCount++;
        $this->queryTimeMs += $query->time;
    }

    public function log(Request $request, int $startedAt, int $status): void
    {
        $route = $request->route();

        Log::info('performance.request', [
            'method' => $request->method(),
            'route' => $route?->getName() ?? $route?->uri() ?? 'unmatched',
            'status' => $status,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'db_query_count' => $this->queryCount,
            'db_query_time_ms' => round($this->queryTimeMs, 2),
        ]);
    }
}
