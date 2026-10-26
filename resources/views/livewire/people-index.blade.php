<section x-data="{ term: '' }">
    <section class="records-toolbar">
        <div><span class="section-kicker">RESTRICTED INDEX</span><strong>{{ number_format($totalPeople) }} PROFILE{{ $totalPeople === 1 ? '' : 'S' }}</strong></div>
        <form method="GET" action="{{ route('people.index') }}" class="records-search" x-data>
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg>
            <input type="search" name="q" value="{{ $this->search }}" placeholder="Search name, ID, state or citizenship" aria-label="Search people" data-page-search
                wire:model.live.debounce.250ms="search" x-ref="peopleSearch"
                x-on:input="term = $event.target.value.toLocaleLowerCase()"
                x-on:keydown.escape.prevent="$wire.set('search', ''); term = ''; $refs.peopleSearch.focus()">
            <span wire:loading.delay wire:target="search" role="status">SEARCHING</span>
        </form>
    </section>
    @if ($people->isEmpty())
        <section class="records-empty"><span class="archive-preview-mark">USR</span><h2>No person records found</h2><p>{{ $this->search ? 'No profiles match the current search.' : 'Create a profile to begin organizing person records and associated cases.' }}</p>@unless($this->search)<a href="{{ route('people.create') }}" class="button-primary">CREATE PERSON PROFILE</a>@endunless</section>
    @else
        <section class="person-card-grid" aria-label="Person records">
            @foreach ($people as $person)
                <a class="person-index-card" wire:key="person-{{ $person->id }}"
                    data-search="{{ strtolower($person->public_id.' '.$person->name.' '.$person->state.' '.$person->citizenship) }}"
                    x-show="!term || $el.dataset.search.includes(term)"
                    href="{{ route('people.show', $person) }}">
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
</section>
