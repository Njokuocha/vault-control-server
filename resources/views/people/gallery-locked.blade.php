@extends($profileManagement ? 'layouts.department' : 'layouts.app')

@section('page_title', 'Gallery unavailable — ' . config('app.name'))
@unless($profileManagement)
    @section('page_label', 'PROFILE GALLERY')
@endunless

@section($profileManagement ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PRIVATE PROFILE GALLERY</div>
            <h1>Gallery <span>unavailable</span></h1>
            <p class="page-description">This gallery link is missing a valid access token. Return to the profile and unlock viewables to continue.</p>
        </div>
        <div class="profile-heading-actions">
            <a class="button-secondary" href="{{ route($routePrefix.'.show', ['person' => $person, 'auth' => 'unauthed']) }}">BACK TO PROFILE</a>
        </div>
    </section>
    <section class="gallery-category-restricted" role="status">
        <i class="ri ri-lock-line" aria-hidden="true"></i>
        <strong>Protected gallery</strong>
        <span>Access is unavailable or has expired. Unlock this profile to view its gallery.</span>
    </section>
@endsection
