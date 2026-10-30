<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="app-workspace" content="admin">
        <meta name="broadcast-connection" content="{{ config('broadcasting.default') }}">
        <meta name="application-acronym" content="{{ config('app.acronym') }}">
        <meta name="description" content="{{ config('app.name') }} secure file archive command center.">
        <meta name="dashboard-snapshot-url" content="{{ route('dashboard.snapshot') }}">
        <meta name="upload-user-id" content="{{ auth('web')->id() }}">
        <meta name="upload-admin-id" content="{{ auth('admin')->id() }}">
        <title>@yield('page_title', $page_title ?? config('app.name'))</title>
        @livewireStyles
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
                <a class="brand" href="{{ route('dashboard.index') }}" wire:navigate aria-label="{{ config('app.name') }} home">
                    <span class="brand-seal" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none"><path d="M24 4.5 39 10v12.4c0 9.8-6.1 16.4-15 21.1-8.9-4.7-15-11.3-15-21.1V10l15-5.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M24 14a6 6 0 0 0-3.6 10.8v7.7h7.2v-7.7A6 6 0 0 0 24 14Z" stroke="currentColor" stroke-width="1.5"/><circle cx="24" cy="20" r="1.5" fill="currentColor"/><path d="M24 21.5v4" stroke="currentColor" stroke-width="1.5"/><path d="M18 36h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
                    </span>
                    <span class="brand-copy"><span class="brand-name">{{ config('app.short_name') }}</span><span class="brand-caption">PRIVATE ARCHIVE</span></span>
                </a>
                <div class="sidebar-rule"></div>
                <p class="nav-label">WORKSPACE</p>
                <nav class="nav-list">
                    <a class="nav-item {{ request()->routeIs('dashboard.index') ? 'is-active' : '' }}" href="{{ route('dashboard.index') }}" wire:navigate @if(request()->routeIs('dashboard.index')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg><span>Archive</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('uploads.create') ? 'is-active' : '' }}" href="{{ route('uploads.create') }}" wire:navigate.hover @if(request()->routeIs('uploads.create')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg><span>Manual upload</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('calculator.index') ? 'is-active' : '' }}" href="{{ route('calculator.index') }}" wire:navigate.hover @if(request()->routeIs('calculator.index')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 12h1M12 12h1M16 12h.01M8 16h1M12 16h1M16 16h.01"/></svg><span>Calculator</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('people.*') ? 'is-active' : '' }}" href="{{ route('people.index') }}" wire:navigate @if(request()->routeIs('people.*')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19.5v-1.2a5.5 5.5 0 0 1 11 0v1.2zM16 5.2a3.2 3.2 0 0 1 0 6.2M17 14a4.7 4.7 0 0 1 3.5 4.5v1"/></svg><span>Person records</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('cases.*') ? 'is-active' : '' }}" href="{{ route('cases.index') }}" wire:navigate @if(request()->routeIs('cases.*')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6.5h6l2 2h8v10.8H4z"/><path d="M8 13h8M8 16.5h5"/></svg><span>Case files</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('avatar-models.*') ? 'is-active' : '' }}" href="{{ route('avatar-models.index') }}" wire:navigate @if(request()->routeIs('avatar-models.*')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9z"/><path d="m4.5 7.7 7.5 4.4 7.5-4.4M12 12v8.5"/></svg><span>Avatar models</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('settings.index') ? 'is-active' : '' }}" href="{{ route('settings.index') }}" wire:navigate @if(request()->routeIs('settings.index')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z"/><path d="m19.4 13.8 1.1.9-1.7 3-1.4-.5a7.6 7.6 0 0 1-1.5.9l-.3 1.5h-3.4l-.3-1.5a7.6 7.6 0 0 1-1.5-.9l-1.4.5-1.7-3 1.1-.9a7 7 0 0 1 0-1.8l-1.1-.9 1.7-3 1.4.5a7.6 7.6 0 0 1 1.5-.9l.3-1.5h3.4l.3 1.5a7.6 7.6 0 0 1 1.5.9l1.4-.5 1.7 3-1.1.9a7 7 0 0 1 0 1.8Z"/></svg><span>Settings</span>
                    </a>
                    <a class="nav-item {{ request()->routeIs('admin.staff.*') ? 'is-active' : '' }}" href="{{ route('admin.staff.index') }}" wire:navigate @if(request()->routeIs('admin.staff.*')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 20v-1.5a5.5 5.5 0 0 1 11 0V20zM16 8h5M18.5 5.5v5"/></svg><span>Staff management</span>
                    </a>
                    @if (auth('admin')->check())
                        <a class="nav-item {{ request()->routeIs('admin.mail.*') ? 'is-active' : '' }}" href="{{ route('admin.mail.index') }}" wire:navigate @if(request()->routeIs('admin.mail.*')) aria-current="page" @endif>
                            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="1.5"/><path d="m4 7 8 6 8-6"/></svg><span>Mail center</span>
                        </a>
                    @endif
                    @php
                        $securityUnreadCount = 0;
                        if (\Illuminate\Support\Facades\Schema::hasTable('activity_logs')
                            && (auth('admin')->check() || (auth('web')->check() && auth('web')->user()->department === null))) {
                            $securityUnreadCount = \App\Models\ActivityLog::query()
                                ->where('id', '>', (int) session('security_activity_last_seen_id', 0))
                                ->count();
                        }
                    @endphp
                    <a class="nav-item {{ request()->routeIs('admin.security.*') ? 'is-active' : '' }}" href="{{ route('admin.security.index') }}" wire:navigate data-security-nav @if(request()->routeIs('admin.security.*')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 20 6v5.5c0 4.7-3.1 7.7-8 9.5-4.9-1.8-8-4.8-8-9.5V6z"/><path d="M12 7v5M12 16h.01"/></svg><span>Auth Security</span><span class="security-unread-badge" data-security-activity-badge data-count="{{ $securityUnreadCount }}">{{ $securityUnreadCount > 99 ? '99+' : $securityUnreadCount }}</span>
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
                    <a class="nav-item {{ request()->routeIs('admin.reports.*') ? 'is-active' : '' }}" href="{{ route('admin.reports.index') }}" wire:navigate data-report-system-nav @if(request()->routeIs('admin.reports.*')) aria-current="page" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5.5h14v10H9l-4 3v-13Z"/><path d="M8.5 9h7M8.5 12h4.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg><span>Report System</span><span class="security-unread-badge" data-report-system-badge data-count="{{ $reportUnreadCount }}" @if($reportUnreadCount === 0) hidden @endif>{{ $reportUnreadCount > 99 ? '99+' : $reportUnreadCount }}</span>
                    </a>
                </nav>
                <div class="sidebar-bottom">
                    <div class="storage-card">
                        <div class="storage-heading"><span class="storage-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6.5h16v11H4z"/><path d="M4 10h16M8 14.5h.01M11 14.5h.01"/></svg></span><span>PRIVATE STORAGE</span></div>
                        <p>Configure a private disk before receiving or serving files.</p>
                        <div class="storage-status"><span class="status-dot"></span> VAULT DISK <span class="status-value">READY</span></div>
                    </div>
                    <div class="sidebar-footer"><span class="footer-mark">{{ config('app.symbol') }}</span><span>{{ strtoupper(config('app.short_name')) }} SYSTEMS<br><span class="footer-muted">BUILD 01.00</span></span><span class="connection-indicator"></span></div>
                    <form class="sidebar-logout" method="POST" action="{{ route('auth.logout') }}" x-data x-on:submit.prevent="window.vaultConfirm({ title: 'Sign out', message: 'Sign out of your administrator session?', confirmLabel: 'SIGN OUT', destructive: true }).then(confirmed => confirmed && $el.submit())">@csrf<button type="submit">SIGN OUT <span aria-hidden="true">↗</span></button></form>
                </div>
            </aside>
            <main class="main-panel">
                <div class="classification-strip"><span class="classification-marker"></span><span>RESTRICTED</span><span class="classification-divider">/</span><span>AUTHORIZED PERSONNEL ONLY</span><span class="classification-right">{{ strtoupper(config('app.name')) }} SYSTEMS <span>•</span> PRIVATE</span></div>
                <header class="topbar">
                    <button class="mobile-menu" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false" data-menu-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
                    <div class="breadcrumb"><span>{{ strtoupper(config('app.acronym')) }}</span><span class="breadcrumb-slash">/</span><strong>@yield('page_label', $page_label ?? 'ARCHIVE')</strong></div>
                    <div class="topbar-tools">
                        @yield('topbar_tools')
                        <button class="upload-tracker-toggle" type="button" data-upload-tracker-toggle aria-label="Open upload tracker" aria-controls="upload-tracker-panel" aria-expanded="false">
                            <i class="ri ri-upload-cloud-2-line" aria-hidden="true"></i><span class="upload-tracker-toggle__count" data-upload-tracker-badge hidden>0</span>
                        </button>
                        <div class="session-status"><span class="status-dot"></span><span>SECURE SESSION</span></div>
                        <div class="clock" data-clock>--:--:--</div>
                        <div class="operator-button" aria-label="Signed in as {{ auth()->user()->name }}">
                            <span class="operator-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="operator-name">{{ auth()->user()->name }}</span>
                        </div>
                    </div>
                </header>
                <div class="content">@yield('content')</div>
            </main>
        </div>
        <div class="mobile-scrim" data-menu-close></div>
        @include('components.vault-shell')
        <livewire:dashboard-archive />
        @include('components.portable-calculator')
        @include('components.video-upload-widget')
        <x-image-viewer />
        @persist('alert-host')
            <x-alert-host />
        @endpersist
        @livewireScripts
    </body>
</html>
