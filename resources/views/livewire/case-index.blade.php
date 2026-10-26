<section>
    <section class="case-index-toolbar">
        <span><b>{{ number_format($cases->total()) }}</b> CASE FILES</span>
        <div><span class="case-status-legend legend-classified">CLASSIFIED {{ $classificationCounts['CLASSIFIED'] ?? 0 }}</span><span class="case-status-legend legend-sensitive">HIGHLY SENSITIVE {{ $classificationCounts['HIGHLY SENSITIVE'] ?? 0 }}</span><span class="case-status-legend legend-accessible">ACCESSIBLE {{ $classificationCounts['ACCESSIBLE'] ?? 0 }}</span></div>
    </section>
    @if ($cases->isEmpty())
        <section class="records-empty"><span class="archive-preview-mark">CSE</span><h2>No case files yet</h2><p>Create a case file and add image categories, narrative details, and linked people.</p><a href="{{ route('cases.create') }}" wire:navigate class="button-primary">NEW CASE ＋</a></section>
    @else
        <section class="case-card-grid" aria-label="Case file index">
            @foreach ($cases as $caseFile)
                @php($tone = match ($caseFile->classification) { 'HIGHLY SENSITIVE' => 'sensitive', 'ACCESSIBLE' => 'accessible', default => 'classified' })
                <a href="{{ route('cases.show', $caseFile) }}" wire:navigate class="case-index-card tone-{{ $tone }}">
                    <div class="case-index-card-head"><span>CASE FILE</span><span>{{ $caseFile->public_id }}</span></div>
                    <strong class="case-index-title">{{ $caseFile->title }}</strong>
                    <div class="case-classification-label">{{ $caseFile->classification }}</div>
                    <span class="case-index-card-foot"><i></i><span></span></span>
                    <div class="case-index-card-meta"><span>{{ $caseFile->case_status }}</span><span>{{ $caseFile->people_count }} PEOPLE · {{ $caseFile->categories_count }} CATEGORIES</span></div>
                </a>
            @endforeach
        </section>
        <div class="records-pagination">{{ $cases->links() }}</div>
    @endif
</section>
