<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#080d14">
        <meta name="description" content="{{ config('app.name') }} secure file archive command center.">
        <title>{{ config('app.name') }} — Secure Archive</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar" id="sidebar" aria-label="Primary navigation">
                <a class="brand" href="/" aria-label="{{ config('app.name') }} home">
                    <span class="brand-seal" aria-hidden="true">
                        <svg viewBox="0 0 48 48" fill="none">
                            <path d="M24 4.5 39 10v12.4c0 9.8-6.1 16.4-15 21.1-8.9-4.7-15-11.3-15-21.1V10l15-5.5Z" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M24 14a6 6 0 0 0-3.6 10.8v7.7h7.2v-7.7A6 6 0 0 0 24 14Z" stroke="currentColor" stroke-width="1.5"/>
                            <circle cx="24" cy="20" r="1.5" fill="currentColor"/>
                            <path d="M24 21.5v4" stroke="currentColor" stroke-width="1.5"/>
                            <path d="M18 36h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </span>
                    <span class="brand-copy">
                        <span class="brand-name">{{ config('app.name') }}</span>
                        <span class="brand-caption">PRIVATE ARCHIVE</span>
                    </span>
                </a>

                <div class="sidebar-rule"></div>
                <p class="nav-label">WORKSPACE</p>
                <nav class="nav-list">
                    <a class="nav-item is-active" href="/" aria-current="page">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1"/><rect x="13.5" y="3.5" width="7" height="7" rx="1"/><rect x="3.5" y="13.5" width="7" height="7" rx="1"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/></svg>
                        <span>Archive</span>
                        <span class="nav-count">00</span>
                    </a>
                    <a class="nav-item" href="{{ route('settings.index') }}">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.2a3.8 3.8 0 1 0 0 7.6 3.8 3.8 0 0 0 0-7.6Z"/><path d="m19.4 13.8 1.1.9-1.7 3-1.4-.5a7.6 7.6 0 0 1-1.5.9l-.3 1.5h-3.4l-.3-1.5a7.6 7.6 0 0 1-1.5-.9l-1.4.5-1.7-3 1.1-.9a7 7 0 0 1 0-1.8l-1.1-.9 1.7-3 1.4.5a7.6 7.6 0 0 1 1.5-.9l.3-1.5h3.4l.3 1.5a7.6 7.6 0 0 1 1.5.9l1.4-.5 1.7 3-1.1.9a7 7 0 0 1 0 1.8Z"/></svg>
                        <span>Settings</span>
                    </a>
                </nav>

                <div class="sidebar-bottom">
                    <div class="storage-card">
                        <div class="storage-heading">
                            <span class="storage-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24"><path d="M4 6.5h16v11H4z"/><path d="M4 10h16M8 14.5h.01M11 14.5h.01"/></svg>
                            </span>
                            <span>PRIVATE STORAGE</span>
                        </div>
                        <p>Configure a private disk before receiving or serving files.</p>
                        <div class="storage-status"><span class="status-dot status-dot-pending"></span> VAULT DISK <span class="status-value status-value-pending">SETUP REQUIRED</span></div>
                    </div>
                    <div class="sidebar-footer">
                        <span class="footer-mark">V</span>
                        <span>{{ strtoupper(config('app.name')) }} SYSTEMS<br><span class="footer-muted">BUILD 01.00</span></span>
                        <span class="connection-indicator" title="Interface online" aria-label="Interface online"></span>
                    </div>
                    <form class="sidebar-logout" method="POST" action="{{ route('auth.logout') }}">
                        @csrf
                        <button type="submit">SIGN OUT <span aria-hidden="true">↗</span></button>
                    </form>
                </div>
            </aside>

            <main class="main-panel">
                <div class="classification-strip">
                    <span class="classification-marker"></span>
                    <span>RESTRICTED</span>
                    <span class="classification-divider">/</span>
                    <span>AUTHORIZED PERSONNEL ONLY</span>
                    <span class="classification-right">{{ strtoupper(config('app.name')) }} SYSTEMS <span>•</span> PRIVATE</span>
                </div>

                <header class="topbar">
                    <button class="mobile-menu" type="button" aria-label="Open navigation" aria-controls="sidebar" aria-expanded="false" data-menu-toggle>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                    </button>
                    <div class="breadcrumb"><span>{{ strtoupper(config('app.name')) }}</span><span class="breadcrumb-slash">/</span><strong>ARCHIVE</strong></div>
                    <div class="topbar-tools">
                        <label class="search-box">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg>
                            <input type="search" placeholder="Search archive" aria-label="Search archive" data-search>
                            <kbd> / </kbd>
                        </label>
                        <span class="topbar-divider"></span>
                        <div class="session-status"><span class="status-dot status-dot-pending"></span><span>DESIGN PREVIEW</span></div>
                        <div class="clock" data-clock aria-label="Local time">--:--:--</div>
                        <div class="operator-button" aria-label="Signed in as {{ auth()->user()->name }}">
                            <span class="operator-avatar">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                            <span class="operator-name">{{ auth()->user()->name }}</span>
                        </div>
                    </div>
                </header>

                <div class="content">
                    <section class="page-heading" aria-labelledby="page-title">
                        <div>
                            <div class="eyebrow"><span class="eyebrow-line"></span> CENTRAL RECORDS DIVISION <span class="eyebrow-index">01 / 04</span></div>
                            <h1 id="page-title">Archive <span>overview</span></h1>
                            <p class="page-description">A clear view of your private repository and recent activity.</p>
                        </div>
                        <div class="heading-meta">
                            <span class="meta-label">SYSTEM DATE</span>
                            <time class="meta-value" data-date>—</time>
                        </div>
                    </section>

                    <section class="stats-grid" aria-label="Archive statistics">
                        <article class="stat-card stat-card-featured">
                            <div class="stat-top"><span class="stat-label">TOTAL FILES</span><span class="stat-icon stat-icon-gold" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 3.8h7l5 5v11.4H6z"/><path d="M13 3.8v5h5M9 13h6M9 16h6"/></svg></span></div>
                            <div class="stat-value">0</div>
                            <div class="stat-foot"><span class="stat-caption">INDEXED RECORDS</span><span class="stat-foot-mark">01</span></div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span class="stat-label">TOTAL STORAGE</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v6.4c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8V5.5M4.5 11.9v6.4c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-6.4"/></svg></span></div>
                            <div class="stat-value">0 <span class="stat-unit">B</span></div>
                            <div class="stat-foot"><span class="stat-caption">PRIVATE DISK USAGE</span><span class="stat-foot-mark">02</span></div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span class="stat-label">THIS MONTH</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5.5" width="16" height="15" rx="1.5"/><path d="M8 3.5v4M16 3.5v4M4 10h16M8 14h2M13 14h3M8 17h2"/></svg></span></div>
                            <div class="stat-value">0</div>
                            <div class="stat-foot"><span class="stat-caption">NEW ADDITIONS</span><span class="stat-foot-mark">03</span></div>
                        </article>
                        <article class="stat-card">
                            <div class="stat-top"><span class="stat-label">LAST ACTIVITY</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/></svg></span></div>
                            <div class="stat-value stat-value-text">—</div>
                            <div class="stat-foot"><span class="stat-caption">NO RECENT EVENTS</span><span class="stat-foot-mark">04</span></div>
                        </article>
                    </section>

                    <section class="archive-panel" aria-labelledby="archive-title">
                        <div class="archive-toolbar">
                            <div>
                                <div class="section-kicker">REPOSITORY INDEX</div>
                                <h2 id="archive-title">File archive <span class="archive-count">00</span></h2>
                            </div>
                            <div class="archive-controls" role="group" aria-label="File type filters">
                                <button class="filter-button is-selected" type="button" aria-pressed="true">ALL RECORDS <span>00</span></button>
                                <button class="filter-button" type="button" aria-pressed="false" disabled>DOCUMENTS</button>
                                <button class="filter-button" type="button" aria-pressed="false" disabled>MEDIA</button>
                                <button class="filter-button filter-more" type="button" aria-label="More file filters" disabled>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                                </button>
                            </div>
                        </div>

                        <div class="archive-table-head" aria-hidden="true">
                            <span>RECORD</span><span>CLASSIFICATION</span><span>DATE ADDED</span><span>SIZE</span><span></span>
                        </div>

                        <div class="archive-empty" data-empty-state>
                            <div class="empty-ornament" aria-hidden="true">
                                <span class="orbit orbit-one"></span>
                                <span class="orbit orbit-two"></span>
                                <span class="orbit-center">
                                    <svg viewBox="0 0 48 48" fill="none"><path d="M8 14.5h12l3.2 3.8H40v17H8z" stroke="currentColor" stroke-width="1.5"/><path d="M8 14.5V11h12l3.2 3.5" stroke="currentColor" stroke-width="1.5"/><path d="m19.5 25 3 3 6-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                </span>
                            </div>
                            <div class="empty-code"><span></span> ARCHIVE STATUS <span class="empty-code-muted">/ 000</span></div>
                            <h3>Archive is clear</h3>
                            <p>There are no records in this repository yet. Once private storage and the upload service are configured, incoming files will be indexed here by month.</p>
                            <div class="empty-divider"><span></span><b>INGESTION CHANNEL</b><span></span></div>
                            <div class="endpoint-card">
                                <span class="endpoint-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m8.5 7-5 5 5 5M15.5 7l5 5-5 5M14 4l-4 16"/></svg></span>
                                <span class="endpoint-copy"><span>PLANNED UPLOAD API</span><code>POST&nbsp; /api/upload</code></span>
                                <span class="endpoint-lock" title="Key-protected endpoint"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5.5" y="10" width="13" height="10" rx="1.5"/><path d="M8.5 10V7a3.5 3.5 0 0 1 7 0v3M12 14v2"/></svg></span>
                            </div>
                        </div>

                        <div class="archive-footer">
                            <span><span class="status-dot status-dot-muted"></span> INDEX SYNCHRONIZED</span>
                            <span>0 RECORDS <b>·</b> 0 GROUPS</span>
                        </div>
                    </section>

                    <footer class="page-footer">
                        <span>{{ strtoupper(config('app.name')) }} SECURE FILE MANAGEMENT</span>
                        <span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span>
                    </footer>
                </div>
            </main>
        </div>
        <div class="mobile-scrim" data-menu-close></div>
        <div class="toast-region" role="status" aria-live="polite" data-toast></div>
    </body>
</html>
