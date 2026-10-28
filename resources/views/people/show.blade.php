@extends($profileManagement ? 'layouts.department' : 'layouts.app')

@section('page_title', $person->name . ' — ' . config('app.name'))
@unless($profileManagement)
    @section('page_label', 'PERSON PROFILE')
@endunless

@section($profileManagement ? 'department_content' : 'content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> SUBJECT DOSSIER <span class="eyebrow-index">{{ $person->public_id }}</span></div><h1>{{ $person->name }} <span>profile</span></h1><p class="page-description">Private subject record · last updated {{ $person->updated_at->format('M j, Y H:i') }}</p></div>
        <div class="profile-heading-actions"><button class="button-secondary" type="button" data-open-image-pin>UNLOCK VIEWABLES</button><a class="button-secondary" href="{{ route($routePrefix.'.index') }}" wire:navigate>ALL PROFILES</a><a class="button-primary" href="{{ route($routePrefix.'.edit', $person) }}" wire:navigate>EDIT PROFILE</a></div>
    </section>
    <section class="person-profile-hero">
        <div class="profile-visual-panel">
            <div class="profile-visual-top"><span>IDENTITY VISUAL</span><span>{{ $person->public_id }}</span></div>
            <div class="profile-avatar-stage">
                @if ($avatarModelUrl)
                    <model-viewer src="{{ $avatarModelUrl }}" alt="Rotating {{ strtolower($person->avatar_group) }} avatar" auto-rotate auto-rotate-delay="0" rotation-per-second="16deg" interaction-prompt="none" disable-tap shadow-intensity="0.6" exposure="0.9" data-profile-model></model-viewer>
                @else
                    <div class="profile-unknown-avatar" data-profile-model aria-label="{{ $person->avatar_group }} avatar model unavailable"><span>?</span><small>{{ strtoupper($person->avatar_group) }} MODEL NOT CONFIGURED</small></div>
                @endif
                <div class="profile-main-image" data-profile-main-image hidden>
                    @if ($person->main_image_path)
                        <img data-protected-image data-image-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => 'main']) }}" data-download-url="{{ route($routePrefix.'.images.download', ['person' => $person, 'image' => 'main']) }}" alt="{{ $person->name }} main image" loading="lazy" data-image-viewer>
                    @else
                        <div class="profile-no-image">NO IMAGE FOR THE CURRENT USER</div>
                    @endif
                </div>
            </div>
            @if ($imagePinConfigured)
                <button class="profile-visual-toggle" type="button" data-profile-visual-toggle data-has-main-image="{{ $person->main_image_path ? 'true' : 'false' }}" disabled>VIEW MAIN IMAGE <span>↗</span></button>
            @else
                @if ($profileManagement)
                    <div class="profile-visual-toggle profile-visual-setup">IMAGE ACCESS REQUIRES AN ADMINISTRATOR PIN</div>
                @else
                    <a class="profile-visual-toggle profile-visual-setup" href="{{ route('settings.index') }}" wire:navigate>CONFIGURE IMAGE PIN IN SETTINGS <span>↗</span></a>
                @endif
            @endif
            <div class="profile-model-caption"><span class="status-dot"></span> ROTATION PREVIEW <i>·</i> {{ strtoupper($person->avatar_group) }} MODEL</div>
        </div>
        <div class="profile-identity-panel">
            <div class="profile-identity-top"><span class="record-id">{{ $person->public_id }}</span><span class="person-status-badge status-{{ strtolower($person->flag) }}">{{ strtoupper($person->flag) }}</span></div>
            <h2>{{ $person->name }}</h2>
            <p class="profile-name-line">{{ $person->first_name }} {{ $person->last_name }} {{ $person->surname }}</p>
            <div class="profile-quick-facts">
                <div><span>AGE</span><strong>{{ $person->age }}</strong></div>
                <div><span>GENDER</span><strong>{{ $person->gender }}</strong></div>
                <div><span>CITIZENSHIP</span><strong>{{ $person->citizenship }}</strong></div>
                <div><span>STATE / REGION</span><strong>{{ $person->state }}</strong></div>
            </div>
            @unless($profileManagement)
            <div class="linked-cases-block">
                <div class="profile-section-heading"><div><span class="section-kicker">ASSOCIATED RECORDS</span><h3>Linked case files</h3></div><button type="button" class="button-secondary" data-open-case-link>＋ LINK CASE</button></div>
                @if ($person->cases->isNotEmpty())
                    <div class="linked-case-list">
                        @foreach ($person->cases as $case)
                            <a class="linked-case-row" href="{{ route('cases.show', $case) }}" wire:navigate><span class="case-link-mark">CSE</span><span><strong>{{ $case->public_id }}</strong><small>{{ $case->title }}</small></span><span class="case-classification-chip">{{ $case->classification }}</span><span aria-hidden="true">↗</span></a>
                        @endforeach
                    </div>
                @else
                    <div class="linked-case-empty">No case files linked to this profile.</div>
                @endif
            </div>
            @endunless
            <div class="profile-gallery-block">
                <div class="profile-section-heading"><div><span class="section-kicker">PRIVATE MEDIA RECORD</span><h3>Gallery <span>{{ $person->images->count() }}</span></h3></div><a href="{{ route($routePrefix.'.edit', $person) }}" wire:navigate class="text-action">MANAGE MEDIA</a></div>
                @if ($person->images->isNotEmpty())
                    <div class="profile-gallery is-locked" data-profile-gallery>
                        @foreach ($person->images as $image)
                            @if ($image->type === 'video')
                                <button class="profile-gallery-tile video-gallery-tile" type="button" data-video-viewer data-video-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" data-video-download-url="{{ route($routePrefix.'.images.download', ['person' => $person, 'image' => $image->id]) }}" data-video-title="{{ $image->original_name }}" data-video-pin-required aria-label="Locked video. Unlock viewables to preview." @if ($loop->index >= 4) hidden data-gallery-extra @endif>
                                    <span class="image-lock-placeholder" data-image-lock-placeholder>?</span>
                                    @if ($image->poster_path)<img data-protected-image data-image-url="{{ route($routePrefix.'.images.poster', ['person' => $person, 'image' => $image->id]) }}" alt="" loading="lazy" hidden>@endif
                                    <span class="video-play-overlay" aria-hidden="true">▶ VIDEO</span>
                                    @if ($loop->index === 3 && $person->images->count() > 4)<span class="gallery-more-overlay" data-expand-gallery>+{{ $person->images->count() - 4 }}</span>@endif
                                </button>
                            @else
                                <button class="profile-gallery-tile" type="button" data-image-viewer data-image-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" data-download-url="{{ route($routePrefix.'.images.download', ['person' => $person, 'image' => $image->id]) }}" data-image-title="{{ $image->original_name }}" aria-label="Locked image. Unlock viewables to preview." @if ($loop->index >= 4) hidden data-gallery-extra @endif>
                                    <span class="image-lock-placeholder" data-image-lock-placeholder>?</span>
                                    <img data-protected-image data-image-url="{{ route($routePrefix.'.image', ['person' => $person, 'image' => $image->id]) }}" alt="{{ $image->original_name }}" loading="lazy" hidden>
                                    @if ($loop->index === 3 && $person->images->count() > 4)<span class="gallery-more-overlay" data-expand-gallery>+{{ $person->images->count() - 4 }}</span>@endif
                                </button>
                            @endif
                        @endforeach
                    </div>
                @else
                    <div class="profile-gallery-empty">No additional images have been added.</div>
                @endif
            </div>
        </div>
    </section>
    <section class="profile-details-grid">
        <article class="profile-details-card">
            <div class="profile-section-heading"><div><span class="section-kicker">BIOGRAPHICAL DATA</span><h3>Identity details</h3></div></div>
            <dl class="profile-details-list">
                @foreach (['Date of birth' => 'date_of_birth', 'Marital status' => 'marital_status', 'Spouse' => 'spouse', 'Blood group' => 'blood_group', 'Genotype' => 'genotype', 'Height' => 'height', 'Occupation' => 'occupation', 'Aliases' => 'aliases', 'Last known location' => 'last_known_location', 'Identifying marks' => 'identifying_marks'] as $label => $field)
                    <div><dt>{{ $label }}</dt><dd>{{ $person->{$field} }}</dd></div>
                @endforeach
            </dl>
        </article>
        <article class="profile-statement-card">
            <div class="profile-section-heading"><div><span class="section-kicker">FIELD NOTES</span><h3>Comment / statement</h3></div></div>
            <div class="statement-paper">{{ $person->statement }}</div>
            <div class="statement-footnote">UNVERIFIED INFORMATION SHOULD BE ATTRIBUTED TO ITS SOURCE.</div>
        </article>
    </section>
    <dialog class="image-pin-dialog" data-image-pin-dialog data-unlock-url="{{ route($routePrefix.'.images.unlock', $person) }}" data-pin-configured="{{ $imagePinConfigured ? 'true' : 'false' }}">
        <form method="dialog" class="image-pin-close-form"><button value="close" aria-label="Close PIN window">×</button></form>
        <div class="image-pin-content">
            <span class="pin-security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 19 6v5.5c0 4.4-2.8 7.4-7 9.5-4.2-2.1-7-5.1-7-9.5V6z"/><rect x="9" y="10" width="6" height="5" rx="1"/><path d="M10.5 10V8.5a1.5 1.5 0 0 1 3 0V10"/></svg></span>
            <span class="section-kicker">RESTRICTED IMAGE ACCESS</span>
            <h2>Unlock viewables</h2>
            <p>Enter the four-digit image PIN to view this profile's main image and private gallery.</p>
            @if ($imagePinConfigured)
                <form method="post" action="{{ route($routePrefix.'.images.unlock', $person) }}" data-image-pin-form>
                    @csrf
                    <div class="pin-digit-row" data-pin-digit-row>
                        @for ($digit = 0; $digit < 4; $digit++)
                            <input type="password" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="{{ $digit === 0 ? 'one-time-code' : 'off' }}" aria-label="PIN digit {{ $digit + 1 }}" data-pin-digit>
                        @endfor
                    </div>
                    <p class="pin-error" data-pin-error role="alert" aria-live="polite"></p>
                    <button type="submit" class="button-primary pin-submit" data-submit-image-pin>AUTHENTICATE PIN</button>
                </form>
            @else
                <div class="pin-setup-required">An administrator must configure the image PIN in Settings before images can be viewed.</div>
                @unless($profileManagement)
                    <a class="button-primary pin-submit" href="{{ route('settings.index') }}">OPEN SECURITY SETTINGS</a>
                @endunless
            @endif
        </div>
    </dialog>
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
    <x-video-viewer />
    @unless($profileManagement)
    <dialog class="case-link-dialog" data-case-link-dialog>
        <form method="dialog" class="case-link-dialog-header"><div><span class="section-kicker">RELATIONSHIP INDEX</span><h2>Link a case file</h2></div><button value="close" aria-label="Close case file search">×</button></form>
        <label class="records-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg><input type="search" placeholder="Search case number or title" data-case-link-search></label>
        <div class="case-link-results" data-case-link-results data-search-url="{{ route('people.cases.search', $person) }}" data-attach-url="{{ route('people.cases.attach', $person) }}">Search for a case file to link.</div>
    </dialog>
    @endunless
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · SUBJECT DOSSIER</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
