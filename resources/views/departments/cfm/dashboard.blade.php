@extends('layouts.department')

@section('page_title', 'Case File Management — '.config('app.name'))
@section('department_content')
    <div class="department-dashboard cfm-dashboard">
        <div class="cfm-dashboard__heading">
            <div>
                <span class="section-kicker">CASE FILE MANAGEMENT · OPERATIONS</span>
                <h1>Case Manager Workspace</h1>
                <p>Manage case records, narratives, classifications, and supporting evidence.</p>
            </div>
            <a class="button-primary" href="{{ route('cfm.cases.create') }}" wire:navigate>CREATE CASE FILE <span aria-hidden="true">＋</span></a>
        </div>

        <section class="cfm-summary-grid" aria-label="Case file summary">
            <article><span>ALL CASE FILES</span><strong>{{ number_format($summary['total']) }}</strong><i class="ri ri-folder-shield-2-line" aria-hidden="true"></i></article>
            <article><span>OPEN CASES</span><strong>{{ number_format($summary['open']) }}</strong><i class="ri ri-folder-open-line" aria-hidden="true"></i></article>
            <article><span>UNDER REVIEW</span><strong>{{ number_format($summary['under_review']) }}</strong><i class="ri ri-search-eye-line" aria-hidden="true"></i></article>
            <article><span>HIGHLY SENSITIVE</span><strong>{{ number_format($summary['sensitive']) }}</strong><i class="ri ri-shield-keyhole-line" aria-hidden="true"></i></article>
        </section>

        <section class="cfm-recent-cases">
            <header><div><span class="section-kicker">CURRENT RECORDS</span><h2>Recent case files</h2></div><a class="text-action" href="{{ route('cfm.cases.index') }}" wire:navigate>VIEW ALL CASES ↗</a></header>
            @forelse ($recentCases as $caseFile)
                <a class="cfm-recent-case" href="{{ route('cfm.cases.show', $caseFile) }}" wire:navigate>
                    <span class="cfm-recent-case__mark ri ri-folder-shield-2-line" aria-hidden="true"></span>
                    <span class="cfm-recent-case__details"><strong>{{ $caseFile->title }}</strong><small>{{ $caseFile->public_id }} · {{ $caseFile->updated_at->format('M j, Y') }} · BY {{ $caseFile->creator?->name ?? 'HISTORICAL RECORD' }}</small></span>
                    <span @class([
                        'case-classification-chip',
                        'legend-classified' => $caseFile->classification === 'CLASSIFIED',
                        'legend-sensitive' => $caseFile->classification === 'HIGHLY SENSITIVE',
                        'legend-accessible' => $caseFile->classification === 'ACCESSIBLE',
                    ])>{{ $caseFile->classification }}</span>
                    <span @class([
                        'cfm-recent-case__status',
                        'cfm-status--open' => $caseFile->case_status === 'OPEN',
                        'cfm-status--review' => $caseFile->case_status === 'UNDER REVIEW',
                        'cfm-status--closed' => $caseFile->case_status === 'CLOSED',
                    ])>{{ $caseFile->case_status }}</span>
                    <span class="ri ri-arrow-right-line" aria-hidden="true"></span>
                </a>
            @empty
                <div class="profile-gallery-empty">There are no case files yet. Create the first record to begin.</div>
            @endforelse
        </section>
    </div>
@endsection
