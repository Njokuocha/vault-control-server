<div class="content-inner security-page">
    <div class="page-heading security-page-heading">
        <div><span class="section-kicker">CENTRAL ADMINISTRATION · THREAT AWARENESS</span><h1>Auth Security</h1><p>Authentication status, abuse indicators, and auditable activity across all agency departments.</p></div>
        <span class="agency-status-tag"><i></i> ADMIN ONLY · LIVE</span>
    </div>

    <section class="security-overview-grid">
        <article class="glass-panel security-connect-card">
            <div class="security-connect-card__main">
                <span class="section-kicker">ACTIVE SESSIONS · {{ $snapshotAt?->diffForHumans() ?? 'AWAITING SNAPSHOT' }}</span>
                <strong class="security-connect-card__number">{{ number_format($activeAccounts) }}<small> / {{ number_format($totalAccounts) }}</small></strong>
                <div class="security-connect-card__status {{ $activeAccounts > 0 ? 'is-connected' : 'is-disconnected' }}">
                    <span></span>{{ $activeAccounts > 0 ? 'CONNECTED' : 'NO ACTIVE SESSIONS' }}
                </div>
            </div>
            <div class="security-connect-card__checks">
                <span><i class="ri ri-check-double-line {{ $isAdminAuthenticated ? 'is-ok' : 'is-failed' }}" aria-hidden="true"></i> Authenticated</span>
                <span><i class="ri ri-check-double-line is-ok" aria-hidden="true"></i> Connected to server</span>
                <span><i class="ri ri-check-double-line {{ $connectedToDatabase ? 'is-ok' : 'is-failed' }}" aria-hidden="true"></i> Connected to database</span>
                <div class="security-connect-card__ratio"><span>ACTIVE ACCOUNT RATIO</span><strong>{{ $activeRatio }}%</strong><i style="--ratio: {{ $activeRatio }}%"></i></div>
            </div>
        </article>
        <a class="glass-panel security-stat-card security-stat-card--logs" href="{{ route('admin.security.activity') }}" wire:navigate>
            <span class="section-kicker">ACTIVITY LOGS · PAST 24 HOURS</span><strong>{{ number_format($logs24Hours) }}</strong><span>RECORDED EVENTS</span><i class="ri ri-fingerprint-line" aria-hidden="true"></i>
        </a>
        <a class="glass-panel security-stat-card" href="{{ route('admin.security.auth-history') }}" wire:navigate>
            <span class="section-kicker">AUTHENTICATION · TODAY</span><strong>{{ number_format($authAttemptsToday) }}</strong><span>ATTEMPTS RECORDED</span>
            <div class="security-stat-card__split"><span class="is-failed">{{ $failedToday }} FAILED</span><span class="is-ok">{{ $passedToday }} PASSED</span></div>
        </a>
        <a class="glass-panel security-stat-card security-stat-card--brute" href="{{ route('admin.security.brute-force') }}" wire:navigate>
            <span class="section-kicker">BRUTE-FORCE ATTEMPTS</span><strong>{{ number_format($bruteForce30Days) }}</strong><span>RECORDED · PAST 30 DAYS</span>
            <em>DETECTION WINDOW {{ $bruteForceWindow }} SEC</em>
        </a>
    </section>

    <section class="security-location-grid">
        <article class="glass-panel security-map-card">
            <div class="security-section-heading"><div><span class="section-kicker">CORPORATE LOCATION</span><h2>Operations map</h2></div><a class="text-action" href="{{ route('settings.index') }}" wire:navigate>CONFIGURE ↗</a></div>
            @if ($location)
                <iframe title="Corporate location map" src="{{ $location->embed_url }}" loading="lazy" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin allow-popups"></iframe>
            @else
                <div class="security-map-empty"><span class="ri ri-map-2-line" aria-hidden="true"></span><strong>LOCATION NOT CONFIGURED</strong><small>Add a Google Maps link in Settings to show the corporate location here.</small><a class="button-secondary" href="{{ route('settings.index') }}" wire:navigate>OPEN SETTINGS</a></div>
            @endif
            <div class="security-coordinates"><span>LAT <b>{{ $location?->latitude ?? 'Unknown' }}</b></span><span>LONG <b>{{ $location?->longitude ?? 'Unknown' }}</b></span></div>
        </article>
        <article class="glass-panel security-radar-card">
            <div class="security-section-heading"><div><span class="section-kicker">NETWORK WATCH</span><h2>Signal sweep</h2></div><span class="security-radar-live"><i></i> MONITORING</span></div>
            <div class="security-radar" role="img" aria-label="Animated network radar visualization">
                <span class="security-radar__ring security-radar__ring--outer"></span><span class="security-radar__ring security-radar__ring--middle"></span><span class="security-radar__ring security-radar__ring--inner"></span>
                <span class="security-radar__crosshair security-radar__crosshair--horizontal"></span><span class="security-radar__crosshair security-radar__crosshair--vertical"></span>
                <span class="security-radar__sweep"></span><i class="security-radar__blip security-radar__blip--one"></i><i class="security-radar__blip security-radar__blip--two"></i><i class="security-radar__blip security-radar__blip--three"></i>
                <span class="security-radar__center"></span>
            </div>
            <div class="security-radar-readout"><span>LIVE AUTHENTICATION EVENTS</span><strong>{{ number_format($authAttemptsToday) }}</strong><small>Displayed signal intensity is illustrative, not geolocation telemetry.</small></div>
        </article>
    </section>

    <section class="glass-panel security-department-card">
        <div class="security-section-heading"><div><span class="section-kicker">AGENCY STRUCTURE</span><h2>{{ $departmentCount }} departments available</h2></div><a class="button-secondary" href="{{ route('admin.departments.index') }}" wire:navigate>VIEW DEPARTMENTS</a></div>
        @if ($recentDepartments->isNotEmpty())
            <div class="security-department-list">
                @foreach ($recentDepartments as $department)
                    <a href="{{ route('admin.departments.staff', $department) }}" wire:navigate>
                        <span>{{ $department->rank_short }}</span><strong>{{ $department->name }}</strong><small>{{ $department->rank }}</small>
                    </a>
                @endforeach
            </div>
        @else
            <p class="security-muted">No departments are registered.</p>
        @endif
    </section>

    <section class="security-halves">
        <article class="glass-panel security-table-card">
            <div class="security-section-heading"><div><span class="section-kicker">SOURCE CONTROLS</span><h2>Blacklisted IPs</h2></div><a class="text-action" href="{{ route('admin.security.blacklisted-ips') }}" wire:navigate>VIEW ALL ↗</a></div>
            <div class="security-table-wrap"><table class="security-table"><thead><tr><th>S/N</th><th>IP ADDRESS</th><th>TRIGGERED</th></tr></thead><tbody>
                @forelse ($blacklistedIps as $ip)
                    <tr><td>{{ $loop->iteration }}</td><td><code>{{ $ip->ip_address }}</code></td><td>{{ $ip->triggered_at?->diffForHumans() }}</td></tr>
                @empty
                    <tr><td class="security-table-empty" colspan="3">No blacklisted IPs recorded.</td></tr>
                @endforelse
            </tbody></table></div>
        </article>
        <article class="glass-panel security-table-card">
            <div class="security-section-heading"><div><span class="section-kicker">TODAY · {{ $authAttemptsToday }} ATTEMPTS</span><h2>Authentication history</h2></div><a class="text-action" href="{{ route('admin.security.auth-history') }}" wire:navigate>VIEW HISTORY ↗</a></div>
            <div class="security-table-wrap"><table class="security-table"><thead><tr><th>USER</th><th>IP / TIME</th><th>LASTED</th><th>STATUS</th><th>BRUTE</th></tr></thead><tbody>
                @forelse ($authHistory as $event)
                    <tr>
                        <td><a href="{{ route('admin.security.auth-event', $event) }}" wire:navigate>{{ $event->account_name }}<small>ID {{ $event->account_id ?? 'N' }}</small></a></td>
                        <td><code>{{ $event->ip_address }}</code><small>{{ $event->occurred_at?->format('H:i:s') }}</small></td>
                        <td>{{ $event->duration_ms }}ms</td>
                        <td><span class="security-status security-status--{{ $event->status }}">{{ strtoupper($event->status) }}</span></td>
                        <td><span class="security-boolean {{ $event->is_brute_force ? 'is-true' : 'is-false' }}">{{ $event->is_brute_force ? 'TRUE' : 'FALSE' }}</span></td>
                    </tr>
                @empty
                    <tr><td class="security-table-empty" colspan="5">No authentication attempts recorded today.</td></tr>
                @endforelse
            </tbody></table></div>
        </article>
    </section>

    <section class="glass-panel security-table-card security-activity-card">
        <div class="security-section-heading"><div><span class="section-kicker">AGENCY-WIDE AUDIT TRAIL</span><h2>Recent activity</h2></div><a class="text-action" href="{{ route('admin.security.activity') }}" wire:navigate>VIEW ALL ACTIVITY ↗</a></div>
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>TIME</th><th>ACTOR</th><th>EVENT</th><th>CATEGORY</th><th>SOURCE</th></tr></thead><tbody>
            @forelse ($activityLogs as $log)
                <tr>
                    <td><a href="{{ route('admin.security.activity-detail', $log) }}" wire:navigate>{{ $log->occurred_at?->format('M d · H:i:s') }}</a></td>
                    <td>{{ $log->actor_name }}<small>{{ strtoupper($log->actor_type ?? 'SYSTEM') }} · {{ $log->actor_id ?? 'N' }}</small></td>
                    <td><a href="{{ route('admin.security.activity-detail', $log) }}" wire:navigate>{{ $log->summary }}</a></td>
                    <td><span class="security-category">{{ strtoupper($log->category) }}</span></td>
                    <td><code>{{ $log->ip_address ?? 'SYSTEM' }}</code></td>
                </tr>
            @empty
                <tr><td class="security-table-empty" colspan="5">No activity has been recorded yet.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-dashboard-pagination">{{ $activityLogs->links('livewire.admin.security-pagination') }}</div>
    </section>
</div>
