@extends('layouts.app')
@section('page_title', 'Activity Record — '.config('app.name'))
@section('content')
<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">ACTIVITY LOG · EVENT {{ $log->id }}</span><h1>Activity record</h1><p>Administrative audit detail. Secrets such as passwords are never stored in this log.</p></div><a class="button-secondary" href="{{ route('admin.security.activity') }}" wire:navigate>ACTIVITY LOGS</a></div>
    <section class="glass-panel security-detail-card">
        <dl class="security-detail-grid">
            <div><dt>SUMMARY</dt><dd>{{ $log->summary }}</dd></div>
            <div><dt>CATEGORY / ACTION</dt><dd>{{ strtoupper($log->category) }} · {{ $log->action }}</dd></div>
            <div><dt>ACTOR</dt><dd>{{ $log->actor_name }} <small>{{ strtoupper($log->actor_type ?? 'SYSTEM') }} · ID {{ $log->actor_id ?? 'N' }}</small></dd></div>
            <div><dt>IP ADDRESS</dt><dd>{{ $log->ip_address ?? 'Not recorded' }}</dd></div>
            <div><dt>TIME</dt><dd>{{ $log->occurred_at?->format('M d, Y · H:i:s T') }}</dd></div>
            <div class="security-detail-wide"><dt>DETAILS</dt><dd><pre>{{ json_encode($log->details ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></dd></div>
            <div class="security-detail-wide"><dt>USER AGENT</dt><dd>{{ $log->user_agent ?? 'Not recorded' }}</dd></div>
        </dl>
    </section>
</div>
@endsection
