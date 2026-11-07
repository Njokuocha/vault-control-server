<section class="settings-two-factor">
    <article class="two-factor-panel">
        <div class="two-factor-panel__icon"><i class="ri ri-shield-keyhole-line" aria-hidden="true"></i></div>
        <div class="two-factor-panel__copy">
            <span class="section-kicker">ADMINISTRATOR SIGN-IN SECURITY</span>
            <h2>{{ $enabled ? 'Additional sign-in verification is active' : 'Password-only sign-in is active' }}</h2>
            <p>When enabled, a six-digit code is sent to <strong>{{ $adminEmail }}</strong> after correct credentials are entered. The code expires after three minutes and the verification window survives a page refresh.</p>
            <small>Mail delivery uses the Laravel mail transport configured for this application. No verification code is saved in plaintext.</small>
        </div>
        <button class="mail-toggle {{ $enabled ? 'is-enabled' : '' }}" type="button" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" wire:click="toggle" wire:loading.attr="disabled" aria-label="Toggle administrator email two-factor authentication">
            <span></span><strong>{{ $enabled ? 'ON' : 'OFF' }}</strong>
        </button>
    </article>
</section>
