<div class="content-inner agency-staff-page">
    <div class="page-heading">
        <div><span class="section-kicker">STAFF MANAGEMENT · IDENTITY DIRECTORY</span><h1>Staff <span>profiles</span></h1><p>Manage personnel identity records and provision staff access.</p></div>
        <span class="agency-status-tag"><i></i> STAFF MANAGER</span>
    </div>

    <div class="agency-staff-grid">
        <section class="glass-panel agency-staff-form">
            <div class="calc-panel-heading"><div><span class="section-kicker">{{ $editingId ? 'EDIT PROFILE' : 'NEW PROFILE' }}</span><h2>{{ $editingId ? 'Identity information' : 'Create staff account' }}</h2></div></div>
            <form class="agency-staff-form__fields" wire:submit="save">
                <label>Name <input type="text" wire:model="name" autocomplete="name"><small>@error('name'){{ $message }}@enderror</small></label>
                <label>Email <input type="email" wire:model="email" autocomplete="email"><small>@error('email'){{ $message }}@enderror</small></label>
                <label>Username <input type="text" wire:model="username" autocomplete="off"><small>@error('username'){{ $message }}@enderror</small></label>
                <label>Profile image <input type="file" wire:model="profilePhoto" accept="image/jpeg,image/png,image/gif,image/webp"><small>JPG, PNG, GIF or WEBP · 5 MB max · @error('profilePhoto'){{ $message }}@enderror</small></label>
                @if ($profilePhoto)
                    <img class="agency-staff-photo-preview" src="{{ $profilePhoto->temporaryUrl() }}" alt="Selected staff profile image">
                @endif
                @if (! $editingId)
                    <label>Department
                        <select wire:model="departmentId">
                            @foreach ($departments as $department)
                                <option value="{{ $department->id }}">{{ $department->name }} · {{ $department->rank }}</option>
                            @endforeach
                        </select>
                        <small>@error('departmentId'){{ $message }}@enderror</small>
                    </label>
                    <label>Initial password <input type="password" wire:model="password" autocomplete="new-password"><small>At least 12 characters. Staff Managers cannot reset it later. @error('password'){{ $message }}@enderror</small></label>
                @endif
                <div class="agency-staff-form__actions">
                    <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="save,profilePhoto"><span wire:loading.remove wire:target="save"> {{ $editingId ? 'SAVE PROFILE' : 'CREATE ACCOUNT' }}</span><span wire:loading wire:target="save"><i class="ri ri-loader-4-line" aria-hidden="true"></i> SAVING…</span></button>
                    @if ($editingId)<button class="button-secondary" type="button" wire:click="resetForm">CANCEL</button>@endif
                </div>
            </form>
        </section>

        <section class="glass-panel agency-staff-list">
            <div class="agency-staff-list__heading">
                <div class="agency-staff-accounts-heading"><div class="calc-panel-heading"><div><span class="section-kicker">AGENCY ACCOUNTS</span><h2>{{ $staffMembers->count() }} Staff</h2></div></div></div>
                <div class="agency-staff-filters">
                    <label><span>SEARCH STAFF</span><span class="agency-staff-search-field"><input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, username, or email"><i class="agency-staff-search-spinner" wire:loading wire:target="search" aria-label="Filtering staff" role="status"></i></span></label>
                    <label><span>DEPARTMENT</span><select wire:model.live="departmentFilter"><option value="">All departments</option>@foreach ($departments as $department)<option value="{{ $department->id }}">{{ $department->name }}</option>@endforeach</select></label>
                </div>
            </div>
            <div class="agency-staff-directory">
                @forelse ($staffMembers as $staff)
                    <article class="agency-staff-row">
                        <a class="agency-staff-row__photo-link" href="{{ route('sm.staff.show', $staff) }}" wire:navigate aria-label="View {{ $staff->name }}'s profile">
                            @if ($staff->profile_photo_path)<img class="agency-staff-row__avatar" src="{{ route('sm.staff.photo', $staff) }}" alt="">@else<div class="agency-staff-row__avatar" aria-hidden="true">{{ strtoupper(substr($staff->name, 0, 1)) }}</div>@endif
                        </a>
                        <a class="agency-staff-row__identity" href="{{ route('sm.staff.show', $staff) }}" wire:navigate>
                            <strong>{{ $staff->name }}</strong><span>{{ $staff->username }} · {{ $staff->email }}</span>
                            @php($staffStatus = $staff->account_status === 'suspended' ? 'disabled' : ($staff->account_status ?? 'active'))
                            <small class="agency-staff-row__status">{{ $staff->departmentRecord?->rank_short ?? strtoupper($staff->department) }} · <span class="staff-status-indicator staff-status-indicator--{{ $staffStatus }}">{{ strtoupper($staffStatus) }}<i class="staff-status-indicator__dot" aria-hidden="true"></i></span></small>
                        </a>
                        <div class="agency-staff-row__actions"><button class="button-secondary" type="button" wire:click="edit({{ $staff->id }})" wire:loading.attr="disabled">EDIT IDENTITY</button></div>
                    </article>
                @empty
                    <p class="agency-staff-empty">No staff accounts match these filters.</p>
                @endforelse
            </div>
        </section>
    </div>
</div>
