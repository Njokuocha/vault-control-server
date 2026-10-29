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
        @php
            $department = auth('web')->user()->department;
            $departmentRecord = auth('web')->user()->departmentRecord;
            $profileManagement = $department === 'pm';
            $fileManagement = $department === 'fm';
        @endphp
        @if (in_array($department, ['cfm', 'pm', 'fm'], true))
            <div class="app-shell cfm-app-shell">
                <aside class="sidebar" id="sidebar" aria-label="{{ $fileManagement ? 'File Management' : ($profileManagement ? 'Profile Management' : 'Case File Management') }} navigation">
                    <a class="brand" href="{{ route($department.'.dashboard') }}" wire:navigate aria-label="{{ config('app.name') }} {{ $fileManagement ? 'File Management' : ($profileManagement ? 'Profile Management' : 'Case File Management') }} home">
                        <span class="brand-seal" aria-hidden="true">
                            <svg viewBox="0 0 48 48" fill="none"><path d="M24 4.5 39 10v12.4c0 9.8-6.1 16.4-15 21.1-8.9-4.7-15-11.3-15-21.1V10l15-5.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M24 14a6 6 0 0 0-3.6 10.8v7.7h7.2v-7.7A6 6 0 0 0 24 14Z" stroke="currentColor" stroke-width="1.5"/><circle cx="24" cy="20" r="1.5" fill="currentColor"/><path d="M24 21.5v4" stroke="currentColor" stroke-width="1.5"/><path d="M18 36h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                        </span>
                        <span class="brand-copy"><span class="brand-name">{{ config('app.short_name') }}</span><span class="brand-caption">{{ $fileManagement ? 'FILE OPERATIONS' : ($profileManagement ? 'IDENTITY OPERATIONS' : 'CASE OPERATION') }}</span></span>
                    </a>
                    <div class="sidebar-rule"></div>
                    <p class="nav-label">{{ $fileManagement ? 'FILE MANAGEMENT' : ($profileManagement ? 'PROFILE MANAGEMENT' : 'CASE FILE MANAGEMENT') }}</p>
                    <nav class="nav-list" aria-label="Workspace">
                        <a class="nav-item {{ request()->routeIs($department.'.dashboard') ? 'is-active' : '' }}" href="{{ route($department.'.dashboard') }}" wire:navigate @if (request()->routeIs($department.'.dashboard')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg><span>{{ $fileManagement ? 'Archive' : 'Workspace' }}</span>
                        </a>
                        @if ($fileManagement)
                            <a class="nav-item {{ request()->routeIs('fm.uploads.*') ? 'is-active' : '' }}" href="{{ route('fm.uploads.create') }}" wire:navigate @if (request()->routeIs('fm.uploads.*')) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg><span>Manual upload</span>
                            </a>
                            <a class="nav-item {{ request()->routeIs('fm.person-media.*') ? 'is-active' : '' }}" href="{{ route('fm.person-media.index') }}" wire:navigate @if (request()->routeIs('fm.person-media.*')) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="4" width="17" height="16" rx="1.5"/><circle cx="9" cy="10" r="2"/><path d="m5 17 4.5-4 3.2 2.5 2.8-3 3.5 4.5"/></svg><span>Person media</span>
                            </a>
                        @elseif ($profileManagement)
                            <a class="nav-item {{ request()->routeIs('pm.people.*') ? 'is-active' : '' }}" href="{{ route('pm.people.index') }}" wire:navigate @if (request()->routeIs('pm.people.*')) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 20v-1a5.5 5.5 0 0 1 11 0v1z"/><path d="M16 5.5a3 3 0 0 1 0 5.8M17 15a5 5 0 0 1 3.5 4.8"/></svg><span>Person profiles</span>
                            </a>
                        @else
                            <a class="nav-item {{ request()->routeIs('cfm.cases.*') ? 'is-active' : '' }}" href="{{ route('cfm.cases.index') }}" wire:navigate @if (request()->routeIs('cfm.cases.*')) aria-current="page" @endif>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5h6l2 2h8v10.8H4z"/><path d="M8 13h8M8 16.5h5"/></svg><span>Case files</span>
                            </a>
                        @endif
                        <a class="nav-item {{ request()->routeIs($department.'.profile') ? 'is-active' : '' }}" href="{{ route($department.'.profile') }}" wire:navigate @if (request()->routeIs($department.'.profile')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.2"/><path d="M5 20v-1.2a7 7 0 0 1 14 0V20z"/></svg><span>My profile</span>
                        </a>
                        @php
                            $reportUnreadCount = 0;
                            if (\Illuminate\Support\Facades\Schema::hasTable('reports')) {
                                $reportUnreadCount = app(\App\Services\ReportAccess::class)
                                    ->visibleQuery()
                                    ->where('id', '>', (int) session('report_system_last_seen_id', 0))
                                    ->count();
                            }
                        @endphp
                        <a class="nav-item {{ request()->routeIs($department.'.reports.*') ? 'is-active' : '' }}" href="{{ route($department.'.reports.index') }}" wire:navigate data-report-system-nav @if (request()->routeIs($department.'.reports.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5.5h14v10H9l-4 3v-13Z"/><path d="M8.5 9h7M8.5 12h4.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg><span>Report System</span><span class="security-unread-badge" data-report-system-badge data-count="{{ $reportUnreadCount }}" @if($reportUnreadCount === 0) hidden @endif>{{ $reportUnreadCount > 99 ? '99+' : $reportUnreadCount }}</span>
                        </a>
                    </nav>
                    <div class="sidebar-bottom">
                        <div class="storage-card">
                            <div class="storage-heading"><span class="storage-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 20 6v6c0 5-3.3 7.7-8 9-4.7-1.3-8-4-8-9V6z"/><path d="m8 12 2.5 2.5L16 9"/></svg></span><span>SECURE SESSION</span></div>
                            <p>{{ $fileManagement ? 'Archive and media operations are restricted to your assigned department.' : ($profileManagement ? 'Profile operations are restricted to your assigned department.' : 'Case operations are restricted to your assigned department.') }}</p>
                            <div class="storage-status"><span class="status-dot"></span> ACCESS <span class="status-value">AUTHORIZED</span></div>
                        </div>
                        <div class="sidebar-footer"><span class="footer-mark">{{ config('app.symbol') }}</span><span>{{ strtoupper(config('app.short_name')) }} SYSTEMS<br><span class="footer-muted">{{ strtoupper($departmentRecord?->rank ?? 'AUTHORIZED STAFF') }}</span></span><span class="connection-indicator"></span></div>
                        <form class="sidebar-logout" method="POST" action="{{ route($department.'.logout') }}" x-data x-on:submit.prevent="window.vaultConfirm({ title: 'Sign out', message: 'Sign out of your {{ $fileManagement ? 'File Management' : ($profileManagement ? 'Profile Management' : 'Case File Management') }} session?', confirmLabel: 'SIGN OUT', destructive: true }).then(confirmed => confirmed && $el.submit())">@csrf<button type="submit">SIGN OUT <span aria-hidden="true">↗</span></button></form>
                    </div>
                </aside>
                <main class="main-panel">
                    <div class="classification-strip"><span class="classification-marker"></span><span>RESTRICTED</span><span class="classification-divider">/</span><span>AUTHORIZED PERSONNEL ONLY</span><span class="classification-right">{{ strtoupper(config('app.name')) }} SYSTEMS <span>•</span> {{ $fileManagement ? 'FILE MANAGEMENT' : ($profileManagement ? 'PROFILE MANAGEMENT' : 'CASE OPERATIONS') }}</span></div>
                    <header class="topbar">
                        <button class="mobile-menu" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false" data-menu-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
                        <div class="breadcrumb"><span>{{ strtoupper(config('app.acronym')) }}</span><span class="breadcrumb-slash">/</span><strong>{{ $fileManagement ? 'FILE MANAGEMENT' : ($profileManagement ? 'PROFILE MANAGEMENT' : 'CASE FILE MANAGEMENT') }}</strong></div>
                        <div class="topbar-tools">
                            @if ($fileManagement)
                                <button class="console-launch-button" type="button" data-shell-open aria-label="Open Vault Shell (Ctrl+Shift+X)" title="Open Vault Shell · Ctrl+Shift+X">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 7-5 5 5 5M13 17h7"/></svg><span>SHELL</span>
                                </button>
                            @endif
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
            @if ($fileManagement)
                @persist('fm-vault-shell')
                @include('components.vault-shell')
                @endpersist
                @persist('fm-dashboard-archive')
                <livewire:dashboard-archive />
                @endpersist
            @endif
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
                        <form method="POST" action="{{ url('/'.$department.'/logout') }}" x-data x-on:submit.prevent="window.vaultConfirm({ title: 'Sign out', message: 'Sign out of your {{ $departmentRecord?->name ?? $department }} session?', confirmLabel: 'SIGN OUT', destructive: true }).then(confirmed => confirmed && $el.submit())">@csrf<button type="submit">SIGN OUT</button></form>
                    </nav>
                </header>
                <main class="department-content">@yield('department_content')</main>
                <footer class="department-footer">RESTRICTED · AUTHORIZED PERSONNEL ONLY <span>{{ strtoupper(config('app.acronym')) }} SYSTEMS</span></footer>
            </div>
        @endif
        @include('components.video-upload-widget')
        @if (auth('web')->user()->department === 'fm')
            @persist('fm-file-preview')
                <x-file-preview-modal />
            @endpersist
        @else
            <x-file-preview-modal />
        @endif
        <x-alert-host />
        @livewireScripts
    </body>
</html>
