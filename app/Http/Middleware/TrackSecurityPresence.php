<?php

namespace App\Http\Middleware;

use App\Services\SecuritySessionMetrics;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackSecurityPresence
{
    public function handle(Request $request, Closure $next): Response
    {
        app(SecuritySessionMetrics::class)->trackRequest($request);

        return $next($request);
    }
}
