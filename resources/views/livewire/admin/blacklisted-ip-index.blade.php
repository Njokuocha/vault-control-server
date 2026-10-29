<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">AUTH SECURITY · SOURCE CONTROLS</span><h1>Blacklisted IP Addresses</h1><p>Addresses are blocked after the configured failure threshold and automatically expire from this list after the retention period.</p></div><a class="button-secondary" href="{{ route('admin.security.index') }}" wire:navigate>AUTH SECURITY</a></div>
    <section class="glass-panel security-table-card">
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>S/N</th><th>IP ADDRESS</th><th>TRIGGERED AT</th><th>FAILED ATTEMPTS</th><th>LAST ATTEMPT</th><th>REASON</th><th>ACTION</th></tr></thead><tbody>
            @forelse ($records as $record)
                <tr><td>{{ $records->firstItem() + $loop->index }}</td><td><code>{{ $record->ip_address }}</code></td><td>{{ $record->triggered_at?->format('M d, Y · H:i:s') }}</td><td>{{ $record->failed_attempts }}</td><td>{{ $record->last_attempt_at?->diffForHumans() ?? '—' }}</td><td>{{ $record->reason }}</td><td><button class="button-secondary security-delete-button" type="button" wire:click="confirmRemove({{ $record->id }})">UNBLOCK</button></td></tr>
            @empty
                <tr><td class="security-table-empty" colspan="7">No IP addresses are currently blacklisted.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-pagination">{{ $records->links() }}</div>
    </section>
    @if ($removeTarget)
        <div class="agency-modal-backdrop"><section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="remove-ip-title"><span class="section-kicker">SOURCE CONTROL</span><h2 id="remove-ip-title">Remove IP block?</h2><p>{{ $removeTarget->ip_address }} will be allowed to authenticate again.</p><div class="agency-modal__actions"><button class="button-secondary" wire:click="cancelRemove">CANCEL</button><button class="button-danger" wire:click="remove({{ $removeTarget->id }})">UNBLOCK IP</button></div></section></div>
    @endif
</div>
