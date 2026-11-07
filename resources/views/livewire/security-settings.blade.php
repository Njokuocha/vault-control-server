<div class="security-settings-grid">
    <section class="glass-panel security-settings-card">
        <div class="calc-panel-heading"><div><span class="section-kicker">AUTHENTICATION DEFENSE</span><h2>Brute-force detection</h2></div></div>
        <p>Detect repeated failures across a source IP, regardless of account name. Five failures within five minutes blacklist the IP.</p>
        <form wire:submit="savePolicy" class="security-settings-form">
            <label>Failed attempts before brute-force flag
                <input type="number" min="2" max="50" wire:model="bruteForceAttempts">
                <small>@error('bruteForceAttempts'){{ $message }}@enderror</small>
            </label>
            <label>Detection window (seconds)
                <input type="number" min="30" max="3600" wire:model="bruteForceWindowSeconds">
                <small>@error('bruteForceWindowSeconds'){{ $message }}@enderror</small>
            </label>
            <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="savePolicy"><span wire:loading.remove wire:target="savePolicy">SAVE SECURITY POLICY</span><span wire:loading wire:target="savePolicy">SAVING…</span></button>
        </form>
    </section>

    <section class="glass-panel security-settings-card">
        <div class="calc-panel-heading"><div><span class="section-kicker">CORPORATE LOCATION</span><h2>Security map</h2></div></div>
        <p>Only HTTPS Google Maps URLs are accepted. Coordinates are parsed from the URL locally; no geocoding service or third-party API key is used.</p>
        <form wire:submit="saveMap" class="security-settings-form">
            <label>Current map link
                <textarea rows="3" readonly>{{ $currentLocation?->map_url ?? 'No location map configured.' }}</textarea>
            </label>
            <label>Paste a Google Maps link (leave empty to remove)
                <textarea rows="3" wire:model="mapUrl" placeholder="https://www.google.com/maps/place/..."></textarea>
                <small>@error('mapUrl'){{ $message }}@enderror</small>
            </label>
            <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveMap"><span wire:loading.remove wire:target="saveMap">UPDATE LOCATION MAP</span><span wire:loading wire:target="saveMap">UPDATING…</span></button>
        </form>
    </section>
</div>
