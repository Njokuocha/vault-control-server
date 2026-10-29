<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth:web,admin']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin.workspace' => \App\Http\Middleware\EnsureAdminWorkspace::class,
            'department' => \App\Http\Middleware\EnsureDepartment::class,
            'security.presence' => \App\Http\Middleware\TrackSecurityPresence::class,
            'security.read' => \App\Http\Middleware\MarkSecurityActivityRead::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request): string => match (true) {
            $request->is('cfm', 'cfm/*') => route('cfm.login'),
            $request->is('pm', 'pm/*') => route('pm.login'),
            $request->is('fm', 'fm/*') => route('fm.login'),
            default => route('login'),
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $message = 'The uploaded request is larger than the server permits.';

            if ($request->is('api/upload')) {
                return response()->json([
                    'message' => $message,
                    'errors' => ['file' => [$message]],
                ], 413);
            }

            return back()->withErrors(['files' => $message]);
        });
    })->create();
