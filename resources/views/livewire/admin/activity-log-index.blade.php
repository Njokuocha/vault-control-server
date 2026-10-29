<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">AUTH SECURITY · AGENCY-WIDE</span><h1>Activity Logs</h1><p>Auditable record of significant events across administration and departments. Logs older than 14 days are removed by scheduled cleanup.</p></div><a class="button-secondary" href="{{ route('admin.security.index') }}" wire:navigate>AUTH SECURITY</a></div>
    <section class="glass-panel security-table-card">
        <div class="security-filter-bar security-filter-bar--activity">
            <label><span>SEARCH ACTIVITY</span><input type="search" wire:model.live.debounce.250ms="search" placeholder="Actor, event, category, IP"></label>
            <label><span>FROM MONTH</span><select wire:model.live="fromMonth"><option value="">All months</option>@foreach ($months as $month)<option value="{{ $month['value'] }}">{{ $month['label'] }}</option>@endforeach</select></label>
            <label><span>TO MONTH</span><select wire:model.live="toMonth"><option value="">Current and earlier</option>@foreach ($months as $month)<option value="{{ $month['value'] }}">{{ $month['label'] }}</option>@endforeach</select></label>
        </div>
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>TIME</th><th>ACTOR</th><th>ACTIVITY</th><th>CATEGORY</th><th>IP ADDRESS</th></tr></thead><tbody>
            @forelse ($logs as $log)
                <tr>
                    <td><a href="{{ route('admin.security.activity-detail', $log) }}" wire:navigate>{{ $log->occurred_at?->format('M d, Y · H:i:s') }}</a></td>
                    <td>{{ $log->actor_name }}<small>{{ strtoupper($log->actor_type ?? 'SYSTEM') }} · ID {{ $log->actor_id ?? 'N' }}</small></td>
                    <td><a href="{{ route('admin.security.activity-detail', $log) }}" wire:navigate>{{ $log->summary }}</a></td>
                    <td><span class="security-category">{{ strtoupper($log->category) }}</span><small>{{ $log->action }}</small></td>
                    <td><code>{{ $log->ip_address ?? 'SYSTEM' }}</code></td>
                </tr>
            @empty
                <tr><td class="security-table-empty" colspan="5">No activity matches the current filters.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-pagination">{{ $logs->links() }}</div>
    </section>
</div>
