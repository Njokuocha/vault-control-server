<section class="settings-card manual-upload-card" aria-labelledby="manual-upload-title" data-manual-upload-component>
    <div class="settings-card-head"><span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span><div><div class="section-kicker">ADMINISTRATOR INGESTION</div><h2 id="manual-upload-title">Add files to the vault</h2></div><span class="settings-card-index">01</span></div>
    <p class="settings-description">Files are stored privately using generated names. Original names are retained only as display metadata. The current maximum size is <strong>{{ number_format($maxUploadMb) }} MB per file</strong>.</p>
    @if ($maxUploadMb === 0)
        <div class="upload-disabled-notice"><span>!</span> Uploads are disabled. Change the maximum upload size in Settings to enable manual and API uploads.</div>
    @else
        <form class="manual-upload-form" method="POST" action="{{ route('uploads.create') }}" enctype="multipart/form-data" data-manual-upload data-max-upload-mb="{{ $maxUploadMb }}" data-max-video-upload-mb="{{ $maxVideoUploadMb }}" wire:ignore>
            @csrf
            <label class="upload-dropzone" data-upload-dropzone>
                <input type="file" name="files[]" multiple required data-upload-input>
                <span class="upload-drop-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span>
                <strong>Drop files here or <span>browse</span></strong>
                <span>Select any file type. Multiple files transfer independently in the background.</span>
                <code>MAX {{ number_format($maxUploadMb) }} MB PER FILE</code>
            </label>
            <p class="field-hint">Video limit: {{ $maxVideoUploadMb > 0 ? number_format(min($maxVideoUploadMb, $maxUploadMb)) . ' MB per video' : number_format($maxUploadMb) . ' MB per video (global limit)' }}. File content and size are verified server-side.</p>
            <div class="manual-upload-footer"><span class="field-hint">Files remain private and are never written to the public web directory.</span><button class="button-primary" type="submit" data-upload-submit disabled>UPLOAD SELECTED FILES</button></div>
        </form>
        <noscript><div class="upload-disabled-notice"><span>!</span> Enable JavaScript to upload files with resumable background transfers.</div></noscript>
        <div class="upload-selection" data-upload-selection aria-live="polite">
            {{ $selectedCount > 0 ? $selectedCount . ' file' . ($selectedCount === 1 ? '' : 's') . ' selected.' : 'No files selected.' }}
        </div>
    @endif
</section>
