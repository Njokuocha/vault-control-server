<div class="content-inner agency-staff-page">
    <div class="page-heading">
        <div>
            <span class="section-kicker">AGENCY STRUCTURE · {{ $department->rank_short }}</span>
            <h1>{{ $department->name }} <span>staff</span></h1>
            <p>{{ $department->rank }} · {{ $staffMembers->total() }} assigned staff account{{ $staffMembers->total() === 1 ? '' : 's' }}</p>
        </div>
        <a class="button-secondary" href="{{ route('admin.departments.index') }}" wire:navigate>DEPARTMENTS</a>
    </div>

    <section class="glass-panel agency-department-staff-panel">
        <label class="agency-department-staff-search">
            <span>SEARCH DEPARTMENT STAFF</span>
            <input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, username, or email">
        </label>

        <div class="agency-staff-directory agency-department-staff-directory">
            @forelse ($staffMembers as $staff)
                <article class="agency-staff-row">
                    <a class="agency-staff-row__photo-link" href="{{ route('admin.staff.show', $staff) }}" wire:navigate aria-label="View {{ $staff->name }} profile">
                        @if ($staff->profile_photo_path)
                            <img class="agency-staff-row__avatar" src="{{ route('admin.staff.photo', $staff) }}" alt="{{ $staff->name }} profile picture">
                        @else
                            <span class="agency-staff-row__avatar">{{ strtoupper(substr($staff->name, 0, 1)) }}</span>
                        @endif
                    </a>
                    <div class="agency-staff-row__identity">
                        <a href="{{ route('admin.staff.show', $staff) }}" wire:navigate><strong>{{ $staff->name }}</strong></a>
                        <span>{{ $staff->username }} · {{ $staff->email }}</span>
                        <small>{{ strtoupper($staff->account_status ?? 'active') }}</small>
                    </div>
                    <div class="agency-staff-row__actions">
                        <a class="button-secondary" href="{{ route('admin.staff.show', $staff) }}" wire:navigate>VIEW PROFILE</a>
                    </div>
                </article>
            @empty
                <p class="agency-staff-empty">No staff accounts were found in this department.</p>
            @endforelse
        </div>

        <div class="security-pagination">{{ $staffMembers->links() }}</div>
    </section>
</div>
