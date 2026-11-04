@extends('layouts.app')

@section('page_title', 'Linked people · '.$caseFile->public_id.' — '.config('app.name'))
@section('page_label', 'CASE RELATIONSHIPS')

@section('content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> CASE FILE <span class="eyebrow-index">{{ $caseFile->public_id }}</span></div>
            <h1>Linked people <span>directory</span></h1>
            <p class="page-description">{{ $people->total() }} linked profile{{ $people->total() === 1 ? '' : 's' }} · {{ $caseFile->title }}</p>
        </div>
        <a class="button-secondary" href="{{ route('cases.show', $caseFile) }}" wire:navigate>← BACK TO CASE FILE</a>
    </section>

    <section class="case-linked-directory">
        <form method="GET" action="{{ route('cases.people', $caseFile) }}" class="case-linked-search">
            <i class="ri ri-search-line" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $search }}" placeholder="Search linked people by name or profile ID" aria-label="Search linked people">
            @if ($search !== '')
                <a href="{{ route('cases.people', $caseFile) }}" wire:navigate aria-label="Clear search"><i class="ri ri-close-line" aria-hidden="true"></i></a>
            @endif
            <button type="submit">SEARCH</button>
        </form>

        @if ($people->isNotEmpty())
            <div class="case-linked-people-grid case-linked-people-grid--directory">
                @foreach ($people as $person)
                    @include('cases._linked-person-card', [
                        'person' => $person,
                        'personMediaToken' => $personMediaTokens->get($person->getKey()),
                    ])
                @endforeach
            </div>
            <div class="records-pagination">{{ $people->links() }}</div>
        @else
            <div class="records-empty">
                <span class="archive-preview-mark">USR</span>
                <h2>No linked profiles found</h2>
                <p>{{ $search !== '' ? 'Try another name or profile ID.' : 'No person profiles are currently linked to this case file.' }}</p>
            </div>
        @endif
    </section>
@endsection
