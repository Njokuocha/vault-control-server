@php($tone = match ($caseFile->classification) { 'HIGHLY SENSITIVE' => 'sensitive', 'ACCESSIBLE' => 'accessible', default => 'classified' })
@php($cfmWorkspace = auth('web')->user()?->department === 'cfm')
@php($routePrefix = $cfmWorkspace ? 'cfm.' : '')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> CASE FILE <span class="eyebrow-index">{{ $caseFile->public_id }}</span></div><h1>{{ $caseFile->title }} <span>dossier</span></h1><p class="page-description">Opened {{ $caseFile->created_at->format('M j, Y') }} · Updated {{ $caseFile->updated_at->format('M j, Y H:i') }} · Created by {{ $caseFile->creator?->name ?? 'historical record (creator not recorded)' }}</p></div>
        <div class="profile-heading-actions"><a class="button-secondary" href="{{ route($routePrefix.'cases.index') }}" wire:navigate>ALL CASES</a><a class="button-primary" href="{{ route($routePrefix.'cases.edit', $caseFile) }}" wire:navigate>EDIT CASE FILE</a>
            @unless ($cfmWorkspace)
                <form method="POST" action="{{ route('cases.destroy', $caseFile) }}" x-data x-ref="deleteCaseForm">
                    @csrf
                    @method('DELETE')
                    <button class="button-danger" type="button" @click.prevent="window.vaultConfirm({ title: 'Delete case file', message: 'Permanently delete case file {{ $caseFile->public_id }} and its supporting material?', destructive: true }).then(confirmed => confirmed && $refs.deleteCaseForm.submit())">DELETE CASE FILE</button>
                </form>
            @endunless
        </div>
    </section>
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
    <section class="case-linked-reports">
        <div class="profile-section-heading"><div><span class="section-kicker">CONNECTED INTELLIGENCE</span><h2>Linked reports <span>{{ $linkedReports->count() }}</span></h2></div><a class="text-action" href="{{ route($cfmWorkspace ? 'cfm.reports.index' : 'admin.reports.index') }}" wire:navigate>REPORT SYSTEM ↗</a></div>
        @forelse ($linkedReports as $linkedReport)
            <a class="case-linked-report-row" href="{{ route($cfmWorkspace ? 'cfm.reports.index' : 'admin.reports.index', ['report' => $linkedReport->uuid]) }}" wire:navigate>
                <span class="case-linked-report-node" aria-hidden="true"></span>
                <span><strong>{{ \Illuminate\Support\Str::limit($linkedReport->body, 140) }}</strong><small>{{ $linkedReport->created_at?->format('M d, Y · H:i') }} UTC · {{ $linkedReport->comments_count }} comments</small></span><i class="ri ri-arrow-right-up-line" aria-hidden="true"></i>
            </a>
        @empty
            <p class="profile-gallery-empty">No reports are linked to this case file.</p>
        @endforelse
    </section>
    @unless ($cfmWorkspace)
    <section class="case-linked-people">
        <div class="profile-section-heading"><div><span class="section-kicker">CONNECTED RECORDS</span><h2>Linked people <span>{{ $caseFile->people->count() }}</span></h2></div><a href="{{ route('people.index') }}" wire:navigate class="text-action">PERSON INDEX ↗</a></div>
        @if ($caseFile->people->isNotEmpty())
            <div class="linked-person-list">
                @foreach ($caseFile->people as $person)
                    <a href="{{ route('people.show', $person) }}" wire:navigate class="linked-case-row"><span class="case-link-mark">USR</span><span><strong>{{ $person->public_id }}</strong><small>{{ $person->name }} · {{ $person->flag }}</small></span><span class="case-classification-chip">{{ $person->gender }}</span><span aria-hidden="true">↗</span></a>
                @endforeach
            </div>
        @else
            <p class="profile-gallery-empty">No person profiles are linked to this case.</p>
        @endif
    </section>
    @endunless
    <section class="case-evidence-section">
        <div class="profile-section-heading"><div><span class="section-kicker">SUPPORTING MATERIAL</span><h2>Evidence categories <span>{{ $caseFile->categories->count() }}</span></h2></div></div>
        @forelse ($caseFile->categories as $category)
            <article class="case-evidence-category">
                <header><div><span class="section-kicker">{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }} / EVIDENCE CATEGORY</span><h3>{{ $category->title }}</h3></div><span>{{ $category->images->count() }} FILE{{ $category->images->count() === 1 ? '' : 'S' }}</span></header>
                <p>{{ $category->description }}</p>
                @if ($category->images->isNotEmpty())
                    <div class="case-evidence-gallery" data-expandable-gallery>
                        @foreach ($category->images as $image)
                            @if ($image->type === 'video')
                                <button class="evidence-gallery-tile video-gallery-tile" type="button" data-video-viewer data-video-url="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" data-video-download-url="{{ route($routePrefix.'cases.images.download', ['caseFile' => $caseFile, 'image' => $image]) }}" data-video-title="{{ $image->original_name }}" aria-label="Play {{ $image->original_name }}" @if ($loop->index >= 4) hidden data-gallery-extra @endif>
                                    @if ($image->poster_path || $image->file?->video_poster_path)
                                        <img src="{{ route($routePrefix.'cases.images.poster', ['caseFile' => $caseFile, 'image' => $image]) }}" alt="" loading="lazy" decoding="async" onerror="this.remove()">
                                    @else
                                        <video class="case-gallery-video-thumbnail" data-case-video-thumbnail data-thumbnail-url="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" muted playsinline preload="none" hidden aria-hidden="true"></video>
                                    @endif
                                    <span class="video-play-overlay" aria-hidden="true"><i class="ri ri-play-fill"></i></span>
                                    @if ($loop->index === 3 && $category->images->count() > 4)<span class="gallery-more-overlay" data-expand-gallery>+{{ $category->images->count() - 4 }}</span>@endif
                                </button>
                            @elseif (in_array($image->type, ['document', 'audio'], true))
                                @php($extension = strtoupper(pathinfo($image->original_name, PATHINFO_EXTENSION)) ?: 'FILE')
                                @php($documentIcon = match (strtolower($extension)) {
                                    'pdf' => 'ri-file-pdf-2-line',
                                    'doc', 'docx' => 'ri-file-word-2-line',
                                    'txt', 'rtf' => 'ri-file-text-line',
                                    'mp3', 'wav', 'm4a', 'ogg' => 'ri-music-2-line',
                                    default => 'ri-file-text-line',
                                })
                                <a class="evidence-gallery-tile evidence-gallery-document" href="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" target="_blank" rel="noopener" aria-label="View {{ $image->original_name }}" @if ($loop->index >= 4) hidden data-gallery-extra @endif>
                                    <i class="ri {{ $documentIcon }} evidence-gallery-document__watermark" aria-hidden="true"></i>
                                    <strong>{{ $extension }}</strong>
                                    <span title="{{ $image->original_name }}">{{ \Illuminate\Support\Str::limit(pathinfo($image->original_name, PATHINFO_FILENAME), 22, '...') }}</span>
                                    @if ($loop->index === 3 && $category->images->count() > 4)<span class="gallery-more-overlay" data-expand-gallery>+{{ $category->images->count() - 4 }}</span>@endif
                                </a>
                            @else
                                <button class="evidence-gallery-tile" type="button" data-image-viewer data-image-url="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" data-download-url="{{ route($routePrefix.'cases.images.download', ['caseFile' => $caseFile, 'image' => $image]) }}" data-image-title="{{ $image->original_name }}" aria-label="View {{ $image->original_name }}" @if ($loop->index >= 4) hidden data-gallery-extra @endif>
                                    <img src="{{ route($routePrefix.'cases.image', ['caseFile' => $caseFile, 'image' => $image]) }}" alt="{{ $image->original_name }}" loading="lazy" decoding="async">
                                    @if ($loop->index === 3 && $category->images->count() > 4)<span class="gallery-more-overlay" data-expand-gallery>+{{ $category->images->count() - 4 }}</span>@endif
                                </button>
                            @endif
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
    <x-video-viewer />
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · {{ $caseFile->public_id }}</span><span>RESTRICTED <i>·</i> AUTHENTICATED ACCESS</span></footer>
