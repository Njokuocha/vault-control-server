@extends('layouts.app')
@section('page_title', 'Brute-Force Incident — '.config('app.name'))
@section('content')
<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">BRUTE-FORCE INCIDENT · {{ $record->id }}</span><h1>Detected authentication pattern</h1><p>Repeated failed attempts from a single source address.</p></div><a class="button-secondary" href="{{ route('admin.security.brute-force') }}" wire:navigate>BRUTE-FORCE RECORDS</a></div>
    <section class="glass-panel security-detail-card">
        <dl class="security-detail-grid">
            <div><dt>ACCOUNT</dt><dd>{{ $record->account_name }} <small>ID {{ $record->account_id ?? 'N' }}</small></dd></div>
            <div><dt>IP ADDRESS</dt><dd>{{ $record->ip_address }}</dd></div>
            <div><dt>FAILED ATTEMPTS OBSERVED</dt><dd>{{ $record->failed_attempts }}</dd></div>
            <div><dt>DETECTION WINDOW</dt><dd>{{ $record->window_seconds }} seconds</dd></div>
            <div><dt>DETECTED AT</dt><dd>{{ $record->detected_at?->format('M d, Y · H:i:s T') }}</dd></div>
            <div>
                <dt>AUTH EVENT</dt>
                <dd>
                    @if ($record->auth_event_id)
                        <a href="{{ route('admin.security.auth-event', $record->auth_event_id) }}" wire:navigate>View authentication event #{{ $record->auth_event_id }}</a>
                    @else
                        Removed or expired
                    @endif
                </dd>
            </div>
            <div class="security-detail-wide"><dt>USER AGENT</dt><dd>{{ $record->user_agent ?? 'Not recorded' }}</dd></div>
        </dl>
    </section>
</div>
@endsection
