<div class="content-inner agency-staff-page" x-data="{ deleteModalOpen: false, passwordModalOpen: false, targetName: '' }" x-on:staff-delete-close.window="deleteModalOpen = false" x-on:staff-password-reset-close.window="passwordModalOpen = false">
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
            <form class="agency-staff-form__fields" data-staff-account-form>
                <label>Name <input type="text" wire:model="name" autocomplete="name"><small>@error('name'){{ $message }}@enderror</small></label>
                <label>Email <input type="email" wire:model="email" autocomplete="email"><small>@error('email'){{ $message }}@enderror</small></label>
                <label>Username <input type="text" wire:model="username" autocomplete="off"><small>@error('username'){{ $message }}@enderror</small></label>
                <div class="agency-staff-photo-picker" data-staff-photo-picker>
                    <label class="agency-staff-photo-picker__control">
                        <i class="ri ri-image-add-line" aria-hidden="true"></i>
                        <span>Choose profile image</span>
                        <small>JPG, PNG, GIF or WEBP · 5 MB max · held until account save</small>
                        <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" data-staff-photo-input>
                    </label>
                    <div class="agency-staff-photo-preview-frame" data-staff-photo-preview-frame>
                        @if ($profilePhoto)
                            <img class="agency-staff-photo-preview" src="{{ $profilePhoto->temporaryUrl() }}" alt="Selected staff profile picture" data-staff-photo-preview>
                        @else
                            <span class="agency-staff-photo-preview-placeholder" aria-label="No profile image selected"><i class="ri ri-user-3-line" aria-hidden="true"></i></span>
                        @endif
                    </div>
                    <small class="agency-staff-photo-uploading" data-staff-photo-upload-status hidden aria-live="polite">
                        <i class="ri ri-loader-4-line" data-staff-photo-upload-spinner aria-hidden="true"></i>
                        <span data-staff-photo-upload-message></span>
                    </small>
                    <small class="agency-staff-photo-error">@error('profilePhoto'){{ $message }}@enderror</small>
                </div>
                <label>Department
                    <select wire:model="departmentId">
                        @foreach ($departments as $availableDepartment)
                            <option value="{{ $availableDepartment->id }}">{{ $availableDepartment->name }} · {{ $availableDepartment->rank }}</option>
                        @endforeach
                    </select>
                </label>
                <label>{{ $editingId ? 'Set new password (optional)' : 'Initial password' }} <input type="password" wire:model="password" autocomplete="new-password"><small>@error('password'){{ $message }}@enderror</small></label>
                <div class="agency-staff-form__actions">
                    <button class="button-primary" type="submit" data-staff-submit-button wire:loading.attr="disabled" wire:target="save"><span data-staff-submit-label>{{ $editingId ? 'SAVE ACCOUNT' : 'CREATE ACCOUNT' }}</span><span data-staff-submit-loading hidden><i class="ri ri-loader-4-line" aria-hidden="true"></i> SAVING…</span></button>
                    @if ($editingId)<button class="button-secondary" type="button" wire:click="resetForm">CANCEL</button>@endif
                </div>
            </form>
        </section>

        <section class="glass-panel agency-staff-list">
            <div class="agency-staff-list__heading">
                <div class="agency-staff-accounts-heading"><div class="calc-panel-heading"><div><span class="section-kicker">AGENCY ACCOUNTS</span><h2>{{ $staffMembers->count() }} Staff</h2></div></div><a class="button-secondary" href="{{ route('admin.staff.inactive') }}" wire:navigate><i class="ri ri-user-unfollow-line" aria-hidden="true"></i> NON-ACTIVE ACCOUNTS</a></div>
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
                            @php($staffStatus = $staff->account_status === 'suspended' ? 'disabled' : ($staff->account_status ?? 'active'))
                            <small class="agency-staff-row__status">{{ $staff->departmentRecord?->rank_short ?? strtoupper($staff->department) }} · <span class="staff-status-indicator staff-status-indicator--{{ $staffStatus }}">{{ strtoupper($staffStatus) }}<i class="staff-status-indicator__dot" aria-hidden="true"></i></span></small>
                        </div>
                        <div class="agency-staff-row__actions">
                            <button class="button-secondary" type="button" wire:click="edit({{ $staff->id }})" wire:loading.attr="disabled" wire:target="edit({{ $staff->id }})"><span wire:loading.remove wire:target="edit({{ $staff->id }})">EDIT</span><span wire:loading wire:target="edit({{ $staff->id }})"><i class="ri ri-loader-4-line" aria-hidden="true"></i> LOADING…</span></button>
                            <button class="button-secondary" type="button" x-on:click="targetName = @js($staff->name); passwordModalOpen = true" wire:click="openPasswordReset({{ $staff->id }})" wire:loading.attr="disabled" wire:target="openPasswordReset({{ $staff->id }})">RESET PASSWORD</button>
                            <button class="button-danger" type="button" x-on:click="targetName = @js($staff->name); deleteModalOpen = true" wire:click="openDeleteConfirmation({{ $staff->id }})" wire:loading.attr="disabled" wire:target="openDeleteConfirmation({{ $staff->id }})">DELETE</button>
                        </div>
                    </article>
                @empty
                    <p class="agency-staff-empty">No department accounts match these filters.</p>
                @endforelse
            </div>
        </section>
    </div>

        <div class="agency-modal-backdrop" role="presentation" x-show="deleteModalOpen" x-cloak x-on:click.self="deleteModalOpen = false" wire:click.self="cancelDeleteConfirmation">
            <section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="delete-staff-title">
                <span class="section-kicker">ACCOUNT CONTROL</span>
                <h2 id="delete-staff-title">Delete staff account?</h2>
                <p>This will permanently remove <strong x-text="targetName">the selected staff member</strong> and revoke their access.</p>
                <div class="agency-modal__actions">
                    <button class="button-secondary" type="button" x-on:click="deleteModalOpen = false" wire:click="cancelDeleteConfirmation">CANCEL</button>
                    <button class="button-danger" type="button" wire:click="delete({{ $confirmingDeleteId ?? 0 }})" wire:loading.attr="disabled" wire:target="openDeleteConfirmation,delete">CONFIRM DELETE</button>
                </div>
            </section>
        </div>

        <div class="agency-modal-backdrop" x-show="passwordModalOpen" x-cloak x-on:click.self="passwordModalOpen = false" wire:click.self="cancelPasswordReset">
            <section class="agency-modal" role="dialog" aria-modal="true" aria-labelledby="reset-staff-password-title">
                <span class="section-kicker">CREDENTIAL CONTROL</span>
                <h2 id="reset-staff-password-title">Set a new password</h2>
                <p>Choose the password <strong x-text="targetName">the selected staff member</strong> will use to sign in.</p>
                <form wire:submit="resetPassword" class="agency-modal__form">
                    <label>New password <input type="password" wire:model="newPassword" autocomplete="new-password"><small>@error('newPassword'){{ $message }}@enderror</small></label>
                    <label>Confirm new password <input type="password" wire:model="newPasswordConfirmation" autocomplete="new-password"><small>@error('newPasswordConfirmation'){{ $message }}@enderror</small></label>
                    <div class="agency-modal__actions">
                        <button class="button-secondary" type="button" x-on:click="passwordModalOpen = false" wire:click="cancelPasswordReset">CANCEL</button>
                        <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="openPasswordReset,resetPassword"><span wire:loading.remove wire:target="resetPassword">UPDATE PASSWORD</span><span wire:loading wire:target="resetPassword">UPDATING…</span></button>
                    </div>
                </form>
            </section>
        </div>
</div>
