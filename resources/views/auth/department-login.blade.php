<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ $departmentName }} — {{ config('app.name') }}</title>
        @livewireStyles
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-page">
        <main class="auth-screen">
            <div class="auth-classification"><span class="classification-marker"></span> RESTRICTED SYSTEM <span>/</span> {{ strtoupper($departmentName) }}</div>
            <livewire:department-login-form :department="$department" />
            <footer class="auth-footer">
                <div class="auth-system-line"><span class="status-dot"></span> SECURE ACCESS <span class="auth-footer-divider">·</span> <time data-clock>--:--:--</time><a class="department-login-home" href="{{ route('home') }}" wire:navigate>ALL DEPARTMENTS</a></div>
                <p>Unauthorized access is prohibited and may be logged.</p>
            </footer>
        </main>
        <x-alert-host />
        @livewireScripts
    </body>
</html>
