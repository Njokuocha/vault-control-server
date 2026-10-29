<div class="content-inner agency-staff-profile-page">
    <div class="page-heading">
        <div>
            <span class="section-kicker">CENTRAL ADMINISTRATION · PERSONNEL RECORD</span>
            <h1>{{ $section === 'profile' ? 'Staff profile' : ($section === 'activity' ? 'Activity history' : 'Authentication history') }}</h1>
            <p>Restricted personnel information and account activity for {{ $staff->name }}.</p>
        </div>
        <a class="button-secondary" href="{{ $section === 'profile' ? route('admin.staff.index') : route('admin.staff.show', $staff) }}" wire:navigate>
            {{ $section === 'profile' ? 'STAFF MANAGEMENT' : 'STAFF PROFILE' }}
        </a>
    </div>

    @if ($section === 'profile')
        <section class="glass-panel agency-staff-profile-hero">
            @if ($staff->profile_photo_path)
                <img class="agency-staff-profile-photo" src="{{ route('admin.staff.photo', $staff) }}" alt="{{ $staff->name }} profile picture">
            @else
                <div class="agency-staff-profile-photo agency-staff-profile-photo--initial">{{ strtoupper(substr($staff->name, 0, 1)) }}</div>
            @endif
            <div class="agency-staff-profile-identity">
                <span class="section-kicker">{{ $department?->rank_short ?? strtoupper($staff->department) }} · {{ strtoupper($staff->account_status ?? 'active') }}</span>
                <h2>{{ $staff->name }}</h2>
                <p>{{ $department?->name ?? $staff->department }} · {{ $department?->rank ?? 'Agency staff' }}</p>
            </div>
            <a class="button-secondary" href="{{ route('admin.staff.index', ['search' => $staff->username]) }}" wire:navigate>MANAGE ACCOUNT</a>
        </section>

        <section class="agency-staff-profile-facts" aria-label="Staff account details">
            <article class="glass-panel"><span>USERNAME</span><strong>{{ $staff->username ?: 'Not set' }}</strong></article>
            <article class="glass-panel"><span>EMAIL ADDRESS</span><strong>{{ $staff->email }}</strong></article>
            <article class="glass-panel"><span>ACCOUNT STATUS</span><strong>{{ strtoupper($staff->account_status ?? 'active') }}</strong></article>
            <article class="glass-panel"><span>LAST AUTHENTICATED</span><strong>{{ $lastAuthenticatedAt ? \Illuminate\Support\Carbon::parse($lastAuthenticatedAt)->diffForHumans() : 'No successful login recorded' }}</strong></article>
            <article class="glass-panel"><span>ACCOUNT CREATED</span><strong>{{ $staff->created_at?->format('M d, Y · H:i') ?? 'Unknown' }}</strong></article>
            <article class="glass-panel"><span>LAST UPDATED</span><strong>{{ $staff->updated_at?->format('M d, Y · H:i') ?? 'Unknown' }}</strong></article>
        </section>

        <section class="glass-panel security-table-card agency-staff-history-card">
            <div class="security-section-heading">
                <div><span class="section-kicker">ACCOUNT AUDIT</span><h2>Authentication history</h2></div>
                <a class="text-action" href="{{ route('admin.staff.history', [$staff, 'authentication-history']) }}" wire:navigate>VIEW ALL ↗</a>
            </div>
            <div class="security-table-wrap"><table class="security-table"><thead><tr><th>TIME</th><th>IP ADDRESS</th><th>DURATION</th><th>STATUS</th><th>BRUTE FORCE</th></tr></thead><tbody>
                @forelse ($recentAuthEvents as $event)
                    <tr>
                        <td>{{ $event->occurred_at?->format('M d · H:i:s') }}</td>
                        <td><code>{{ $event->ip_address }}</code></td>
                        <td>{{ $event->duration_ms }}ms</td>
                        <td><span class="security-status security-status--{{ $event->status }}">{{ strtoupper($event->status) }}</span></td>
                        <td><span class="security-boolean {{ $event->is_brute_force ? 'is-true' : 'is-false' }}">{{ $event->is_brute_force ? 'TRUE' : 'FALSE' }}</span></td>
                    </tr>
                @empty
                    <tr><td class="security-table-empty" colspan="5">No authentication events have been recorded for this staff account.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>

        <section class="glass-panel security-table-card agency-staff-history-card">
            <div class="security-section-heading">
                <div><span class="section-kicker">ACCOUNT AUDIT</span><h2>Activity log</h2></div>
                <a class="text-action" href="{{ route('admin.staff.history', [$staff, 'activity']) }}" wire:navigate>VIEW ALL ↗</a>
            </div>
            <div class="security-table-wrap"><table class="security-table"><thead><tr><th>TIME</th><th>CATEGORY</th><th>ACTIVITY</th><th>IP ADDRESS</th></tr></thead><tbody>
                @forelse ($recentActivityLogs as $log)
                    <tr>
                        <td>{{ $log->occurred_at?->format('M d · H:i:s') }}</td>
                        <td><span class="security-category">{{ strtoupper($log->category) }}</span></td>
                        <td>{{ $log->summary }}</td>
                        <td><code>{{ $log->ip_address ?? 'SYSTEM' }}</code></td>
                    </tr>
                @empty
                    <tr><td class="security-table-empty" colspan="4">No activity has been recorded for this staff account.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @else
        <section class="glass-panel security-table-card agency-staff-history-card">
            <div class="agency-staff-history-toolbar">
                <label class="agency-staff-profile-search">
                    <span>SEARCH {{ $section === 'activity' ? 'ACTIVITY' : 'AUTHENTICATION HISTORY' }}</span>
                    <input type="search" wire:model.live.debounce.250ms="search" placeholder="{{ $section === 'activity' ? 'Summary, category, or action' : 'IP address, status, or result' }}">
                </label>
            </div>
            @if ($section === 'authentication-history')
                <div class="security-table-wrap"><table class="security-table"><thead><tr><th>TIME</th><th>IP ADDRESS</th><th>DURATION</th><th>STATUS</th><th>BRUTE FORCE</th><th>RESULT</th></tr></thead><tbody>
                    @forelse ($authEvents as $event)
                        <tr>
                            <td>{{ $event->occurred_at?->format('M d, Y · H:i:s') }}</td>
                            <td><code>{{ $event->ip_address }}</code></td>
                            <td>{{ $event->duration_ms }}ms</td>
                            <td><span class="security-status security-status--{{ $event->status }}">{{ strtoupper($event->status) }}</span></td>
                            <td><span class="security-boolean {{ $event->is_brute_force ? 'is-true' : 'is-false' }}">{{ $event->is_brute_force ? 'TRUE' : 'FALSE' }}</span></td>
                            <td>{{ $event->result_detail }}</td>
                        </tr>
                    @empty
                        <tr><td class="security-table-empty" colspan="6">No authentication events match this search.</td></tr>
                    @endforelse
                </tbody></table></div>
                <div class="security-pagination">{{ $authEvents->links() }}</div>
            @else
                <div class="security-table-wrap"><table class="security-table"><thead><tr><th>TIME</th><th>CATEGORY</th><th>ACTIVITY</th><th>DETAILS</th><th>IP ADDRESS</th></tr></thead><tbody>
                    @forelse ($activityLogs as $log)
                        <tr>
                            <td>{{ $log->occurred_at?->format('M d, Y · H:i:s') }}</td>
                            <td><span class="security-category">{{ strtoupper($log->category) }}</span></td>
                            <td>{{ $log->summary }}</td>
                            <td>{{ $log->details ? json_encode($log->details, JSON_UNESCAPED_SLASHES) : '—' }}</td>
                            <td><code>{{ $log->ip_address ?? 'SYSTEM' }}</code></td>
                        </tr>
                    @empty
                        <tr><td class="security-table-empty" colspan="5">No activity matches this search.</td></tr>
                    @endforelse
                </tbody></table></div>
                <div class="security-pagination">{{ $activityLogs->links() }}</div>
            @endif
        </section>
    @endif
</div>
