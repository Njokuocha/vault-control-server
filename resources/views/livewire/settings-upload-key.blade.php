<section class="settings-card" aria-labelledby="upload-key-title">
    <div class="settings-card-head">
        <span class="settings-card-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="8.2" cy="15.8" r="4.2"/><path d="m11.2 12.8 8.3-8.3 2 2-2 2 1.5 1.5-2.2 2.2-1.5-1.5-3.1 3.1"/></svg></span>
        <div><div class="section-kicker">API ACCESS CONTROL</div><h2 id="upload-key-title">Upload key</h2></div>
        <span class="settings-card-index">01</span>
    </div>
    <p class="settings-description">This secret authorizes external clients to upload files. It is encrypted at rest. Rotating the key immediately invalidates the previous value.</p>
    <div class="setting-field-label">CURRENT API KEY</div>
    <div class="secret-display">
        <code id="current-upload-key">{{ $uploadKey ?? 'No key configured' }}</code>
        @if ($uploadKey)
            <button class="icon-action" type="button" aria-label="Copy current upload key" title="Copy key" data-copy-target="current-upload-key"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="11" height="12" rx="1.5"/><path d="M16 8V5.5A1.5 1.5 0 0 0 14.5 4h-9A1.5 1.5 0 0 0 4 5.5v9A1.5 1.5 0 0 0 5.5 16H8"/></svg></button>
        @endif
    </div>
    <form class="key-form" wire:submit.prevent="rotate">
        <label class="setting-field-label" for="upload-key">SET A CUSTOM KEY</label>
        <div class="key-input-row">
            <input id="upload-key" type="text" wire:model="customKey" placeholder="Leave blank to generate a secure key" autocomplete="off" maxlength="255">
            <button class="button-secondary" type="button" wire:click="generate" data-settings-key-generate>GENERATE</button>
            <button class="button-primary" type="button" data-settings-key-rotate wire:loading.attr="disabled" wire:target="rotate">ROTATE KEY</button>
        </div>
        @error('customKey')<p class="field-error">{{ $message }}</p>@enderror
        <p class="field-hint">Enter a custom value, or leave the field blank to generate a secure key. Anyone who obtains this key can upload files.</p>
    </form>
    <div class="endpoint-section">
        <div class="setting-field-label">UPLOAD ENDPOINT</div>
        <div class="endpoint-url"><span class="endpoint-method">POST</span><code>{{ $uploadEndpoint }}</code><span class="endpoint-pending">{{ $apiUploadsEnabled ? 'API UPLOAD ENABLED' : 'API UPLOAD DISABLED' }}</span></div>
        <div class="integration-notice"><span>i</span><p>API uploads require the current key as the <code>auth</code> query parameter. Manual uploads remain available while API uploads are disabled, subject to the maximum file size.</p></div>
        @if ($uploadKey)
            <div class="curl-sample">
                <div class="curl-sample-head"><span>CLIENT REQUEST EXAMPLE</span><button class="text-action" type="button" data-copy-target="curl-example">COPY</button></div>
                <code id="curl-example">curl -X POST "{{ $uploadEndpoint }}?auth={{ $uploadKey }}" -F "file=@/path/to/file"</code>
            </div>
        @endif
    </div>
</section>
