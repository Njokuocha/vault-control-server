@extends('layouts.app')

@section('page_title', config('app.name') . ' — Secure Archive')
@section('page_label', 'ARCHIVE')

@section('topbar_tools')
    <label class="search-box">
        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg>
        <input type="search" placeholder="Search archive" aria-label="Search archive" data-search>
        <kbd> / </kbd>
    </label>
    <button class="console-launch-button" type="button" data-shell-open aria-label="Open Vault Shell (Ctrl+Shift+X)" title="Open Vault Shell · Ctrl+Shift+X">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 7-5 5 5 5M13 17h7"/></svg>
        <span>SHELL</span>
    </button>
    <span class="topbar-divider"></span>
@endsection

@section('content')
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
            <div class="stat-value" data-dashboard-stat="fileCount">{{ number_format($fileCount) }}</div>
            <div class="stat-foot"><span class="stat-caption">INDEXED RECORDS</span><span class="stat-foot-mark">01</span></div>
        </article>
        <article class="stat-card">
            <div class="stat-top"><span class="stat-label">TOTAL STORAGE</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="7.5" ry="2.8"/><path d="M4.5 5.5v6.4c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8V5.5M4.5 11.9v6.4c0 1.5 3.4 2.8 7.5 2.8s7.5-1.3 7.5-2.8v-6.4"/></svg></span></div>
            <div class="stat-value" data-dashboard-stat="storageLabel">{{ $storageLabel }}</div>
            <div class="stat-foot"><span class="stat-caption">PRIVATE DISK USAGE</span><span class="stat-foot-mark">02</span></div>
        </article>
        <article class="stat-card">
            <div class="stat-top"><span class="stat-label">THIS MONTH</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="4" y="5.5" width="16" height="15" rx="1.5"/><path d="M8 3.5v4M16 3.5v4M4 10h16M8 14h2M13 14h3M8 17h2"/></svg></span></div>
            <div class="stat-value" data-dashboard-stat="uploadedThisMonth">{{ number_format($uploadedThisMonth) }}</div>
            <div class="stat-foot"><span class="stat-caption">NEW ADDITIONS</span><span class="stat-foot-mark">03</span></div>
        </article>
        <article class="stat-card">
            <div class="stat-top"><span class="stat-label">LAST ACTIVITY</span><span class="stat-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/></svg></span></div>
            <div class="stat-value stat-value-text" data-dashboard-stat="lastActivity">{{ $lastActivity ? \Illuminate\Support\Carbon::parse($lastActivity)->format('M j, H:i') : '—' }}</div>
            <div class="stat-foot"><span class="stat-caption">{{ $lastActivity ? 'LATEST FILE ADDED' : 'NO RECENT EVENTS' }}</span><span class="stat-foot-mark">04</span></div>
        </article>
    </section>

    <section class="archive-panel" aria-labelledby="archive-title">
        <div class="archive-toolbar">
            <div>
                <div class="section-kicker">REPOSITORY INDEX</div>
                <h2 id="archive-title">File archive <span class="archive-count" data-archive-count>{{ number_format($files->count()) }}</span></h2>
            </div>
            <div class="archive-controls" role="group" aria-label="File type filters">
                <button class="filter-button is-selected" type="button" data-archive-filter="all" aria-pressed="true">ALL RECORDS <span data-archive-filter-count="fileCount">{{ number_format($fileCount) }}</span></button>
                <button class="filter-button" type="button" data-archive-filter="documents" aria-pressed="false">DOCUMENTS <span data-archive-filter-count="documentCount">{{ number_format($documentCount) }}</span></button>
                <button class="filter-button" type="button" data-archive-filter="media" aria-pressed="false">MEDIA <span data-archive-filter-count="mediaCount">{{ number_format($mediaCount) }}</span></button>
            </div>
        </div>

        <div class="archive-table-head" aria-hidden="true">
            <span>RECORD</span><span>CLASSIFICATION</span><span>UPLOAD METHOD</span><span>DATE ADDED</span><span>SIZE</span><span></span>
        </div>

        @if ($files->isEmpty())
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
                <p>There are no records in this repository yet. Uploaded files will appear here automatically.</p>
                <div class="empty-divider"><span></span><b>INGESTION CHANNEL</b><span></span></div>
                <div class="endpoint-card">
                    <span class="endpoint-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m8.5 7-5 5 5 5M15.5 7l5 5-5 5M14 4l-4 16"/></svg></span>
                    <span class="endpoint-copy"><span>ACTIVE UPLOAD API</span><code>POST&nbsp; /api/upload</code></span>
                    <span class="endpoint-lock" title="Key-protected endpoint"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5.5" y="10" width="13" height="10" rx="1.5"/><path d="M8.5 10V7a3.5 3.5 0 0 1 7 0v3M12 14v2"/></svg></span>
                </div>
            </div>
            <div class="archive-records" data-archive-records hidden></div>
            <div class="archive-full-link-row" hidden>
                <button type="button" class="archive-full-link" data-archive-open>
                    VIEW FULL ARCHIVE <span aria-hidden="true">↗</span>
                </button>
            </div>
            <div class="archive-filter-empty" data-filter-empty hidden>No files match the current search or filter.</div>
        @else
            <div class="archive-records" data-archive-records>
                @foreach ($fileGroups as $month => $monthFiles)
                    <section class="archive-month" data-archive-month>
                        <h3 class="archive-month-heading">{{ $groupLabels[$month] }} <span>{{ $monthFiles->count() }} FILE{{ $monthFiles->count() === 1 ? '' : 'S' }}</span></h3>
                        @foreach ($monthFiles as $file)
                            @php
                                $filterGroup = in_array($file->category, ['image', 'video', 'audio'], true)
                                    ? 'media'
                                    : (in_array($file->category, ['pdf', 'text', 'code'], true) ? 'documents' : 'other');
                            @endphp
                            <article class="archive-record"
                                     data-archive-record
                                     data-archive-search="{{ strtolower($file->original_name.' '.$file->mime_type.' '.$file->category) }}"
                                     data-archive-category="{{ $filterGroup }}">
                                <div class="archive-record-name">
                                    <span class="archive-file-icon" aria-hidden="true">{{ strtoupper($file->extension ?: 'FILE') }}</span>
                                    <span><strong>{{ $file->original_name }}</strong><small>{{ $file->mime_type }}</small></span>
                                </div>
                                <span class="archive-record-category">{{ strtoupper($file->category) }}</span>
                                <span class="upload-method-badge upload-method-{{ $file->upload_method ?? 'unknown' }}">{{ strtoupper($file->upload_method ?? 'UNKNOWN') }}</span>
                                <time class="archive-record-date" datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('M j, Y · H:i') }}</time>
                                <span class="archive-record-size">{{ \App\Support\FormatBytes::format($file->size) }}</span>
                                <div class="archive-record-actions">
                                    <button type="button" data-archive-preview="{{ $file->uuid }}" aria-label="Preview {{ $file->original_name }}">OPEN</button>
                                    <a href="{{ route('files.download', ['file' => $file->uuid]) }}" aria-label="Download {{ $file->original_name }}">↓</a>
                                </div>
                            </article>
                        @endforeach
                    </section>
                @endforeach
            </div>
            <div class="archive-full-link-row">
                <button type="button" class="archive-full-link" data-archive-open>
                    VIEW FULL ARCHIVE <span aria-hidden="true">↗</span>
                </button>
            </div>
            <div class="archive-filter-empty" data-filter-empty hidden>No files match the current search or filter.</div>
        @endif

        <div class="archive-footer">
            <span><span class="status-dot status-dot-muted"></span> INDEX SYNCHRONIZED</span>
            <span data-dashboard-record-summary>{{ number_format($files->count()) }} LATEST OF {{ number_format($fileCount) }} RECORDS <b>·</b> {{ $fileGroups->count() }} GROUPS</span>
        </div>
    </section>

    <footer class="page-footer">
        <span>{{ strtoupper(config('app.name')) }} SECURE FILE MANAGEMENT</span>
        <span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span>
    </footer>
@endsection