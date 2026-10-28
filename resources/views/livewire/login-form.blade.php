<section class="login-card {{ $errors->any() ? 'has-error' : '' }}" aria-labelledby="login-title">
    <div class="login-emblem" aria-hidden="true">
        <svg viewBox="0 0 58 58" fill="none">
            <path d="M29 4.5 47 11v14.8c0 11.8-7.3 19.7-18 25.4C18.3 45.5 11 37.6 11 25.8V11l18-6.5Z" stroke="currentColor" stroke-width="1.4"/>
            <path d="M29 17a7.1 7.1 0 0 0-4.2 12.8V39h8.4v-9.2A7.1 7.1 0 0 0 29 17Z" stroke="currentColor" stroke-width="1.4"/>
            <circle cx="29" cy="24" r="1.8" fill="currentColor"/>
            <path d="M29 25.8v4.5M22 43h14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
    </div>
    <div class="login-brand">{{ config('app.short_name') }}</div>
    <div class="login-subtitle">SECURE DOCUMENT REPOSITORY</div>
    <div class="login-divider"><span></span><b>OPERATOR AUTHENTICATION</b><span></span></div>

    @if ($errors->any())
        <div class="login-error" role="alert">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8v5M12 16.5h.01"/><path d="M10.2 4.6 2.8 17.4A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.6L13.8 4.6a2.1 2.1 0 0 0-3.6 0Z"/></svg>
            <span>ACCESS DENIED: {{ $errors->first() }}</span>
        </div>
    @endif

    <form class="login-form" x-on:submit.prevent="$wire.authenticate($el.elements.email.value, $el.elements.password.value, $el.elements.remember.checked)">
        <label class="auth-field">
            <span class="auth-field-label">OPERATOR ID</span>
            <span class="auth-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.3"/><path d="M5.5 20v-1.7a6.5 6.5 0 0 1 13 0V20z"/></svg>
                <input type="email" name="email" placeholder="operator@domain.com" autocomplete="username" required autofocus>
            </span>
        </label>

        <label class="auth-field">
            <span class="auth-field-label">ACCESS CODE</span>
            <span class="auth-input-wrap">
                <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="1.5"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5M12 14.5v2.2"/></svg>
                <input id="access-code" type="password" name="password" placeholder="Enter your access code" autocomplete="current-password" required>
                <button class="password-toggle" type="button" aria-label="Show access code" aria-pressed="false" data-password-toggle>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.3-6 9.5-6 9.5 6 9.5 6-3.3 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                </button>
            </span>
        </label>

        <label class="remember-control"><input type="checkbox" name="remember" value="1"><span class="custom-check" aria-hidden="true"></span><span>Keep this session active</span></label>

        <button class="authenticate-button" type="submit" wire:loading.attr="disabled" wire:loading.class="is-loading" wire:target="authenticate">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v2M12 19v2M3 12h2M19 12h2M5.6 5.6 7 7M17 17l1.4 1.4M18.4 5.6 17 7M7 17l-1.4 1.4"/><circle cx="12" cy="12" r="5.2"/><circle cx="12" cy="12" r="1.5"/></svg>
            <span wire:loading.remove wire:target="authenticate">AUTHENTICATE</span>
            <span wire:loading wire:target="authenticate">VERIFYING</span>
            <span class="auth-progress"></span>
        </button>
        <p class="login-progress-message" aria-live="polite" wire:loading wire:target="authenticate">Verifying credentials…</p>
    </form>

    <div class="login-secure-note"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="1.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg> ENCRYPTED SESSION <span>·</span> AUDIT LOGGING ENABLED</div>
</section>
