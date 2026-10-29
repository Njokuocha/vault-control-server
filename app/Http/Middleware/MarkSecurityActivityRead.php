<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MarkSecurityActivityRead
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->session()->put('security_activity_last_seen_id', (int) ActivityLog::query()->max('id'));

        return $next($request);
    }
}
