<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">AUTH SECURITY · AUDIT</span><h1>Authentication History</h1><p>Search all recorded admin and department sign-in outcomes.</p></div><a class="button-secondary" href="{{ route('admin.security.index') }}" wire:navigate>AUTH SECURITY</a></div>
    <section class="glass-panel security-table-card">
        <div class="security-filter-bar">
            <label><span>SEARCH ACCOUNT OR IP</span><input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, ID, IP address, guard"></label>
            <label><span>BRUTE FORCE</span><select wire:model.live="bruteForce"><option value="">All outcomes</option><option value="yes">Brute force</option><option value="no">Not brute force</option></select></label>
            <label><span>FROM MONTH</span><select wire:model.live="fromMonth"><option value="">All months</option>@foreach ($months as $month)<option value="{{ $month['value'] }}">{{ $month['label'] }}</option>@endforeach</select></label>
            <label><span>TO MONTH</span><select wire:model.live="toMonth"><option value="">Current and earlier</option>@foreach ($months as $month)<option value="{{ $month['value'] }}">{{ $month['label'] }}</option>@endforeach</select></label>
        </div>
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>USER / ID</th><th>IP ADDRESS</th><th>TIME</th><th>LASTED</th><th>STATUS</th><th>BRUTE FORCE</th></tr></thead><tbody>
            @forelse ($events as $event)
                <tr>
                    <td><a href="{{ route('admin.security.auth-event', $event) }}" wire:navigate>{{ $event->account_name }}<small>{{ $event->account_type ? strtoupper($event->account_type) : 'UNKNOWN' }} · ID {{ $event->account_id ?? 'N' }}</small></a></td>
                    <td><code>{{ $event->ip_address }}</code></td>
                    <td>{{ $event->occurred_at?->format('M d, Y · H:i:s') }}</td>
                    <td>{{ $event->duration_ms }} ms</td>
                    <td><span class="security-status security-status--{{ $event->status }}">{{ strtoupper($event->status) }}</span></td>
                    <td><span class="security-boolean {{ $event->is_brute_force ? 'is-true' : 'is-false' }}">{{ $event->is_brute_force ? 'TRUE' : 'FALSE' }}</span></td>
                </tr>
            @empty
                <tr><td class="security-table-empty" colspan="6">No authentication events match the current filters.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-pagination">{{ $events->links() }}</div>
    </section>
</div>
