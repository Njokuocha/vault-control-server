<div class="content-inner agency-staff-page">
    <div class="page-heading">
        <div><span class="section-kicker">CENTRAL ADMINISTRATION</span><h1>Staff Management</h1><p>Create department accounts and control staff access.</p></div>
        <div class="agency-staff-heading-actions">
            <a class="button-secondary" href="{{ route('admin.departments.index') }}" wire:navigate>MANAGE DEPARTMENTS</a>
            <span class="agency-status-tag"><i></i> ADMIN CONTROL</span>
        </div>
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
                    <select wire:model="departmentId">
                        @foreach ($departments as $availableDepartment)
                            <option value="{{ $availableDepartment->id }}">{{ $availableDepartment->name }} · {{ $availableDepartment->rank }}</option>
                        @endforeach
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
        </section>

        <section class="glass-panel agency-staff-list">
            <div class="agency-staff-list__heading">
                <div class="calc-panel-heading"><div><span class="section-kicker">AGENCY ACCOUNTS</span><h2>{{ $staffMembers->count() }} Staff</h2></div></div>
                <div class="agency-staff-filters">
                    <label><span>SEARCH STAFF</span><input type="search" wire:model.live.debounce.250ms="search" placeholder="Name, username, or email"></label>
                    <label><span>DEPARTMENT</span><select wire:model.live="departmentFilter"><option value="">All departments</option>@foreach ($departments as $availableDepartment)<option value="{{ $availableDepartment->id }}">{{ $availableDepartment->name }}</option>@endforeach</select></label>
                </div>
            </div>
            <div class="agency-staff-directory">
                @forelse ($staffMembers as $staff)
                    <article class="agency-staff-row">
                        <a class="agency-staff-row__photo-link" href="{{ route('admin.staff.show', $staff) }}" wire:navigate aria-label="View {{ $staff->name }}'s profile">
                            @if ($staff->profile_photo_path)
                                <img class="agency-staff-row__avatar" src="{{ route('admin.staff.photo', $staff) }}" alt="">
                            @else
                                <div class="agency-staff-row__avatar" aria-hidden="true">{{ strtoupper(substr($staff->name, 0, 1)) }}</div>
                            @endif
                        </a>
                        <div class="agency-staff-row__identity">
                            <strong>{{ $staff->name }}</strong>
                            <span>{{ $staff->username }} · {{ $staff->email }}</span>
                            <small>{{ $staff->departmentRecord?->rank_short ?? strtoupper($staff->department) }} · {{ strtoupper($staff->account_status) }}</small>
                        </div>
                        <div class="agency-staff-row__actions">
                            <button class="button-secondary" type="button" wire:click="edit({{ $staff->id }})">EDIT</button>
                            <button class="button-secondary" type="button" wire:click="openPasswordReset({{ $staff->id }})">RESET PASSWORD</button>
                            <button class="button-danger" type="button" wire:click="openDeleteConfirmation({{ $staff->id }})">DELETE</button>
                        </div>
                    </article>
                @empty
                    <p class="agency-staff-empty">No department accounts match these filters.</p>
                @endforelse
            </div>
        </section>
    </div>

    @if ($deleteTarget)
        <div class="agency-modal-backdrop" role="presentation" wire:click.self="cancelDeleteConfirmation">
            <section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="delete-staff-title">
                <span class="section-kicker">ACCOUNT CONTROL</span>
                <h2 id="delete-staff-title">Delete staff account?</h2>
                <p>This will permanently remove <strong>{{ $deleteTarget->name }}</strong> and revoke their access.</p>
                <div class="agency-modal__actions">
                    <button class="button-secondary" type="button" wire:click="cancelDeleteConfirmation">CANCEL</button>
                    <button class="button-danger" type="button" wire:click="delete({{ $deleteTarget->id }})" wire:loading.attr="disabled" wire:target="delete">CONFIRM DELETE</button>
                </div>
            </section>
        </div>
    @endif

    @if ($passwordResetTarget)
        <div class="agency-modal-backdrop">
            <section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="reset-staff-password-title">
                <span class="section-kicker">CREDENTIAL CONTROL</span>
                <h2 id="reset-staff-password-title">Set a new password</h2>
                <p>Choose the password <strong>{{ $passwordResetTarget->name }}</strong> will use to sign in.</p>
                <form wire:submit="resetPassword" class="agency-modal__form">
                    <label>New password <input type="password" wire:model="newPassword" autocomplete="new-password"><small>@error('newPassword'){{ $message }}@enderror</small></label>
                    <label>Confirm new password <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password"><small>@error('newPasswordConfirmation'){{ $message }}@enderror</small></label>
                    <div class="agency-modal__actions">
                        <button class="button-secondary" type="button" wire:click="cancelPasswordReset">CANCEL</button>
                        <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="resetPassword"><span wire:loading.remove wire:target="resetPassword">UPDATE PASSWORD</span><span wire:loading wire:target="resetPassword">UPDATING…</span></button>
                    </div>
                </form>
            </section>
        </div>
    @endif
</div>
