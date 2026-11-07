<section class="settings-card password-card cfm-password-card" aria-labelledby="cfm-password-title">
    <div class="settings-card-head">
        <span class="settings-card-icon settings-card-icon-blue"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v2"/></svg></span>
        <div><div class="section-kicker">ACCOUNT SECURITY</div><h2 id="cfm-password-title">Change password</h2></div>
    </div>
    <p class="settings-description">Enter your current password, then choose a new password with at least 12 characters.</p>
    <form class="password-form" wire:submit="save">
        <label class="password-field" for="cfm-current-password"><span class="setting-field-label">CURRENT PASSWORD</span><input id="cfm-current-password" type="password" wire:model="currentPassword" autocomplete="current-password" required></label>
        @error('currentPassword')<p class="field-error">{{ $message }}</p>@enderror
        <div class="password-fields-row">
            <label class="password-field" for="cfm-new-password"><span class="setting-field-label">NEW PASSWORD</span><input id="cfm-new-password" type="password" wire:model="newPassword" autocomplete="new-password" minlength="12" required></label>
            <label class="password-field" for="cfm-new-password-confirm"><span class="setting-field-label">CONFIRM PASSWORD</span><input id="cfm-new-password-confirm" type="password" wire:model="newPasswordConfirmation" autocomplete="new-password" minlength="12" required></label>
        </div>
        @error('newPassword')<p class="field-error">{{ $message }}</p>@enderror
        @error('newPasswordConfirmation')<p class="field-error">{{ $message }}</p>@enderror
        <div class="password-form-footer">
            <span class="field-hint">Your new password takes effect immediately.</span>
            <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">UPDATE PASSWORD</span><span wire:loading wire:target="save">UPDATING…</span></button>
        </div>
    </form>
</section>
