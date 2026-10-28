<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="app-workspace" content="{{ auth('web')->user()->department }}">
        <meta name="application-acronym" content="{{ config('app.acronym') }}">
        <meta name="upload-user-id" content="{{ auth('web')->id() }}">
        <title>@yield('page_title', config('app.name'))</title>
        @livewireStyles
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="department-page">
        @php($department = auth('web')->user()->department)
        @if ($department === 'cfm')
            <div class="app-shell cfm-app-shell">
                <aside class="sidebar" id="sidebar" aria-label="Case File Management navigation">
                    <a class="brand" href="{{ route('cfm.dashboard') }}" wire:navigate aria-label="{{ config('app.name') }} Case File Management home">
                        <span class="brand-seal" aria-hidden="true">
                            <svg viewBox="0 0 48 48" fill="none"><path d="M24 4.5 39 10v12.4c0 9.8-6.1 16.4-15 21.1-8.9-4.7-15-11.3-15-21.1V10l15-5.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M24 14a6 6 0 0 0-3.6 10.8v7.7h7.2v-7.7A6 6 0 0 0 24 14Z" stroke="currentColor" stroke-width="1.5"/><circle cx="24" cy="20" r="1.5" fill="currentColor"/><path d="M24 21.5v4" stroke="currentColor" stroke-width="1.5"/><path d="M18 36h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        </span>
                        <span class="brand-copy"><span class="brand-name">{{ config('app.short_name') }}</span><span class="brand-caption">CASE OPERATION</span></span>
                    </a>
                    <div class="sidebar-rule"></div>
                    <p class="nav-label">CASE FILE MANAGEMENT</p>
                    <nav class="nav-list" aria-label="Workspace">
                        <a class="nav-item {{ request()->routeIs('cfm.dashboard') ? 'is-active' : '' }}" href="{{ route('cfm.dashboard') }}" wire:navigate @if (request()->routeIs('cfm.dashboard')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg><span>Workspace</span>
                        </a>
                        <a class="nav-item {{ request()->routeIs('cfm.cases.*') ? 'is-active' : '' }}" href="{{ route('cfm.cases.index') }}" wire:navigate @if (request()->routeIs('cfm.cases.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5h6l2 2h8v10.8H4z"/><path d="M8 13h8M8 16.5h5"/></svg><span>Case files</span>
                        </a>
                        <a class="nav-item {{ request()->routeIs('cfm.profile') ? 'is-active' : '' }}" href="{{ route('cfm.profile') }}" wire:navigate @if (request()->routeIs('cfm.profile')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.2"/><path d="M5 20v-1.2a7 7 0 0 1 14 0V20z"/></svg><span>My profile</span>
                        </a>
                    </nav>
                    <div class="sidebar-bottom">
                        <div class="storage-card">
                            <div class="storage-heading"><span class="storage-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 20 6v6c0 5-3.3 7.7-8 9-4.7-1.3-8-4-8-9V6z"/><path d="m8 12 2.5 2.5L16 9"/></svg></span><span>SECURE SESSION</span></div>
                            <p>Case operations are restricted to your assigned department.</p>
                            <div class="storage-status"><span class="status-dot"></span> ACCESS <span class="status-value">AUTHORIZED</span></div>
                        </div>
                        <div class="sidebar-footer"><span class="footer-mark">{{ config('app.symbol') }}</span><span>{{ strtoupper(config('app.short_name')) }} SYSTEMS<br><span class="footer-muted">CASE MANAGER</span></span><span class="connection-indicator"></span></div>
                        <form class="sidebar-logout" method="POST" action="{{ route('cfm.logout') }}">@csrf<button type="submit">SIGN OUT <span aria-hidden="true">↗</span></button></form>
                    </div>
                </aside>
                <main class="main-panel">
                    <div class="classification-strip"><span class="classification-marker"></span><span>RESTRICTED</span><span class="classification-divider">/</span><span>AUTHORIZED PERSONNEL ONLY</span><span class="classification-right">{{ strtoupper(config('app.name')) }} SYSTEMS <span>•</span> CASE OPERATIONS</span></div>
                    <header class="topbar">
                        <button class="mobile-menu" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false" data-menu-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
                        <div class="breadcrumb"><span>{{ strtoupper(config('app.acronym')) }}</span><span class="breadcrumb-slash">/</span><strong>CASE FILE MANAGEMENT</strong></div>
                        <div class="topbar-tools">
                            <button class="upload-tracker-toggle" type="button" data-upload-tracker-toggle aria-label="Open upload tracker" aria-controls="upload-tracker-panel" aria-expanded="false">
                                <span aria-hidden="true">⇧</span><span class="upload-tracker-toggle__count" data-upload-tracker-badge hidden>0</span>
                            </button>
                            <div class="session-status"><span class="status-dot"></span><span>SECURE SESSION</span></div>
                            <div class="clock" data-clock>--:--:--</div>
                            <div class="operator-button" aria-label="Signed in as {{ auth('web')->user()->name }}">
                                <span class="operator-avatar">{{ strtoupper(substr(auth('web')->user()->name, 0, 1)) }}</span>
                                <span class="operator-name">{{ auth('web')->user()->name }}</span>
                            </div>
                        </div>
                    </header>
                    <div class="content department-content">@yield('department_content')</div>
                    <footer class="department-footer">RESTRICTED · AUTHORIZED PERSONNEL ONLY <span>{{ strtoupper(config('app.acronym')) }} SYSTEMS</span></footer>
                </main>
            </div>
            <div class="mobile-scrim" data-menu-close></div>
        @else
            <div class="department-shell">
                <header class="department-topbar">
                    <a class="brand" href="{{ '/'.$department }}" wire:navigate><span class="footer-mark">{{ config('app.symbol') }}</span><span class="brand-copy"><span class="brand-name">{{ config('app.short_name') }}</span><span class="brand-caption">{{ strtoupper($department) }} DEPARTMENT</span></span></a>
                    <nav aria-label="Department navigation">
                        <a href="{{ '/'.$department }}" wire:navigate>WORKSPACE</a>
                        <a href="{{ '/'.$department.'/profile' }}" wire:navigate>MY PROFILE</a>
                        <button class="upload-tracker-toggle" type="button" data-upload-tracker-toggle aria-label="Open upload tracker" aria-controls="upload-tracker-panel" aria-expanded="false">
                            <span aria-hidden="true">⇧</span><span class="upload-tracker-toggle__count" data-upload-tracker-badge hidden>0</span>
                        </button>
                        <form method="POST" action="{{ route($department.'.logout') }}">@csrf<button type="submit">SIGN OUT</button></form>
                    </nav>
                </header>
                <main class="department-content">@yield('department_content')</main>
                <footer class="department-footer">RESTRICTED · AUTHORIZED PERSONNEL ONLY <span>{{ strtoupper(config('app.acronym')) }} SYSTEMS</span></footer>
            </div>
        @endif
        @include('components.video-upload-widget')
        <x-file-preview-modal />
        <x-alert-host />
        @livewireScripts
    </body>
</html>
