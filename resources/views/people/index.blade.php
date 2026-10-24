@extends('layouts.app')

@section('page_title', 'Person records — ' . config('app.name'))
@section('page_label', 'PERSON RECORDS')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> SUBJECT INDEX <span class="eyebrow-index">04 / 06</span></div><h1>Person <span>records</span></h1><p class="page-description">Private profiles and verified links to associated case files.</p></div>
        <a href="{{ route('people.create') }}" class="button-primary">＋ NEW PERSON</a>
    </section>
    <section class="records-toolbar">
        <div><span class="section-kicker">RESTRICTED INDEX</span><strong>{{ number_format($totalPeople) }} PROFILE{{ $totalPeople === 1 ? '' : 'S' }}</strong></div>
        <form method="GET" action="{{ route('people.index') }}" class="records-search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search name, ID, state or citizenship" aria-label="Search people">
        </form>
    </section>
    @if (session('status'))<div class="settings-alert" role="status"><span class="alert-check">✓</span>{{ session('status') }}</div>@endif
    @if ($people->isEmpty())
        <section class="records-empty"><span class="archive-preview-mark">USR</span><h2>No person records found</h2><p>Create a profile to begin organizing person records and associated cases.</p><a href="{{ route('people.create') }}" class="button-primary">CREATE PERSON PROFILE</a></section>
    @else
        <section class="person-card-grid" aria-label="Person records">
            @foreach ($people as $person)
                <a class="person-index-card" href="{{ route('people.show', $person) }}">
                    <div class="person-index-portrait">
                        <span>{{ strtoupper(substr($person->first_name !== 'Unknown' ? $person->first_name : $person->name, 0, 1)) }}</span>
                        <i class="flag-indicator flag-{{ strtolower($person->flag) }}"></i>
                    </div>
                    <div class="person-index-copy">
                        <span class="record-id">{{ $person->public_id }}</span>
                        <h2>{{ $person->name }}</h2>
                        <p>{{ $person->gender }} <span>·</span> {{ $person->age === 'Unknown' ? 'Age unknown' : $person->age }}</p>
                    </div>
                    <div class="person-index-meta"><span>{{ $person->cases_count }} LINKED CASE{{ $person->cases_count === 1 ? '' : 'S' }}</span><span>{{ $person->images_count }} GALLERY IMAGE{{ $person->images_count === 1 ? '' : 'S' }}</span></div>
                    <span class="person-card-arrow" aria-hidden="true">↗</span>
                </a>
            @endforeach
        </section>
        <div class="records-pagination">{{ $people->links() }}</div>
    @endif
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · RESTRICTED RECORDS</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
