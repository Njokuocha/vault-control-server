<?php

namespace App\Http\Middleware;

use App\Support\PerformanceQueryListener;
use App\Support\RequestPerformanceMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MeasureRequestPerformance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('performance.enabled')) {
            return $next($request);
        }

        $metrics = new RequestPerformanceMetrics;
        $startedAt = hrtime(true);
        $status = 500;
        $request->attributes->set(RequestPerformanceMetrics::REQUEST_ATTRIBUTE, $metrics);
        app(PerformanceQueryListener::class)->register();

        try {
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } finally {
            $request->attributes->remove(RequestPerformanceMetrics::REQUEST_ATTRIBUTE);
            $metrics->log($request, $startedAt, $status);
        }
    }
}
