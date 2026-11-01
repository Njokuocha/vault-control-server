@php($staffManagement = str_starts_with($routePrefix, 'sm.'))
@extends($staffManagement ? 'layouts.department' : 'layouts.app')

@section('page_title', 'Upload Gallery Media — '.config('app.name'))
@unless($staffManagement)
    @section('page_label', 'STAFF GALLERY UPLOAD')
@endunless

@section($staffManagement ? 'department_content' : 'content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> STAFF MEDIA INTAKE <span class="eyebrow-index">{{ $staff->id }}</span></div>
            <h1>Upload <span>gallery media</span></h1>
            <p class="page-description">Images and videos transfer in the background and are attached to {{ $staff->name }}'s gallery.</p>
        </div>
        <div class="profile-heading-actions">
            <a class="button-secondary" href="{{ route($routePrefix.'.show', ['user' => $staff, 'auth' => $authToken]) }}" wire:navigate>BACK TO STAFF PROFILE</a>
            <a class="button-primary" href="{{ route($galleryRoutePrefix.'.index', ['user' => $staff, 'auth' => $authToken]) }}" wire:navigate>OPEN GALLERY</a>
        </div>
    </section>

    @if ($maxUploadMb === 0)
        <div class="upload-disabled-notice"><span>!</span> Uploads are disabled. An administrator must increase the maximum upload size in Settings.</div>
    @else
        <form class="record-form-shell staff-gallery-upload-form" method="POST" action="{{ route($galleryRoutePrefix.'.index', ['user' => $staff, 'auth' => $authToken]) }}" enctype="multipart/form-data" data-video-limit-config data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}" data-record-upload-form data-record-id="{{ $staff->id }}" data-commit-record-uploads="true" data-upload-on-demand="true">
            @csrf
            <section class="record-form-visual">
                <div class="form-section-heading">
                    <span class="section-kicker">01 / PRIVATE STAFF MEDIA</span>
                    <h2>Select images and videos</h2>
                    <p>Each selected file starts an independent background transfer. Assign categories later from the gallery.</p>
                </div>
                <div class="person-gallery-upload-list" data-record-gallery-upload-list>
                    <label class="upload-dropzone staff-gallery-upload-dropzone" data-upload-dropzone>
                        <input class="visually-hidden-file" type="file" multiple accept="{{ $acceptAttribute }}" data-record-gallery-input data-record-purpose="staff-gallery">
                        <span class="upload-drop-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span>
                        <strong>Choose images or videos</strong>
                        <span>Choose or drop supported images and videos to preview them before upload.</span>
                        <code>MAX {{ number_format($maxUploadMb) }} MB PER FILE</code>
                    </label>
                    <p class="field-hint">Video limit: {{ $maxVideoUploadMb > 0 ? number_format(min($maxVideoUploadMb, $maxUploadMb)) : number_format($maxUploadMb) }} MB per video. Files are stored privately; category assignment happens in the gallery.</p>
                </div>
                <div class="record-form-footer staff-gallery-upload-actions">
                    <span>
                        <b data-staff-gallery-upload-status role="status" aria-live="polite">SELECT FILES TO BEGIN</b>
                        <small>Uploads continue in the background and can be tracked from the upload panel.</small>
                    </span>
                    <button class="button-primary" type="submit" data-staff-gallery-upload-submit disabled>UPLOAD SELECTED FILES</button>
                </div>
            </section>
        </form>
    @endif
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }} · STAFF MEDIA INTAKE</span><span>PRIVATE BY DESIGN <i>·</i> BACKGROUND TRANSFERS</span></footer>
@endsection
