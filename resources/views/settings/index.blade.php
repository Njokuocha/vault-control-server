<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <title>Settings — {{ config('app.name') }}</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="app-shell settings-shell">
            <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
                <a class="brand" href="{{ route('dashboard.index') }}" aria-label="{{ config('app.name') }} home">
                    <span class="brand-seal" aria-hidden="true"><svg viewBox="0 0 48 48" fill="none"><path d="M24 4.5 39 10v12.4c0 9.8-6.1 16.4-15 21.1-8.9-4.7-15-11.3-15-21.1V10l15-5.5Z" stroke="currentColor" stroke-width="1.5"/><path d="M24 14a6 6 0 0 0-3.6 10.8v7.7h7.2v-7.7A6 6 0 0 0 24 14Z" stroke="currentColor" stroke-width="1.5"/><circle cx="24" cy="20" r="1.5" fill="currentColor"/><path d="M18 36h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></span>
                    <span class="brand-copy"><span class="brand-name">{{ config('app.name') }}</span><span class="brand-caption">PRIVATE ARCHIVE</span></span>
                </a>
                <div class="sidebar-rule"></div>
                <p class="nav-label">WORKSPACE</p>
                <nav class="nav-list">
                    <a class="nav-item" href="{{ route('dashboard.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg><span>Archive</span></a>
                    <a class="nav-item is-active" href="{{ route('settings.index') }}" aria-current="page"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z"/><path d="m19.4 13.8 1.1.9-1.7 3-1.4-.5a7.6 7.6 0 0 1-1.5.9l-.3 1.5h-3.4l-.3-1.5a7.6 7.6 0 0 1-1.5-.9l-1.4.5-1.7-3 1.1-.9a7 7 0 0 1 0-1.8l-1.1-.9 1.7-3 1.4.5a7.6 7.6 0 0 1 1.5-.9l.3-1.5h3.4l.3 1.5a7.6 7.6 0 0 1 1.5.9l1.4-.5 1.7 3-1.1.9a7 7 0 0 1 0 1.8Z"/></svg><span>Settings</span></a>
                </nav>
                <div class="sidebar-bottom">
                    <div class="storage-card"><div class="storage-heading"><span class="storage-icon"><svg viewBox="0 0 24 24"><path d="M4 6.5h16v11H4z"/><path d="M4 10h16M8 14.5h.01M11 14.5h.01"/></svg></span><span>PRIVATE STORAGE</span></div><p>Configure a private disk before receiving or serving files.</p><div class="storage-status"><span class="status-dot status-dot-pending"></span> VAULT DISK <span class="status-value status-value-pending">SETUP REQUIRED</span></div></div>
                    <div class="sidebar-footer"><span class="footer-mark">V</span><span>{{ strtoupper(config('app.name')) }} SYSTEMS<br><span class="footer-muted">BUILD 01.00</span></span><span class="connection-indicator"></span></div>
                    <form class="sidebar-logout" method="POST" action="{{ route('auth.logout') }}">@csrf<button type="submit">SIGN OUT <span aria-hidden="true">↗</span></button></form>
                </div>
            </aside>

            <main class="main-panel">
                <div class="classification-strip"><span class="classification-marker"></span><span>RESTRICTED</span><span class="classification-divider">/</span><span>AUTHORIZED PERSONNEL ONLY</span><span class="classification-right">{{ strtoupper(config('app.name')) }} SYSTEMS <span>•</span> PRIVATE</span></div>
                <header class="topbar">
                    <button class="mobile-menu" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false" data-menu-toggle><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
                    <div class="breadcrumb"><span>{{ strtoupper(config('app.name')) }}</span><span class="breadcrumb-slash">/</span><strong>SETTINGS</strong></div>
                    <div class="topbar-tools"><div class="session-status"><span class="status-dot status-dot-pending"></span><span>SECURE SESSION</span></div><div class="clock" data-clock>--:--:--</div><div class="operator-button"><span class="operator-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span><span class="operator-name">{{ auth()->user()->name }}</span></div></div>
                </header>

                <div class="content settings-content">
                    <section class="page-heading">
                        <div><div class="eyebrow"><span class="eyebrow-line"></span> SYSTEM CONFIGURATION <span class="eyebrow-index">02 / 04</span></div><h1 id="settings-title">System <span>settings</span></h1><p class="page-description">Manage your ingestion key and administrator credentials.</p></div>
                        <a class="back-link" href="{{ route('dashboard.index') }}"><span aria-hidden="true">←</span> RETURN TO ARCHIVE</a>
                    </section>

                    @if (session('status'))
                        <div class="settings-alert" role="status"><span class="alert-check">✓</span>{{ session('status') }}</div>
                    @endif
                    @if ($errors->any())
                        <div class="settings-alert settings-alert-error" role="alert"><span>!</span>{{ $errors->first() }}</div>
                    @endif

                    <section class="settings-card" aria-labelledby="upload-key-title">
                        <div class="settings-card-head">
                            <span class="settings-card-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8.2" cy="15.8" r="4.2"/><path d="m11.2 12.8 8.3-8.3 2 2-2 2 1.5 1.5-2.2 2.2-1.5-1.5-3.1 3.1"/></svg></span>
                            <div><div class="section-kicker">API ACCESS CONTROL</div><h2 id="upload-key-title">Upload key</h2></div>
                            <span class="settings-card-index">01</span>
                        </div>
                        <p class="settings-description">This secret authorizes external clients to upload files. It is encrypted at rest. Rotating the key immediately invalidates the previous value.</p>

                        <div class="setting-field-label">CURRENT API KEY</div>
                        <div class="secret-display">
                            <code id="current-upload-key">{{ $uploadKey ?? 'No key configured' }}</code>
                            @if ($uploadKey)
                                <button class="icon-action" type="button" aria-label="Copy current upload key" title="Copy key" data-copy-target="current-upload-key"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="12" rx="1.5"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/></svg></button>
                            @endif
                        </div>

                        <form method="POST" action="{{ route('settings.key.reset') }}" class="key-form">
                            @csrf
                            <label class="setting-field-label" for="upload-key">SET A CUSTOM KEY</label>
                            <div class="key-input-row">
                                <input id="upload-key" type="text" name="upload_key" value="{{ old('upload_key') }}" placeholder="Enter a strong custom key" autocomplete="off" maxlength="255" required>
                                <button class="button-secondary" type="button" data-generate-key>GENERATE</button>
                                <button class="button-primary" type="submit">ROTATE KEY</button>
                            </div>
                            @error('upload_key')<p class="field-error">{{ $message }}</p>@enderror
                            <p class="field-hint">Use a unique, randomly generated value. Anyone who obtains this key can upload files.</p>
                        </form>

                        <div class="endpoint-section">
                            <div class="setting-field-label">UPLOAD ENDPOINT</div>
                            <div class="endpoint-url"><span class="endpoint-method">POST</span><code>{{ $uploadEndpoint }}</code><span class="endpoint-pending">HANDLER NOT CONFIGURED</span></div>
                            <div class="integration-notice"><span>i</span><p>The upload endpoint contract is documented, but its API handler is not implemented yet. Upload requests will not work until that backend is added.</p></div>
                            @if ($uploadKey)
                                <div class="curl-sample">
                                    <div class="curl-sample-head"><span>CLIENT REQUEST EXAMPLE</span><button class="text-action" type="button" data-copy-target="curl-example">COPY</button></div>
                                    <code id="curl-example">curl -X POST "{{ $uploadEndpoint }}?auth={{ $uploadKey }}" -F "file=@/path/to/file"</code>
                                </div>
                            @endif
                        </div>
                    </section>

                    <section class="settings-card password-card" aria-labelledby="password-title">
                        <div class="settings-card-head">
                            <span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg></span>
                            <div><div class="section-kicker">ACCOUNT SECURITY</div><h2 id="password-title">Administrator password</h2></div>
                            <span class="settings-card-index">02</span>
                        </div>
                        <p class="settings-description">Update the password used to authenticate this administrator account. Your current password is required.</p>

                        <form method="POST" action="{{ route('settings.password.update') }}" class="password-form">
                            @csrf
                            <label class="password-field" for="current-password"><span class="setting-field-label">CURRENT PASSWORD</span><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></label>
                            @error('current_password')<p class="field-error">{{ $message }}</p>@enderror
                            <div class="password-fields-row">
                                <label class="password-field" for="new-password"><span class="setting-field-label">NEW PASSWORD</span><input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" required></label>
                                <label class="password-field" for="confirm-password"><span class="setting-field-label">CONFIRM NEW PASSWORD</span><input id="confirm-password" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
                            </div>
                            @error('password')<p class="field-error">{{ $message }}</p>@enderror
                            <div class="password-form-footer"><span class="field-hint">Use at least 12 characters.</span><button class="button-primary" type="submit">UPDATE PASSWORD</button></div>
                        </form>
                    </section>

                    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} SECURE FILE MANAGEMENT</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
                </div>
            </main>
        </div>
        <div class="mobile-scrim" data-menu-close></div>
        <div class="toast-region" role="status" aria-live="polite" data-toast></div>
    </body>
</html>
