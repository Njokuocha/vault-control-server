<div class="content-inner security-page">
    <div class="page-heading"><div><span class="section-kicker">AUTH SECURITY · SOURCE CONTROLS</span><h1>Blacklisted IP Addresses</h1><p>Automatic blocks expire after the retention period; manual blocks remain until removed.</p></div><div class="profile-heading-actions"><a class="button-secondary" href="{{ route('admin.security.whitelisted-ips') }}" wire:navigate>WHITELISTED IPS</a><button class="button-primary" type="button" wire:click="openAddModal">＋ ADD IP MANUALLY</button></div></div>
    <livewire:whitelist-ip-policy-toggle />
    <section class="glass-panel security-table-card">
        <div class="ip-source-legend"><span><i class="ip-source-dot ip-source-dot--automatic"></i>AUTOMATIC BLOCK</span><span><i class="ip-source-dot ip-source-dot--manual"></i>MANUAL BLOCK</span></div>
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>S/N</th><th>IP ADDRESS</th><th>TRIGGERED AT</th><th>FAILED ATTEMPTS</th><th>LAST ATTEMPT</th><th>REASON</th><th>ACTION</th></tr></thead><tbody>
            @forelse ($records as $record)
                <tr><td>{{ $records->firstItem() + $loop->index }}</td><td><code>{{ $record->ip_address }}</code></td><td>{{ $record->triggered_at?->format('M d, Y · H:i:s') ?? '—' }}</td><td>{{ $record->failed_attempts ?? 'Unknown' }}</td><td>{{ $record->last_attempt_at?->diffForHumans() ?? '—' }}</td><td>{{ $record->reason ?? 'Unknown' }}</td><td><span class="ip-source-dot ip-source-dot--{{ $record->source === 'manual' ? 'manual' : 'automatic' }}" title="{{ $record->source === 'manual' ? 'Manually added' : 'Automatically detected' }}" aria-label="{{ $record->source === 'manual' ? 'Manually added' : 'Automatically detected' }}"></span><button class="button-secondary security-delete-button" type="button" wire:click="confirmRemove({{ $record->id }})">UNBLOCK</button></td></tr>
            @empty
                <tr><td class="security-table-empty" colspan="7">No IP addresses are currently blacklisted.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-pagination">{{ $records->links() }}</div>
    </section>
    @if ($addModalOpen)
        <div class="agency-modal-backdrop"><section class="agency-modal whitelist-ip-modal" role="dialog" aria-modal="true" aria-labelledby="add-blacklist-ip-title"><span class="section-kicker">SOURCE CONTROL</span><h2 id="add-blacklist-ip-title">Manually blacklist IP</h2><p>Sign-in attempts from this address will be denied until it is unblocked.</p>
            <form wire:submit="add">
                <label for="blacklist-ip-address">IP ADDRESS</label>
                <input id="blacklist-ip-address" type="text" inputmode="url" autocomplete="off" placeholder="203.0.113.10 or IPv6" wire:model="ipAddress">
                @error('ipAddress')<small class="field-error">{{ $message }}</small>@enderror
                <label for="blacklist-ip-reason">REASON</label>
                <textarea id="blacklist-ip-reason" rows="3" maxlength="160" wire:model="reason"></textarea>
                @error('reason')<small class="field-error">{{ $message }}</small>@enderror
                <div class="agency-modal__actions"><button class="button-secondary" type="button" wire:click="closeAddModal">CANCEL</button><button class="button-danger" type="submit" wire:loading.attr="disabled" wire:target="add">BLACKLIST IP</button></div>
            </form>
        </section></div>
    @endif
    @if ($removeTarget)
        <div class="agency-modal-backdrop"><section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="remove-ip-title"><span class="section-kicker">SOURCE CONTROL</span><h2 id="remove-ip-title">Remove IP block?</h2><p>{{ $removeTarget->ip_address }} will be allowed to authenticate again.</p><div class="agency-modal__actions"><button class="button-secondary" wire:click="cancelRemove">CANCEL</button><button class="button-danger" wire:click="remove({{ $removeTarget->id }})">UNBLOCK IP</button></div></section></div>
    @endif
</div>
