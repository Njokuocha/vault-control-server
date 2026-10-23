@extends('layouts.app')

@section('page_title', 'Settings — ' . config('app.name'))
@section('page_label', 'SETTINGS')

@section('content')
    <section class="page-heading">
        <div><div class="eyebrow"><span class="eyebrow-line"></span> SYSTEM CONFIGURATION <span class="eyebrow-index">02 / 04</span></div><h1 id="settings-title">System <span>settings</span></h1><p class="page-description">Manage your ingestion key and administrator credentials.</p></div>
        <a class="back-link" href="{{ route('dashboard.index') }}"><span aria-hidden="true">←</span> RETURN TO ARCHIVE</a>
    </section>

    @if (session('status'))
        <div class="settings-alert" role="status"><span class="alert-check">✓</span>{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="settings-alert settings-alert-error" role="alert"><span>!</span>{{ $errors->first() }}</div>
    @endif

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

        <form method="POST" action="{{ route('settings.key.reset') }}" class="key-form">
            @csrf
            <label class="setting-field-label" for="upload-key">SET A CUSTOM KEY</label>
            <div class="key-input-row">
                <input id="upload-key" type="text" name="upload_key" value="{{ old('upload_key') }}" placeholder="Enter a strong custom key" autocomplete="off" maxlength="255" required>
                <button class="button-secondary" type="button" data-generate-key>GENERATE</button>
                <button class="button-primary" type="submit">ROTATE KEY</button>
            </div>
            @error('upload_key')<p class="field-error">{{ $message }}</p>@enderror
            <p class="field-hint">Use a unique, randomly generated value. Anyone who obtains this key can upload files.</p>
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

    <section class="settings-card upload-policy-card" aria-labelledby="upload-policy-title">
        <div class="settings-card-head">
            <span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 15V4M8 8l4-4 4 4"/><path d="M5 13v6a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-6"/></svg></span>
            <div><div class="section-kicker">INGESTION POLICY</div><h2 id="upload-policy-title">Upload controls</h2></div>
            <span class="settings-card-index">02</span>
        </div>
        <p class="settings-description">Set the per-file upload limit and control whether external clients may use the API endpoint. A limit of 0 MB blocks all uploads.</p>
        <form method="POST" action="{{ route('settings.upload-policy.update') }}" class="upload-policy-form">
            @csrf
            <label class="upload-limit-field" for="max-upload-mb">
                <span class="setting-field-label">MAXIMUM FILE SIZE</span>
                <span class="upload-limit-input"><input id="max-upload-mb" type="number" name="max_upload_mb" min="0" max="1048576" step="1" value="{{ old('max_upload_mb', $maxUploadMb) }}" required><span>MB PER FILE</span></span>
                <span class="field-hint">Enter 0 to decline all upload requests. Maximum configurable value: 1,048,576 MB.</span>
            </label>
            @error('max_upload_mb')<p class="field-error">{{ $message }}</p>@enderror

            <div class="api-switch-row">
                <div class="api-switch-copy"><span class="setting-field-label">API UPLOAD ACCESS</span><span>Allow external clients with a valid upload key to upload files.</span></div>
                <label class="switch-control" for="api-upload-enabled">
                    <input type="hidden" name="api_upload_enabled" value="0">
                    <input id="api-upload-enabled" type="checkbox" name="api_upload_enabled" value="1" @checked(old('api_upload_enabled', $apiUploadsEnabled))>
                    <span class="switch-track" aria-hidden="true"><span></span></span>
                    <span class="switch-status" data-switch-status>{{ $apiUploadsEnabled ? 'ENABLED' : 'DISABLED' }}</span>
                </label>
            </div>
            @error('api_upload_enabled')<p class="field-error">{{ $message }}</p>@enderror
            <div class="password-form-footer"><span class="field-hint">API toggle affects <code>POST /api/upload</code> only; manual uploads remain available.</span><button class="button-primary" type="submit">SAVE UPLOAD POLICY</button></div>
        </form>
    </section>

    <section class="settings-card password-card" aria-labelledby="password-title">
        <div class="settings-card-head">
            <span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg></span>
            <div><div class="section-kicker">ACCOUNT SECURITY</div><h2 id="password-title">Administrator password</h2></div>
            <span class="settings-card-index">03</span>
        </div>
        <p class="settings-description">Update the password used to authenticate this administrator account. Your current password is required.</p>

        <form method="POST" action="{{ route('settings.password.update') }}" class="password-form">
            @csrf
            <label class="password-field" for="current-password"><span class="setting-field-label">CURRENT PASSWORD</span><input id="current-password" type="password" name="current_password" autocomplete="current-password" required></label>
            @error('current_password')<p class="field-error">{{ $message }}</p>@enderror
            <div class="password-fields-row">
                <label class="password-field" for="new-password"><span class="setting-field-label">NEW PASSWORD</span><input id="new-password" type="password" name="password" autocomplete="new-password" minlength="12" required></label>
                <label class="password-field" for="new-password-confirm"><span class="setting-field-label">CONFIRM PASSWORD</span><input id="new-password-confirm" type="password" name="password_confirmation" autocomplete="new-password" minlength="12" required></label>
            </div>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
            <div class="password-form-footer"><span class="field-hint">Password updates immediately affect the admin login flow.</span><button class="button-primary" type="submit">UPDATE PASSWORD</button></div>
        </form>
    </section>

    <footer class="page-footer"><span>{{ strtoupper(config('app.name')) }}</span><span>PRIVATE BY DESIGN <i>·</i> ACCESS CONTROLLED</span></footer>
@endsection