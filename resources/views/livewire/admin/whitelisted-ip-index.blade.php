<div class="content-inner security-page">
    <div class="page-heading">
        <div><span class="section-kicker">AUTH SECURITY · SOURCE CONTROLS</span><h1>Whitelisted IP Addresses</h1><p>Maintain trusted source addresses for optional whitelist-only authentication.</p></div>
        <div class="profile-heading-actions"><a class="button-secondary" href="{{ route('admin.security.blacklisted-ips') }}" wire:navigate>BLACKLISTED IPS</a><button class="button-primary" type="button" wire:click="openAddModal">＋ ADD IP ADDRESS</button></div>
    </div>

    <livewire:whitelist-ip-policy-toggle />

    <section class="glass-panel security-table-card">
        <div class="security-table-wrap"><table class="security-table"><thead><tr><th>S/N</th><th>IP ADDRESS</th><th>ADDED</th><th>ACTION</th></tr></thead><tbody>
            @forelse ($records as $record)
                <tr><td>{{ $records->firstItem() + $loop->index }}</td><td><code>{{ $record->ip_address }}</code></td><td>{{ $record->created_at?->format('M d, Y · H:i:s') ?? 'Unknown' }}</td><td><button class="button-secondary security-delete-button" type="button" wire:click="confirmRemove({{ $record->id }})">REMOVE</button></td></tr>
            @empty
                <tr><td class="security-table-empty" colspan="4">No IP addresses are whitelisted. Blacklist enforcement remains active.</td></tr>
            @endforelse
        </tbody></table></div>
        <div class="security-pagination">{{ $records->links() }}</div>
    </section>

    @if ($addModalOpen)
        <div class="agency-modal-backdrop"><section class="agency-modal whitelist-ip-modal" role="dialog" aria-modal="true" aria-labelledby="add-whitelist-ip-title"><span class="section-kicker">TRUSTED SOURCE CONTROL</span><h2 id="add-whitelist-ip-title">Add trusted IP address</h2><p>Only add an address you control. When whitelist-only authentication is enabled, this address can sign in.</p>
            <form wire:submit="add">
                <label for="whitelist-ip-address">IP ADDRESS</label>
                <input id="whitelist-ip-address" type="text" inputmode="url" autocomplete="off" placeholder="203.0.113.10 or IPv6" wire:model="ipAddress">
                @error('ipAddress')<small class="field-error">{{ $message }}</small>@enderror
                <div class="agency-modal__actions"><button class="button-secondary" type="button" wire:click="closeAddModal" wire:loading.attr="disabled" wire:target="add">CANCEL</button><button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="add"><span wire:loading.remove wire:target="add">ADD IP</span><span wire:loading wire:target="add"><i class="ri ri-loader-4-line" aria-hidden="true"></i> ADDING…</span></button></div>
            </form>
        </section></div>
    @endif

    @if ($removeTarget)
        <div class="agency-modal-backdrop"><section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="remove-whitelist-ip-title"><span class="section-kicker">TRUSTED SOURCE CONTROL</span><h2 id="remove-whitelist-ip-title">Remove trusted IP?</h2><p>{{ $removeTarget->ip_address }} will no longer be on the whitelist.</p><div class="agency-modal__actions"><button class="button-secondary" type="button" wire:click="cancelRemove" wire:loading.attr="disabled" wire:target="remove">CANCEL</button><button class="button-danger" type="button" wire:click="remove({{ $removeTarget->id }})" wire:loading.attr="disabled" wire:target="remove"><span wire:loading.remove wire:target="remove">REMOVE IP</span><span wire:loading wire:target="remove"><i class="ri ri-loader-4-line" aria-hidden="true"></i> REMOVING…</span></button></div></section></div>
    @endif
</div>
