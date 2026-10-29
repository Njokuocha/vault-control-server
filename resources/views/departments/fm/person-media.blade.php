@extends('layouts.department')

@section('page_title', 'Protected media — '.config('app.name'))
@section('department_content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PROTECTED MEDIA COLLECTION <span class="eyebrow-index">RESTRICTED</span></div>
            <h1>Private <span>viewables</span></h1>
            <p class="page-description">Only the images and videos assigned to this collection are available here. Profile and case details are not shown.</p>
        </div>
        <div class="profile-heading-actions">
            <button class="button-secondary" type="button" data-fm-open-pin>UNLOCK VIEWABLES</button>
            <a class="button-secondary" href="{{ route('fm.person-media.index') }}" wire:navigate>ALL COLLECTIONS</a>
        </div>
    </section>
    <section class="profile-gallery-block" aria-label="Protected images and videos" data-fm-media-page>
        <div class="profile-section-heading"><div><span class="section-kicker">PRIVATE MEDIA RECORD</span><h2>Viewables <span>{{ $person->images->count() + (int) (bool) ($person->main_image_path || $person->main_image_file_id) }}</span></h2></div></div>
        <div class="profile-gallery is-locked" data-profile-gallery>
            @if ($person->main_image_path || $person->main_image_file_id)
                <button class="profile-gallery-tile" type="button" data-fm-media-preview data-media-type="image" data-media-url="{{ route('fm.person-media.image', ['person' => $person->id, 'image' => 'main']) }}" data-download-url="{{ route('fm.person-media.download', ['person' => $person->id, 'image' => 'main']) }}" data-media-title="Profile image" aria-label="Locked image. Unlock viewables to preview.">
                    <span class="image-lock-placeholder" data-fm-lock-placeholder>?</span>
                    <img data-fm-protected-image data-image-url="{{ route('fm.person-media.image', ['person' => $person->id, 'image' => 'main']) }}" alt="Protected image" loading="lazy" hidden>
                </button>
            @endif
            @foreach ($person->images as $image)
                @if ($image->type === 'video')
                    <button class="profile-gallery-tile video-gallery-tile" type="button" data-fm-media-preview data-media-type="video" data-media-url="{{ route('fm.person-media.image', ['person' => $person->id, 'image' => $image->id]) }}" data-download-url="{{ route('fm.person-media.download', ['person' => $person->id, 'image' => $image->id]) }}" data-media-title="Protected video" aria-label="Locked video. Unlock viewables to preview.">
                        <span class="image-lock-placeholder" data-fm-lock-placeholder>?</span>
                        @if ($image->poster_path)<img data-fm-protected-image data-image-url="{{ route('fm.person-media.poster', ['person' => $person->id, 'image' => $image->id]) }}" alt="" loading="lazy" hidden>@endif
                        <span class="video-play-overlay" aria-hidden="true"><i class="ri ri-play-fill"></i></span>
                    </button>
                @else
                    <button class="profile-gallery-tile" type="button" data-fm-media-preview data-media-type="image" data-media-url="{{ route('fm.person-media.image', ['person' => $person->id, 'image' => $image->id]) }}" data-download-url="{{ route('fm.person-media.download', ['person' => $person->id, 'image' => $image->id]) }}" data-media-title="Protected image" aria-label="Locked image. Unlock viewables to preview.">
                        <span class="image-lock-placeholder" data-fm-lock-placeholder>?</span>
                        <img data-fm-protected-image data-image-url="{{ route('fm.person-media.image', ['person' => $person->id, 'image' => $image->id]) }}" alt="Protected image" loading="lazy" hidden>
                    </button>
                @endif
            @endforeach
        </div>
    </section>
    <dialog class="image-pin-dialog" data-fm-pin-dialog data-unlock-url="{{ route('fm.person-media.unlock', $person->id) }}" data-pin-configured="{{ $imagePinConfigured ? 'true' : 'false' }}">
        <form method="dialog" class="image-pin-close-form"><button value="close" aria-label="Close PIN window">×</button></form>
        <div class="image-pin-content">
            <span class="pin-security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 19 6v5.5c0 4.4-2.8 7.4-7 9.5-4.2-2.1-7-5.1-7-9.5V6z"/><rect x="9" y="10" width="6" height="5" rx="1"/><path d="M10.5 10V8.5a1.5 1.5 0 0 1 3 0V10"/></svg></span>
            <span class="section-kicker">RESTRICTED IMAGE ACCESS</span>
            <h2>Unlock viewables</h2>
            <p>Enter the four-digit image PIN to access this collection’s protected images and videos.</p>
            @if ($imagePinConfigured)
                <form method="post" action="{{ route('fm.person-media.unlock', $person->id) }}" data-fm-pin-form>
                    @csrf
                    <div class="pin-digit-row" data-pin-digit-row>
                        @for ($digit = 0; $digit < 4; $digit++)
                            <input type="password" inputmode="numeric" pattern="[0-9]" maxlength="1" autocomplete="{{ $digit === 0 ? 'one-time-code' : 'off' }}" aria-label="PIN digit {{ $digit + 1 }}" data-fm-pin-digit>
                        @endfor
                    </div>
                    <p class="pin-error" data-fm-pin-error role="alert" aria-live="polite"></p>
                    <button type="submit" class="button-primary pin-submit" data-fm-pin-submit>AUTHENTICATE PIN</button>
                </form>
            @else
                <div class="pin-setup-required">An administrator must configure the image PIN before viewables can be unlocked.</div>
            @endif
        </div>
    </dialog>
    <dialog class="image-viewer-dialog" data-fm-media-preview-dialog>
        <header class="image-viewer-header">
            <div class="image-viewer-heading"><span class="section-kicker">PRIVATE MEDIA VIEWER</span><h2 data-fm-preview-title>Preview</h2></div>
            <div class="image-viewer-tools"><a class="image-viewer-download" data-fm-preview-download download aria-label="Download file" title="Download file">↓</a><button type="button" data-fm-preview-close aria-label="Close media viewer">×</button></div>
        </header>
        <div class="image-viewer-stage" data-fm-preview-stage><video data-fm-preview-video controls playsinline hidden></video></div>
    </dialog>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · PROTECTED MEDIA</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
