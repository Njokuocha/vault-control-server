<section class="settings-card upload-policy-card" aria-labelledby="upload-policy-title">
    <div class="settings-card-head">
        <span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span>
        <div><div class="section-kicker">INGESTION POLICY</div><h2 id="upload-policy-title">Upload controls</h2></div>
        <span class="settings-card-index">02</span>
    </div>
    <p class="settings-description">Set the per-file upload limit and control whether external clients may use the API endpoint. A limit of 0 MB blocks all uploads.</p>
    <form class="upload-policy-form" wire:submit="save">
        <label class="upload-limit-field" for="max-upload-mb">
            <span class="setting-field-label">MAXIMUM FILE SIZE</span>
            <span class="upload-limit-input"><input id="max-upload-mb" type="number" wire:model.number="maxUploadMb" min="0" max="1048576" step="1" required><span>MB PER FILE</span></span>
            <span class="field-hint">Enter 0 to decline all upload requests. Maximum configurable value: 1,048,576 MB.</span>
        </label>
        @error('maxUploadMb')<p class="field-error">{{ $message }}</p>@enderror
        <label class="upload-limit-field" for="max-video-upload-mb">
            <span class="setting-field-label">MAXIMUM VIDEO UPLOAD SIZE</span>
            <span class="upload-limit-input"><input id="max-video-upload-mb" type="number" wire:model.number="maxVideoUploadMb" min="0" max="1048576" step="1" required><span>MB PER VIDEO</span></span>
            <span class="field-hint">Enter 0 for no separate video ceiling; the maximum file size above still applies. Current effective video limit: {{ $maxUploadMb === 0 ? 'uploads disabled' : ($maxVideoUploadMb > 0 ? number_format(min($maxVideoUploadMb, $maxUploadMb)) : number_format($maxUploadMb)) . ' MB' }}.</span>
        </label>
        @error('maxVideoUploadMb')<p class="field-error">{{ $message }}</p>@enderror

        <div class="api-switch-row">
            <div class="api-switch-copy"><span class="setting-field-label">API UPLOAD ACCESS</span><span>Allow external clients with a valid upload key to upload files.</span></div>
            <label class="switch-control" for="api-upload-enabled">
                <input id="api-upload-enabled" type="checkbox" wire:model="apiUploadsEnabled" value="1">
                <span class="switch-track" aria-hidden="true"><span></span></span>
                <span class="switch-status" data-switch-status>{{ $apiUploadsEnabled ? 'ENABLED' : 'DISABLED' }}</span>
            </label>
        </div>
        @error('apiUploadsEnabled')<p class="field-error">{{ $message }}</p>@enderror
        <div class="password-form-footer"><span class="field-hint">API toggle affects <code>POST /api/upload</code> only; manual uploads remain available.</span><button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">SAVE UPLOAD POLICY</span><span wire:loading wire:target="save">SAVING…</span></button></div>
    </form>
</section>
