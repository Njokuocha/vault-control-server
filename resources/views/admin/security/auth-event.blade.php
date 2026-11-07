@extends('layouts.app')
@section('page_title', 'Authentication Record — '.config('app.name'))
@section('content')
<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">AUTH SECURITY · EVENT {{ $event->id }}</span><h1>Authentication record</h1><p>Recorded sign-in outcome and request metadata.</p></div><a class="button-secondary" href="{{ route('admin.security.auth-history') }}" wire:navigate>AUTH HISTORY</a></div>
    <section class="glass-panel security-detail-card">
        <dl class="security-detail-grid">
            <div><dt>ACCOUNT</dt><dd>{{ $event->account_name }} <small>ID {{ $event->account_id ?? 'N' }}</small></dd></div>
            <div><dt>GUARD</dt><dd>{{ strtoupper($event->guard) }} · {{ strtoupper($event->account_type ?? 'UNKNOWN') }}</dd></div>
            <div><dt>IP ADDRESS</dt><dd>{{ $event->ip_address }}</dd></div>
            <div><dt>TIME</dt><dd>{{ $event->occurred_at?->format('M d, Y · H:i:s T') }}</dd></div>
            <div><dt>AUTHENTICATION TIME</dt><dd>{{ $event->duration_ms }} ms</dd></div>
            <div><dt>STATUS</dt><dd><span class="security-status security-status--{{ $event->status }}">{{ strtoupper($event->status) }}</span></dd></div>
            <div><dt>BRUTE FORCE</dt><dd><span class="security-boolean {{ $event->is_brute_force ? 'is-true' : 'is-false' }}">{{ $event->is_brute_force ? 'TRUE' : 'FALSE' }}</span></dd></div>
            <div><dt>DETAIL</dt><dd>{{ $event->result_detail ?? 'No additional detail.' }}</dd></div>
            <div class="security-detail-wide"><dt>USER AGENT</dt><dd>{{ $event->user_agent ?? 'Not recorded' }}</dd></div>
        </dl>
    </section>
</div>
@endsection
