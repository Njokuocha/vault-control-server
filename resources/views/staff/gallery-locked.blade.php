@php($staffManagement = str_starts_with($routePrefix, 'sm.'))
@extends($staffManagement ? 'layouts.department' : 'layouts.app')

@section('page_title', 'Gallery unavailable — '.config('app.name'))
@unless($staffManagement)
    @section('page_label', 'STAFF GALLERY')
@endunless

@section($staffManagement ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> RESTRICTED STAFF GALLERY</div>
            <h1>Gallery <span>locked</span></h1>
            <p class="page-description">This staff gallery requires a valid access token. Authenticate with the gallery PIN to continue.</p>
        </div>
        <div class="profile-heading-actions">
            <a class="button-secondary" href="{{ route($routePrefix.'.show', $staff) }}" wire:navigate>BACK TO STAFF PROFILE</a>
        </div>
    </section>

    <section class="gallery-category-restricted staff-gallery-restricted" role="status">
        <i class="ri ri-lock-line" aria-hidden="true"></i>
        <strong>Protected gallery</strong>
        <span>This gallery is unavailable because its access token is missing, invalid, or expired.</span>
        @if ($pinConfigured)
            <button class="button-primary" type="button" data-open-image-pin>UNLOCK WITH PIN</button>
        @else
            <span>An administrator must configure the gallery PIN before protected media can be viewed.</span>
        @endif
    </section>

    <dialog class="image-pin-dialog" data-image-pin-dialog data-unlock-url="{{ route($galleryRoutePrefix.'.unlock', $staff) }}" data-pin-configured="{{ $pinConfigured ? 'true' : 'false' }}">
        <form method="dialog" class="image-pin-close-form"><button value="close" aria-label="Close PIN window">×</button></form>
        <div class="image-pin-content">
            <span class="pin-security-emblem" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3 19 6v5.5c0 4.4-2.8 7.4-7 9.5-4.2-2.1-7-5.1-7-9.5V6z"/><rect x="9" y="10" width="6" height="5" rx="1"/><path d="M10.5 10V8.5a1.5 1.5 0 0 1 3 0V10"/></svg></span>
            <span class="section-kicker">RESTRICTED GALLERY ACCESS</span>
            <h2>Unlock staff gallery</h2>
            <p>Enter the four-digit gallery PIN to view this staff member's private media.</p>
            @if ($pinConfigured)
                <form method="post" action="{{ route($galleryRoutePrefix.'.unlock', $staff) }}" data-image-pin-form>
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
                <div class="pin-setup-required">An administrator must configure the gallery PIN before staff media can be viewed.</div>
            @endif
        </div>
    </dialog>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · STAFF GALLERY</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection
