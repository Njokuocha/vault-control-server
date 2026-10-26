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
        <div class="heading-meta"><span class="meta-label">SYSTEM DATE</span><time class="meta-value" data-date>—</time></div>
    </section>
    <livewire:dashboard-stats />
    <livewire:dashboard-latest-files />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} SECURE FILE MANAGEMENT</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
