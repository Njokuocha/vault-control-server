<div class="content-inner agency-inactive-staff-page">
    <div class="page-heading">
        <div>
            <span class="section-kicker">CENTRAL ADMINISTRATION · PERSONNEL CONTROL</span>
            <h1>Non-active accounts</h1>
            <p>Review frozen and disabled department accounts and restore access when authorized.</p>
        </div>
        <a class="button-secondary" href="{{ route('admin.staff.index') }}" wire:navigate>STAFF MANAGEMENT</a>
    </div>

    <section class="glass-panel agency-inactive-staff-panel">
        <div class="agency-inactive-staff-toolbar">
            <div class="agency-inactive-staff-tabs" role="tablist" aria-label="Non-active account status">
                <button class="{{ $statusFilter === 'frozen' ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $statusFilter === 'frozen' ? 'true' : 'false' }}" wire:click="$set('statusFilter', 'frozen')">
                    FROZEN <span>{{ $statusCounts['frozen'] ?? 0 }}</span>
                </button>
                <button class="{{ $statusFilter === 'disabled' ? 'is-active' : '' }}" type="button" role="tab" aria-selected="{{ $statusFilter === 'disabled' ? 'true' : 'false' }}" wire:click="$set('statusFilter', 'disabled')">
                    DISABLED <span>{{ $statusCounts['disabled'] ?? 0 }}</span>
                </button>
            </div>
            <span class="section-kicker">SELECT A PERSONNEL CARD TO CHANGE STATUS</span>
        </div>

        <div class="agency-inactive-staff-grid">
            @forelse ($staffMembers as $staff)
                <button class="agency-inactive-staff-card" type="button" data-account-status="{{ $staff->account_status === 'suspended' ? 'disabled' : $staff->account_status }}" data-status="{{ $staff->account_status === 'suspended' ? 'disabled' : ($staff->account_status ?? 'active') }}" data-freeze-hours="{{ $staff->account_status === 'frozen' && $staff->frozen_until?->isFuture() ? max(0.1, ceil(now()->diffInSeconds($staff->frozen_until) / 36) / 100) : 1 }}" x-on:click="$dispatch('staff-status-open', { status: $el.dataset.status, freezeHours: $el.dataset.freezeHours })" wire:click="openStatusModal({{ $staff->id }})" aria-label="Change status for {{ $staff->name }}">
                    @if ($staff->profile_photo_path)
                        <img class="agency-inactive-staff-card__portrait" src="{{ route('admin.staff.photo', $staff) }}" alt="">
                    @else
                        <span class="agency-inactive-staff-card__portrait agency-inactive-staff-card__portrait--initial" aria-hidden="true">{{ strtoupper(substr($staff->name, 0, 1)) }}</span>
                    @endif
                    <span class="staff-status-chip staff-status-chip--{{ $staff->account_status === 'suspended' ? 'disabled' : $staff->account_status }}">{{ strtoupper($staff->account_status === 'suspended' ? 'disabled' : $staff->account_status) }}</span>
                    <strong>{{ $staff->name }}</strong>
                    <small>{{ $staff->departmentRecord?->rank ?? 'Agency staff' }}</small>
                    <small>{{ $staff->username }}</small>
                    @if ($staff->account_status === 'frozen' && $staff->frozen_until)
                        <span class="staff-freeze-countdown" data-freeze-until="{{ $staff->frozen_until->getTimestamp() * 1000 }}" data-freeze-refresh-method="refreshFreezeExpiry">
                            <span>REMAINS</span><b data-freeze-countdown>CALCULATING…</b>
                        </span>
                    @endif
                    <span class="agency-inactive-staff-card__action">CHANGE STATUS <i class="ri ri-arrow-right-line" aria-hidden="true"></i></span>
                </button>
            @empty
                <div class="agency-inactive-staff-empty">
                    <i class="ri ri-shield-check-line" aria-hidden="true"></i>
                    <strong>No {{ $statusFilter }} accounts</strong>
                    <span>There are no department accounts in this status.</span>
                </div>
            @endforelse
        </div>
        <div class="security-pagination">{{ $staffMembers->links() }}</div>
    </section>

    @include('livewire.admin._staff-status-modal')
</div>
