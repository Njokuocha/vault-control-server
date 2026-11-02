<div class="content-inner staff-analytics-page">
    <header class="page-heading staff-analytics-heading">
        <div>
            <span class="section-kicker">CENTRAL ADMINISTRATION · PERSONNEL INTELLIGENCE</span>
            <h1>Staff Analytics</h1>
            <p>Account posture, session duration, and authentication risk indicators for agency staff.</p>
        </div>
        <a class="button-secondary" href="{{ route('admin.staff.index', ['display' => 'full']) }}" wire:navigate><i class="ri ri-arrow-left-line" aria-hidden="true"></i> BACK TO STAFF</a>
    </header>
    @include('components.staff-overview-cards', ['metrics' => $metrics, 'routePrefix' => $routePrefix])

    <section class="staff-analytics-grid" aria-label="Staff security analytics">
        <article class="glass-panel staff-analytics-panel staff-analytics-panel--sessions">
            <header class="staff-analytics-panel__heading">
                <div><span class="section-kicker">SESSION DURATION · ROLLING 30 DAYS</span><h2>Highest time in application</h2></div>
                <span class="staff-analytics-live"><i></i> LIVE SESSION SHOWN IN GREEN</span>
            </header>
            <p class="staff-analytics-description">Time is summed from each tracked login until logout; current sessions accrue through now. Historical tracking begins with this release.</p>
            @if ($metrics['topSessions']->isNotEmpty())
                <div class="staff-session-chart" role="list" aria-label="Top five staff by application session duration">
                    @foreach ($metrics['topSessions'] as $session)
                        <div class="staff-session-chart__row {{ $session->is_active ? 'is-active' : '' }}" role="listitem">
                            <div class="staff-session-chart__identity">
                                <span class="staff-session-chart__rank">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                                <span><strong>{{ $session->name }}</strong><small>{{ strtoupper($session->department) }} · {{ $session->is_active ? 'LOGGED IN NOW' : 'SESSION ENDED' }}</small></span>
                                <b>{{ $session->duration_label }}</b>
                            </div>
                            <div class="staff-session-chart__track"><span style="--session-share: {{ max(2, (int) round($session->duration_seconds / max(1, (int) $metrics['topSessions']->max('duration_seconds')) * 100)) }}%"></span></div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="staff-analytics-empty"><i class="ri ri-time-line" aria-hidden="true"></i><strong>NO SESSION HISTORY YET</strong><span>Tracked session durations will appear after staff sign in.</span></div>
            @endif
        </article>

        <article class="glass-panel staff-analytics-panel">
            <header class="staff-analytics-panel__heading">
                <div><span class="section-kicker">AUTHENTICATION · 7 DAYS</span><h2>Passed vs. failed attempts</h2></div>
                <span class="staff-analytics-tag">STAFF ONLY</span>
            </header>
            <div class="staff-auth-legend"><span><i class="is-passed"></i> PASSED</span><span><i class="is-failed"></i> FAILED</span></div>
            <div class="staff-auth-chart" role="img" aria-label="Daily staff authentication attempts for the past seven days, separated into passed and failed attempts">
                @foreach ($metrics['authenticationTimeline'] as $day)
                    <div class="staff-auth-chart__day" aria-label="{{ $day['label'] }}: {{ $day['passed'] }} passed, {{ $day['failed'] }} failed">
                        <div class="staff-auth-chart__column">
                            <span class="is-passed" style="--bar-height: {{ $day['passedHeight'] }}%"></span>
                            <span class="is-failed" style="--bar-height: {{ $day['failedHeight'] }}%"></span>
                        </div>
                        <small>{{ $day['label'] }}</small>
                        <b>{{ $day['total'] }}</b>
                    </div>
                @endforeach
            </div>
        </article>

        <article class="glass-panel staff-analytics-panel staff-analytics-panel--posture">
            <header class="staff-analytics-panel__heading">
                <div><span class="section-kicker">ACCESS POSTURE · BY DEPARTMENT</span><h2>Account status distribution</h2></div>
                <span class="staff-analytics-tag">LIVE ROSTER</span>
            </header>
            <div class="staff-posture-legend"><span><i class="is-active"></i> ACTIVE</span><span><i class="is-frozen"></i> FROZEN</span><span><i class="is-disabled"></i> DISABLED</span></div>
            <div class="staff-posture-chart">
                @forelse ($metrics['departmentPosture'] as $department)
                    <div class="staff-posture-chart__row">
                        <div><strong>{{ $department['name'] }}</strong><span>{{ $department['total'] }} STAFF</span></div>
                        <div class="staff-posture-chart__bar" role="img" aria-label="{{ $department['name'] }}: {{ $department['active'] }} active, {{ $department['frozen'] }} frozen, {{ $department['disabled'] }} disabled">
                            @if ($department['activeWidth'] > 0)<i class="is-active" style="--posture-share: {{ $department['activeWidth'] }}%"></i>@endif
                            @if ($department['frozenWidth'] > 0)<i class="is-frozen" style="--posture-share: {{ $department['frozenWidth'] }}%"></i>@endif
                            @if ($department['disabledWidth'] > 0)<i class="is-disabled" style="--posture-share: {{ $department['disabledWidth'] }}%"></i>@endif
                        </div>
                        <small>{{ $department['active'] }} active · {{ $department['frozen'] }} frozen · {{ $department['disabled'] }} disabled</small>
                    </div>
                @empty
                    <div class="staff-analytics-empty"><strong>NO DEPARTMENTS</strong><span>Department account posture will appear here.</span></div>
                @endforelse
            </div>
        </article>
    </section>
</div>
