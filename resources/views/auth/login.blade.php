<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>Authenticate — {{ config('app.name') }}</title>
        @livewireStyles
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-page">
        <main class="auth-screen">
            <div class="auth-classification"><span class="classification-marker"></span> RESTRICTED SYSTEM <span>/</span> AUTHORIZED ACCESS ONLY</div>

            <livewire:login-form />

            <footer class="auth-footer">
                <div class="auth-system-line"><span class="status-dot"></span> SYSTEM OPERATIONAL <span class="auth-footer-divider">·</span> <time data-clock>--:--:--</time><span class="auth-build">SECURE ACCESS</span></div>
                <p>Unauthorized access is prohibited and may be logged.</p>
            </footer>
        </main>
        @livewireScripts
    </body>
</html>
