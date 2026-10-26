@extends('layouts.app')

@section('page_title', 'Manual upload — ' . config('app.name'))
@section('page_label', 'MANUAL UPLOAD')

@section('content')
    <section class="page-heading">
        <div>
            <div class="eyebrow"><span class="eyebrow-line"></span> PRIVATE INGESTION <span class="eyebrow-index">02 / 04</span></div>
            <h1>Manual <span>upload</span></h1>
            <p class="page-description">Securely add one or more files to your private archive.</p>
        </div>
        <a class="back-link" href="{{ route('dashboard.index') }}" wire:navigate><span aria-hidden="true">←</span> RETURN TO ARCHIVE</a>
    </section>

    <section class="settings-card manual-upload-card" aria-labelledby="manual-upload-title">
        <div class="settings-card-head"><span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span><div><div class="section-kicker">ADMINISTRATOR INGESTION</div><h2 id="manual-upload-title">Add files to the vault</h2></div><span class="settings-card-index">01</span></div>
        <p class="settings-description">Files are stored privately using generated names. Original names are retained only as display metadata. The current maximum size is <strong>{{ number_format($maxUploadMb) }} MB per file</strong>.</p>
        @if ($maxUploadMb === 0)
            <div class="upload-disabled-notice"><span>!</span> Uploads are disabled. Change the maximum upload size in Settings to enable manual and API uploads.</div>
        @else
            <form class="manual-upload-form" method="POST" action="{{ route('uploads.store') }}" enctype="multipart/form-data" data-manual-upload data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}" data-php-upload-max-bytes="{{ $phpUploadMaxBytes }}" data-php-post-max-bytes="{{ $phpPostMaxBytes }}">
                @csrf
                <label class="upload-dropzone" data-upload-dropzone>
                    <input type="file" name="files[]" multiple required data-upload-input>
                    <span class="upload-drop-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span>
                    <strong>Drop files here or <span>browse</span></strong>
                    <span>Select up to 20 non-video files. Use the background uploader for videos.</span>
                    <code>MAX {{ number_format($maxUploadMb) }} MB PER FILE</code>
                </label>
                <div class="upload-selection" data-upload-selection aria-live="polite">No files selected.</div>
                <p class="field-hint">Video limit: {{ $maxVideoUploadMb > 0 ? number_format(min($maxVideoUploadMb, $maxUploadMb)) . ' MB per video' : number_format($maxUploadMb) . ' MB per video (global limit)' }}. The server verifies file content before accepting an upload.</p>
                <div class="manual-upload-footer"><span class="field-hint">Files remain private and are never written to the public web directory.</span><button class="button-primary" type="submit" data-upload-submit disabled>UPLOAD SELECTED FILES</button></div>
            </form>
            <div class="video-background-upload" data-video-background-upload data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}">
                <label class="record-field">
                    <span>BACKGROUND VIDEO UPLOAD</span>
                    <input type="file" multiple data-video-upload-input>
                </label>
                <p class="field-hint">Choose video files in any format. Transfers use private chunks; stay on the app or dismiss the browser's leave-page warning while a transfer is active. Content type and configured limits are checked again on the server.</p>
            </div>
        @endif
    </section>
    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }}</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection