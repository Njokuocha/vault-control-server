<section class="staff-overview-grid" aria-label="Staff and security overview">
    <article class="glass-panel staff-overview-card staff-overview-card--total">
        <span class="section-kicker">REGISTERED PERSONNEL</span>
        <strong>{{ number_format($metrics['totalStaff']) }}</strong>
        <span>STAFF ACCOUNTS</span>
        <i class="ri ri-team-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--active">
        <span class="section-kicker">ACCOUNT POSTURE</span>
        <strong>{{ number_format($metrics['activeStaff']) }}</strong>
        <span>ACTIVE · AUTHORIZED</span>
        <i class="ri ri-shield-check-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--frozen">
        <span class="section-kicker">ACCOUNT POSTURE</span>
        <strong>{{ number_format($metrics['frozenStaff']) }}</strong>
        <span>FROZEN ACCOUNTS</span>
        <i class="ri ri-snowflake-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--disabled">
        <span class="section-kicker">ACCOUNT POSTURE</span>
        <strong>{{ number_format($metrics['disabledStaff']) }}</strong>
        <span>DISABLED ACCOUNTS</span>
        <i class="ri ri-user-forbid-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card">
        <span class="section-kicker">AGENCY STRUCTURE</span>
        <strong>{{ number_format($metrics['departmentCount']) }}</strong>
        <span>REGISTERED DEPARTMENTS</span>
        <i class="ri ri-node-tree" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--online">
        <span class="section-kicker">SESSION PRESENCE</span>
        <strong>{{ number_format($metrics['activeStaffCount']) }}</strong>
        <span>STAFF WITH ACTIVE SESSIONS</span>
        <i class="ri ri-radar-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card">
        <span class="section-kicker">AUTHENTICATION · 24 HOURS</span>
        <strong>{{ number_format($metrics['passedAuth24Hours']) }}</strong>
        <span>SUCCESSFUL STAFF LOGINS</span>
        <i class="ri ri-login-circle-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--risk">
        <span class="section-kicker">AUTHENTICATION · 24 HOURS</span>
        <strong>{{ number_format($metrics['failedAuth24Hours']) }}</strong>
        <span>FAILED STAFF LOGINS</span>
        <i class="ri ri-error-warning-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--risk">
        <span class="section-kicker">THREAT WATCH · 30 DAYS</span>
        <strong>{{ number_format($metrics['bruteForce30Days']) }}</strong>
        <span>BRUTE-FORCE INCIDENTS</span>
        <i class="ri ri-spam-2-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--person">
        <span class="section-kicker">LAST AUTHENTICATED STAFF</span>
        @if ($metrics['lastAuthenticatedStaff'])
            <div class="staff-overview-person">
                @if ($metrics['lastAuthenticatedStaff']->profile_photo_path)
                    <img src="{{ route($routePrefix.'.staff.photo', $metrics['lastAuthenticatedStaff']) }}" alt="">
                @else
                    <span class="staff-overview-person__fallback" aria-hidden="true">{{ strtoupper(substr($metrics['lastAuthenticatedStaff']->name, 0, 1)) }}</span>
                @endif
                <span><strong>{{ $metrics['lastAuthenticatedStaff']->name }}</strong><small>{{ strtoupper($metrics['lastAuthenticatedStaff']->departmentRecord?->rank_short ?? $metrics['lastAuthenticatedStaff']->department) }}</small></span>
            </div>
            <span class="staff-overview-card__detail">{{ $metrics['lastAuthenticatedAt']?->format('M j, Y · H:i') }} · {{ $metrics['lastAuthenticatedAt']?->diffForHumans() }}</span>
        @else
            <strong class="staff-overview-card__empty">NO STAFF LOGIN RECORDED</strong>
            <span>Successful authentication history will appear here.</span>
        @endif
        <i class="ri ri-user-follow-line" aria-hidden="true"></i>
    </article>
    <article class="glass-panel staff-overview-card staff-overview-card--incident">
        <span class="section-kicker">LATEST STAFF BRUTE-FORCE SIGNAL</span>
        @if ($metrics['latestBruteForce'])
            <strong class="staff-overview-card__account">{{ $metrics['latestBruteForce']->account_name }}</strong>
            <span class="staff-overview-card__detail"><code>{{ $metrics['latestBruteForce']->ip_address }}</code> · {{ number_format($metrics['latestBruteForce']->failed_attempts) }} failures / {{ $metrics['latestBruteForce']->window_seconds }}s window</span>
            <span class="staff-overview-card__detail">{{ $metrics['latestBruteForce']->detected_at?->format('M j, Y · H:i') }} · {{ $metrics['latestBruteForce']->detected_at?->diffForHumans() }}</span>
            @if ($metrics['latestBruteForce']->user_agent)
                <span class="staff-overview-card__agent" title="{{ $metrics['latestBruteForce']->user_agent }}">{{ \Illuminate\Support\Str::limit($metrics['latestBruteForce']->user_agent, 76) }}</span>
            @endif
        @else
            <strong class="staff-overview-card__empty">NO STAFF INCIDENTS</strong>
            <span>No staff-targeted brute-force attempts are recorded.</span>
        @endif
        <i class="ri ri-shield-keyhole-line" aria-hidden="true"></i>
    </article>
</section>
