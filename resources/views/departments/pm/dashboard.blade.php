@extends('layouts.department')

@section('page_title', 'Profile Management — '.config('app.name'))
@section('department_content')
    <div class="cfm-dashboard">
        <header class="cfm-dashboard__heading">
            <div>
                <span class="section-kicker">PROFILE MANAGEMENT WORKSPACE</span>
                <h1>Identity <span>operations</span></h1>
                <p>Manage person profiles and their protected identity media.</p>
            </div>
            <a class="button-primary" href="{{ route('pm.people.create') }}" wire:navigate>＋ CREATE PROFILE</a>
        </header>
        <section class="cfm-summary-grid" aria-label="Profile management summary">
            <article><span>ALL PROFILES</span><strong>{{ number_format($summary['total']) }}</strong><i>USR</i></article>
            <article><span>CREATED THIS MONTH</span><strong>{{ number_format($summary['created_this_month']) }}</strong><i>＋</i></article>
            <article><span>UPDATED THIS MONTH</span><strong>{{ number_format($summary['updated_this_month']) }}</strong><i>↻</i></article>
            <article><span>GALLERY ITEMS</span><strong>{{ number_format($summary['gallery_items']) }}</strong><i>▧</i></article>
        </section>
        <section class="cfm-recent-cases pm-recent-profiles">
            <header>
                <div><span class="section-kicker">RECENTLY UPDATED</span><h2>Person profiles</h2></div>
                <a class="text-action" href="{{ route('pm.people.index') }}" wire:navigate>OPEN PROFILE INDEX <span aria-hidden="true">↗</span></a>
            </header>
            @forelse ($recentProfiles as $person)
                <a class="cfm-recent-case pm-recent-profile" href="{{ route('pm.people.show', $person) }}" wire:navigate>
                    <span class="case-link-mark">USR</span>
                    <span class="cfm-recent-case__details"><strong>{{ $person->name }}</strong><small>{{ $person->public_id }} · Updated {{ $person->updated_at->diffForHumans() }}</small></span>
                    <span class="person-status-badge status-{{ strtolower($person->flag) }}">{{ strtoupper($person->flag) }}</span>
                    <span class="case-index-card-creator" aria-hidden="true">↗</span>
                </a>
            @empty
                <p class="linked-case-empty">No person profiles have been created yet.</p>
            @endforelse
        </section>
    </div>
@endsection
