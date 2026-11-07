<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">AUTH SECURITY · THREAT RECORDS</span><h1>Brute-Force Attempts</h1><p>Repeated authentication failures are grouped by source IP, independent of the account identifier supplied.</p></div><a class="button-secondary" href="{{ route('admin.security.index') }}" wire:navigate>AUTH SECURITY</a></div>
    <section class="glass-panel security-table-card">
        <div class="security-filter-bar security-filter-bar--activity"><label><span>SEARCH INCIDENTS</span><input type="search" wire:model.live.debounce.250ms="search" placeholder="Account, ID, IP address"></label></div>
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>ACCOUNT</th><th>IP ADDRESS</th><th>FAILED COUNT</th><th>WINDOW</th><th>DETECTED</th><th>RECORD</th><th>ACTION</th></tr></thead><tbody>
            @forelse ($records as $record)
                <tr>
                    <td><a href="{{ route('admin.security.brute-force-detail', $record) }}" wire:navigate>{{ $record->account_name }}<small>ID {{ $record->account_id ?? 'N' }}</small></a></td>
                    <td><code>{{ $record->ip_address }}</code></td>
                    <td>{{ $record->failed_attempts }}</td>
                    <td>{{ $record->window_seconds }} sec</td>
                    <td>{{ $record->detected_at?->format('M d, Y · H:i:s') }}</td>
                    <td><a class="text-action" href="{{ route('admin.security.brute-force-detail', $record) }}" wire:navigate>OPEN ↗</a></td>
                    <td><button class="button-danger security-delete-button" type="button" wire:click="confirmDelete({{ $record->id }})">DELETE</button></td>
                </tr>
            @empty
                <tr><td class="security-table-empty" colspan="7">No brute-force incidents are recorded for the retained period.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-pagination">{{ $records->links() }}</div>
    </section>
    @if ($deleteTarget)
        <div class="agency-modal-backdrop"><section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="delete-brute-title"><span class="section-kicker">INCIDENT CONTROL</span><h2 id="delete-brute-title">Delete this record?</h2><p>The event will be removed from the brute-force register. Authentication history remains available.</p><div class="agency-modal__actions"><button class="button-secondary" wire:click="cancelDelete">CANCEL</button><button class="button-danger" wire:click="delete({{ $deleteTarget->id }})">DELETE RECORD</button></div></section></div>
    @endif
</div>
