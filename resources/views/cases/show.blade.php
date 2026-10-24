@extends('layouts.app')

@php($tone = match ($caseFile->classification) { 'HIGHLY SENSITIVE' => 'sensitive', 'ACCESSIBLE' => 'accessible', default => 'classified' })
@section('page_title', $caseFile->public_id . ' — ' . config('app.name'))
@section('page_label', 'CASE DOSSIER')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> CASE FILE <span class="eyebrow-index">{{ $caseFile->public_id }}</span></div><h1>{{ $caseFile->title }} <span>dossier</span></h1><p class="page-description">Opened {{ $caseFile->created_at->format('M j, Y') }} · Updated {{ $caseFile->updated_at->format('M j, Y H:i') }}</p></div>
        <div class="profile-heading-actions"><a class="button-secondary" href="{{ route('cases.index') }}">ALL CASES</a><a class="button-primary" href="{{ route('cases.edit', $caseFile) }}">EDIT CASE FILE</a></div>
    </section>
    @if (session('status'))<div class="settings-alert" role="status"><span class="alert-check">✓</span>{{ session('status') }}</div>@endif
    <section class="case-dossier-banner tone-{{ $tone }}">
        <div class="case-dossier-id"><span>CASE FILE NUMBER</span><strong>{{ $caseFile->public_id }}</strong></div>
        <div class="case-dossier-classification"><span>ACCESS CLASSIFICATION</span><strong>{{ $caseFile->classification }}</strong></div>
        <div class="case-dossier-state"><span>CASE STATUS</span><strong>{{ $caseFile->case_status }}</strong></div>
        <span class="case-dossier-line"><i></i></span>
    </section>
    <section class="case-narrative-card">
        <div class="profile-section-heading"><div><span class="section-kicker">CASE ABSTRACT</span><h2>Investigation narrative</h2></div><span class="case-classification-chip {{ 'legend-'.$tone }}">{{ $caseFile->classification }}</span></div>
        <div class="case-narrative-text">{{ $caseFile->narrative }}</div>
        <div class="case-comment-text"><span>COMMENTS / CONTINUITY NOTES</span><p>{{ $caseFile->comment }}</p></div>
    </section>
    <section class="case-linked-people">
        <div class="profile-section-heading"><div><span class="section-kicker">CONNECTED RECORDS</span><h2>Linked people <span>{{ $caseFile->people->count() }}</span></h2></div><a href="{{ route('people.index') }}" class="text-action">PERSON INDEX ↗</a></div>
        @if ($caseFile->people->isNotEmpty())
            <div class="linked-person-list">
                @foreach ($caseFile->people as $person)
                    <a href="{{ route('people.show', $person) }}" class="linked-case-row"><span class="case-link-mark">USR</span><span><strong>{{ $person->public_id }}</strong><small>{{ $person->name }} · {{ $person->flag }}</small></span><span class="case-classification-chip">{{ $person->gender }}</span><span aria-hidden="true">↗</span></a>
                @endforeach
            </div>
        @else
            <p class="profile-gallery-empty">No person profiles are linked to this case.</p>
        @endif
    </section>
    <section class="case-evidence-section">
        <div class="profile-section-heading"><div><span class="section-kicker">SUPPORTING MATERIAL</span><h2>Image categories <span>{{ $caseFile->categories->count() }}</span></h2></div></div>
        @forelse ($caseFile->categories as $category)
            <article class="case-evidence-category">
                <header><div><span class="section-kicker">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }} / EVIDENCE CATEGORY</span><h3>{{ $category->title }}</h3></div><span>{{ $category->images->count() }} IMAGE{{ $category->images->count() === 1 ? '' : 'S' }}</span></header>
                <p>{{ $category->description }}</p>
                @if ($category->images->isNotEmpty())
                    <div class="case-evidence-gallery" data-expandable-gallery>
                        @foreach ($category->images as $image)
                            <button class="evidence-gallery-tile" type="button" data-image-viewer data-image-url="{{ route('cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" data-download-url="{{ route('cases.images.download', ['caseFile' => $caseFile, 'image' => $image]) }}" data-image-title="{{ $image->original_name }}" aria-label="View {{ $image->original_name }}" @if ($loop->index >= 4) hidden data-gallery-extra @endif>
                                <img src="{{ route('cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" alt="{{ $image->original_name }}">
                                @if ($loop->index === 3 && $category->images->count() > 4)<span class="gallery-more-overlay" data-expand-gallery>+{{ $category->images->count() - 4 }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                @else
                    <div class="profile-gallery-empty">No images in this category.</div>
                @endif
            </article>
        @empty
            <div class="profile-gallery-empty">No image categories have been added.</div>
        @endforelse
    </section>
    <dialog class="image-viewer-dialog" data-image-viewer-dialog>
        <header class="image-viewer-header">
            <div class="image-viewer-heading"><span class="section-kicker">PRIVATE IMAGE VIEWER</span><h2 data-image-viewer-title>Image preview</h2></div>
            <div class="image-viewer-tools">
                <div class="image-zoom-controls" aria-label="Image zoom controls">
                    <button type="button" data-image-zoom-out aria-label="Zoom out" title="Zoom out">−</button>
                    <span data-image-zoom-level>100%</span>
                    <button type="button" data-image-zoom-in aria-label="Zoom in" title="Zoom in">+</button>
                </div>
                <label class="image-grayscale-toggle"><input type="checkbox" data-image-grayscale><span class="image-grayscale-switch" aria-hidden="true"></span><span>GRAYSCALE</span></label>
                <a class="image-viewer-download" data-image-viewer-download download aria-label="Download image" title="Download image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11m-4-4 4 4 4-4"/><path d="M5 15v4a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4"/></svg></a>
                <button type="button" data-close-image-viewer aria-label="Close image viewer">×</button>
            </div>
        </header>
        <div class="image-viewer-stage" data-image-viewer-stage><img data-image-viewer-image alt="" draggable="false"></div>
    </dialog>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · {{ $caseFile->public_id }}</span><span>RESTRICTED <i>·</i> AUTHENTICATED ACCESS</span></footer>
@endsection
