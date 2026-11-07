@php($cfmWorkspace = request()->routeIs('cfm.*'))
@php($caseRoutePrefix = $cfmWorkspace ? 'cfm.' : '')
@extends($cfmWorkspace ? 'layouts.department' : 'layouts.app')

@section('page_title', 'Linked reports · '.$caseFile->public_id.' — '.config('app.name'))
@unless ($cfmWorkspace)
    @section('page_label', 'CASE RELATIONSHIPS')
@endunless

@section($cfmWorkspace ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> CASE FILE <span class="eyebrow-index">{{ $caseFile->public_id }}</span></div>
            <h1>Linked reports <span>archive</span></h1>
            <p class="page-description">Connected intelligence · {{ $caseFile->title }}</p>
        </div>
        <a class="button-secondary" href="{{ route($caseRoutePrefix.'cases.show', $caseFile) }}" wire:navigate>← BACK TO CASE FILE</a>
    </section>

    <section class="case-linked-directory">
        <form method="GET" action="{{ route($caseRoutePrefix.'cases.reports', $caseFile) }}" class="case-linked-search">
            <i class="ri ri-search-line" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search linked report text" aria-label="Search linked reports">
            @if ($search !== '')
                <a href="{{ route($caseRoutePrefix.'cases.reports', $caseFile) }}" wire:navigate aria-label="Clear search"><i class="ri ri-close-line" aria-hidden="true"></i></a>
            @endif
            <button type="submit">SEARCH</button>
        </form>

        @if ($reports->isNotEmpty())
            <div class="case-linked-report-grid case-linked-report-grid--directory">
                @foreach ($reports as $report)
                    @include('cases._linked-report-card', [
                        'report' => $report,
                        'reportIndexRoute' => $reportIndexRoute,
                    ])
                @endforeach
            </div>
            <div class="records-pagination">{{ $reports->links() }}</div>
        @else
            <div class="records-empty">
                <span class="archive-preview-mark"><i class="ri ri-file-text-line" aria-hidden="true"></i></span>
                <h2>No linked reports found</h2>
                <p>{{ $search !== '' ? 'Try another phrase from the report narrative.' : 'No reports are currently linked to this case file.' }}</p>
            </div>
        @endif
    </section>
@endsection
