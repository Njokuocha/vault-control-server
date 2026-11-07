<?php

namespace App\Http\Middleware;

use App\Services\SettingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyUploadKey
{
    public function __construct(private readonly SettingService $settings)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->settings->apiUploadsEnabled()) {
            return response()->json([
                'status' => 'error',
                'message' => 'API uploads are disabled.',
            ], 403);
        }

        if ($this->settings->maxUploadMegabytes() === 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'Uploads are disabled because the maximum upload size is set to 0 MB.',
            ], 403);
        }

        $given = (string) $request->query('auth', '');
        $actual = $this->settings->get('upload_key');

        if ($given === '' || $actual === null || ! hash_equals($actual, $given)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized.',
            ], 401);
        }

        return $next($request);
    }
}
