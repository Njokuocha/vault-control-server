<div class="agency-modal-backdrop agency-status-modal-backdrop" role="presentation" x-data="{ open: false, status: @js($statusSelection), freezeHours: @js($freezeHours) }" x-show="open" x-cloak x-on:staff-status-open.window="open = true; status = $event.detail.status; freezeHours = $event.detail.freezeHours" x-on:staff-status-close.window="open = false" x-on:click.self="open = false" wire:click.self="cancelStatusModal">
    <section class="agency-modal agency-staff-status-modal" role="dialog" aria-modal="true" aria-labelledby="staff-status-modal-title">
        <button class="agency-staff-status-modal__close" type="button" x-on:click="open = false" wire:click="cancelStatusModal" aria-label="Close status window">×</button>
        <span class="section-kicker">PERSONNEL ACCESS CONTROL</span>
        <h2 id="staff-status-modal-title">Change account status</h2>
        <p>Set the access state for <strong>{{ $statusTarget?->name ?? 'the selected staff member' }}</strong>.</p>

        <form wire:submit="saveStatus" class="agency-modal__form">
            <label>Account status
                <select class="agency-staff-status-select" x-model="status" wire:model="statusSelection">
                    <option value="active">Active — normal access</option>
                    <option value="frozen">Freeze — temporarily block access</option>
                    <option value="disabled">Disabled — block access until changed</option>
                </select>
                <small>@error('statusSelection'){{ $message }}@enderror</small>
            </label>
            <div class="agency-staff-freeze-field" x-show="status === 'frozen'" x-cloak>
                <label>
                    <span class="agency-staff-freeze-field__label">
                        <span>Freeze duration in hours</span>
                        <small x-text="Number(freezeHours) >= 0.1 ? `Roughly ${Math.round(Number(freezeHours) * 60)} minutes.` : 'Enter at least 0.1 hours.'"></small>
                    </span>
                    <input type="text" inputmode="decimal" min="0.1" max="{{ \App\Services\StaffAccountStatusService::MAX_FREEZE_HOURS }}" step="0.01" x-model="freezeHours" wire:model="freezeHours" data-freeze-hours-input placeholder="0.1">
                    <small>Minimum: 0.1 hours. Maximum: 8,760 hours (one year).</small>
                    <small>@error('freezeHours'){{ $message }}@enderror</small>
                </label>
            </div>
            <div class="agency-staff-status-submission" wire:loading wire:target="saveStatus" role="status" aria-live="polite">
                <i class="ri ri-loader-4-line" aria-hidden="true"></i>
                <span>Applying account status… Please wait.</span>
            </div>
            <div class="agency-modal__actions">
                <button class="button-secondary" type="button" x-on:click="open = false" wire:click="cancelStatusModal" wire:loading.attr="disabled" wire:target="saveStatus">CANCEL</button>
                <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveStatus">
                    <span wire:loading.remove wire:target="saveStatus">UPDATE STATUS</span>
                    <span wire:loading wire:target="saveStatus"><i class="ri ri-loader-4-line" aria-hidden="true"></i> UPDATING…</span>
                </button>
            </div>
        </form>
    </section>
</div>
