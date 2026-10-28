<section class="settings-card password-card" aria-labelledby="image-pin-title">
    <div class="settings-card-head">
        <span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg></span>
        <div><div class="section-kicker">ACCOUNT SECURITY</div><h2 id="image-pin-title">Person image PIN</h2></div>
        <span class="settings-card-index">03</span>
    </div>
    <p class="settings-description">A four-digit PIN is required before person profile images or galleries can be viewed. The PIN is encrypted at rest and is never displayed after saving.</p>
    <div class="image-pin-state"><span class="status-dot"></span>{{ $imagePinConfigured ? 'IMAGE PIN CONFIGURED' : 'IMAGE PIN NOT CONFIGURED' }}</div>
    <form class="password-form" wire:submit="save">
        <div class="password-fields-row">
            <label class="password-field" for="image-pin"><span class="setting-field-label">{{ $imagePinConfigured ? 'NEW FOUR-DIGIT PIN' : 'CREATE FOUR-DIGIT PIN' }}</span><input id="image-pin" type="password" wire:model="imagePin" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password" required></label>
            <label class="password-field" for="image-pin-confirm"><span class="setting-field-label">CONFIRM PIN</span><input id="image-pin-confirm" type="password" wire:model="imagePinConfirmation" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="new-password" required></label>
        </div>
        @error('imagePin')<p class="field-error">{{ $message }}</p>@enderror
        @error('imagePinConfirmation')<p class="field-error">{{ $message }}</p>@enderror
        <div class="password-form-footer"><span class="field-hint">Updating the PIN immediately relocks viewables in the current session.</span><button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">{{ $imagePinConfigured ? 'UPDATE IMAGE PIN' : 'CREATE IMAGE PIN' }}</span><span wire:loading wire:target="save">SAVING…</span></button></div>
    </form>
</section>
