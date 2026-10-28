<div class="content-inner agency-staff-page">
    <div class="page-heading">
        <div><span class="section-kicker">CENTRAL ADMINISTRATION</span><h1>Staff Management</h1><p>Create department accounts and control staff access.</p></div>
        <span class="agency-status-tag"><i></i> ADMIN CONTROL</span>
    </div>

    <div class="agency-staff-grid">
        <section class="glass-panel agency-staff-form">
            <div class="calc-panel-heading"><div><span class="section-kicker">{{ $editingId ? 'EDIT ACCOUNT' : 'NEW ACCOUNT' }}</span><h2>{{ $editingId ? 'Staff credentials' : 'Create staff account' }}</h2></div></div>
            <form wire:submit="save" class="agency-staff-form__fields">
                <label>Name <input type="text" wire:model="name" autocomplete="name"><small>@error('name'){{ $message }}@enderror</small></label>
                <label>Email <input type="email" wire:model="email" autocomplete="email"><small>@error('email'){{ $message }}@enderror</small></label>
                <label>Username <input type="text" wire:model="username" autocomplete="off"><small>@error('username'){{ $message }}@enderror</small></label>
                <label>Profile picture <input type="file" wire:model="profilePhoto" accept="image/jpeg,image/png,image/gif,image/webp"><small>@error('profilePhoto'){{ $message }}@enderror</small></label>
                @if ($profilePhoto)
                    <img class="agency-staff-photo-preview" src="{{ $profilePhoto->temporaryUrl() }}" alt="Selected staff profile picture">
                @endif
                <label>Department
                    <select wire:model="department">
                        <option value="cfm">Case File Management</option>
                        <option value="pm">Profile Management</option>
                        <option value="fm">File Management</option>
                    </select>
                </label>
                @if ($editingId)
                    <label>Account status
                        <select wire:model="accountStatus">
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                            <option value="disabled">Disabled</option>
                        </select>
                    </label>
                @endif
                <label>{{ $editingId ? 'Set new password (optional)' : 'Initial password' }} <input type="password" wire:model="password" autocomplete="new-password"><small>@error('password'){{ $message }}@enderror</small></label>
                <div class="agency-staff-form__actions">
                    <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="save"><span wire:loading.remove wire:target="save">{{ $editingId ? 'SAVE ACCOUNT' : 'CREATE ACCOUNT' }}</span><span wire:loading wire:target="save">SAVING…</span></button>
                    @if ($editingId)<button class="button-secondary" type="button" wire:click="resetForm">CANCEL</button>@endif
                </div>
            </form>
            @if ($temporaryPassword)
                <div class="agency-temp-password" role="status"><strong>ONE-TIME REPLACEMENT PASSWORD</strong><code>{{ $temporaryPassword }}</code><button type="button" class="button-secondary" x-on:click="navigator.clipboard.writeText(@js($temporaryPassword)).then(() => window.vaultAlert({ type: 'success', message: 'Temporary password copied.' }))">COPY PASSWORD</button></div>
            @endif
        </section>

        <section class="glass-panel agency-staff-list">
            <div class="calc-panel-heading"><div><span class="section-kicker">AGENCY ACCOUNTS</span><h2>{{ $staffMembers->count() }} Staff</h2></div></div>
            @forelse ($staffMembers as $staff)
                <article class="agency-staff-row">
                    @if ($staff->profile_photo_path)
                        <img class="agency-staff-row__avatar" src="{{ route('admin.staff.photo', $staff) }}" alt="{{ $staff->name }} profile picture">
                    @else
                        <div class="agency-staff-row__avatar">{{ strtoupper(substr($staff->name, 0, 1)) }}</div>
                    @endif
                    <div class="agency-staff-row__identity"><strong>{{ $staff->name }}</strong><span>{{ $staff->username }} · {{ $staff->email }}</span><small>{{ strtoupper($staff->department) }} · {{ strtoupper($staff->account_status) }}</small></div>
                    <div class="agency-staff-row__actions">
                        <button class="button-secondary" type="button" wire:click="edit({{ $staff->id }})">EDIT</button>
                        @if ($confirmingPasswordResetId === $staff->id)
                            <button class="button-danger" type="button" wire:click="resetPassword({{ $staff->id }})">CONFIRM RESET</button>
                            <button class="button-secondary" type="button" wire:click="togglePasswordResetConfirmation({{ $staff->id }})">CANCEL</button>
                        @else
                            <button class="button-secondary" type="button" wire:click="togglePasswordResetConfirmation({{ $staff->id }})">RESET PASSWORD</button>
                        @endif
                        @if ($confirmingDeleteId === $staff->id)
                            <button class="button-danger" type="button" wire:click="delete({{ $staff->id }})">CONFIRM DELETE</button>
                            <button class="button-secondary" type="button" wire:click="toggleDeleteConfirmation({{ $staff->id }})">CANCEL</button>
                        @else
                            <button class="button-danger" type="button" wire:click="toggleDeleteConfirmation({{ $staff->id }})">DELETE</button>
                        @endif
                    </div>
                </article>
            @empty
                <p class="agency-staff-empty">No department accounts have been created.</p>
            @endforelse
        </section>
    </div>
</div>
