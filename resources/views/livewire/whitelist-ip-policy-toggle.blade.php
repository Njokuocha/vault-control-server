<section class="whitelist-policy-card">
    <div class="api-switch-row">
        <div class="api-switch-copy">
            <span class="setting-field-label">WHITELIST-ONLY AUTHENTICATION</span>
            <span>When enabled and the whitelist contains entries, only listed addresses can sign in. Blacklisted addresses remain denied. An empty whitelist falls back to blacklist enforcement.</span>
        </div>
        <label class="switch-control" for="whitelist-ip-enforcement">
            <input id="whitelist-ip-enforcement" type="checkbox" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}" @checked($enabled) wire:click="toggle" wire:loading.attr="disabled" wire:target="toggle">
            <span class="switch-track" aria-hidden="true"><span></span></span>
            <span class="switch-status">{{ $enabled ? 'ENABLED' : 'DISABLED' }}</span>
        </label>
    </div>
</section>
