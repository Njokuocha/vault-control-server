<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

$handleRequest = static function (): void {
    if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
        require $maintenance;
    }

    /** @var Application $app */
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->handleRequest(Request::capture());
};

if (! function_exists('frankenphp_handle_request')) {
    $handleRequest();

    return;
}

$maxRequests = (int) ($_SERVER['MAX_REQUESTS'] ?? 500);

for ($requestCount = 0; $maxRequests === 0 || $requestCount < $maxRequests; $requestCount++) {
    $keepRunning = frankenphp_handle_request($handleRequest);
    gc_collect_cycles();

    if (! $keepRunning) {
        break;
    }
}
