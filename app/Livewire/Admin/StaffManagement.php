<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Models\Department;
use App\Services\ActivityLogger;
use App\Services\StaffAccountStatusService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class StaffManagement extends Component
{
    use WithFileUploads;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $username = '';

    public string $departmentId = '';

    public string $password = '';

    public ?TemporaryUploadedFile $profilePhoto = null;

    public ?int $confirmingDeleteId = null;

    public ?int $passwordResetTargetId = null;

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    #[Url(as: 'search', history: true)]
    public string $search = '';

    public string $departmentFilter = '';

    public function mount(): void
    {
        $this->departmentId = (string) (Department::query()->where('code', 'cfm')->value('id') ?? '');
    }

    public function edit(int $id): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->editingId = $staff->id;
        $this->name = $staff->name;
        $this->email = $staff->email;
        $this->username = $staff->username ?? '';
        $this->departmentId = (string) $staff->department_id;
        $this->password = '';
        $this->profilePhoto = null;
        $this->resetValidation();
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'username', 'password', 'profilePhoto', 'confirmingDeleteId', 'passwordResetTargetId', 'newPassword', 'newPasswordConfirmation']);
        $this->departmentId = (string) (Department::query()->where('code', 'cfm')->value('id') ?? '');
        $this->resetValidation();
    }

    public function save(): void
    {
        $isCreating = $this->editingId === null;
        $staff = $this->editingId
            ? User::query()->whereNotNull('department')->findOrFail($this->editingId)
            : new User;

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($staff->id),
                Rule::unique('admins', 'email'),
            ],
            'username' => ['required', 'string', 'max:80', 'alpha_dash:ascii', Rule::unique('users', 'username')->ignore($staff->id)],
            'departmentId' => ['required', 'integer', Rule::exists('departments', 'id')],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:12'],
            'profilePhoto' => ['nullable', 'image', 'max:5120'],
        ]);

        $staff->name = trim($validated['name']);
        $staff->email = strtolower(trim($validated['email']));
        $staff->username = strtolower(trim($validated['username']));
        $department = Department::query()->findOrFail((int) $validated['departmentId']);
        $staff->department_id = $department->id;
        $staff->department = $department->code;
        if ($isCreating) {
            $staff->account_status = 'active';
        }
        if ($validated['password'] !== null && $validated['password'] !== '') {
            $staff->password = $validated['password'];
        }
        $staff->save();

        if ($this->profilePhoto !== null) {
            $previousPhotoPath = $staff->profile_photo_path;
            $photoPath = $this->profilePhoto->store('staff-profiles/'.$staff->id, 'vault');
            $staff->profile_photo_path = $photoPath;
            $staff->save();

            if ($previousPhotoPath !== null && $previousPhotoPath !== $photoPath) {
                Storage::disk('vault')->delete($previousPhotoPath);
            }
        }

        app(ActivityLogger::class)->record(
            'staff-management',
            $isCreating ? 'staff.created' : 'staff.updated',
            $isCreating ? "Staff account {$staff->username} was created." : "Staff account {$staff->username} was updated.",
            [
                'staff_id' => $staff->getKey(),
                'username' => $staff->username,
                'department_id' => $department->getKey(),
                'department_code' => $department->code,
                'account_status' => $staff->account_status,
                'profile_photo_changed' => $this->profilePhoto !== null,
            ],
        );
        $this->resetForm();
        $this->dispatch('alert', type: 'success', message: 'Staff account saved.');
        $this->dispatch('staff-account-saved');
    }

    public function resetPassword(): void
    {
        abort_if($this->passwordResetTargetId === null, 403);
        $validated = $this->validate([
            'newPassword' => ['required', 'string', 'min:12'],
            'newPasswordConfirmation' => ['required', 'string', 'same:newPassword'],
        ]);
        $staff = User::query()->whereNotNull('department')->findOrFail($this->passwordResetTargetId);
        $staff->password = $validated['newPassword'];
        $staff->save();
        app(ActivityLogger::class)->record(
            'staff-management',
            'staff.password-reset',
            "An administrator reset the password for {$staff->username}.",
            ['staff_id' => $staff->getKey(), 'username' => $staff->username],
        );
        $this->passwordResetTargetId = null;
        $this->reset(['newPassword', 'newPasswordConfirmation']);
        $this->dispatch('alert', type: 'success', message: 'The staff password was updated.');
        $this->dispatch('staff-password-reset-close');
    }

    public function openDeleteConfirmation(int $id): void
    {
        $this->confirmingDeleteId = User::query()->whereNotNull('department')->findOrFail($id)->id;
    }

    public function cancelDeleteConfirmation(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function openPasswordReset(int $id): void
    {
        $this->passwordResetTargetId = User::query()->whereNotNull('department')->findOrFail($id)->id;
        $this->reset(['newPassword', 'newPasswordConfirmation']);
        $this->resetValidation();
    }

    public function cancelPasswordReset(): void
    {
        $this->passwordResetTargetId = null;
        $this->reset(['newPassword', 'newPasswordConfirmation']);
        $this->resetValidation();
    }

    public function delete(int $id): void
    {
        abort_unless($this->confirmingDeleteId === $id, 403);
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        app(StaffAccountStatusService::class)->revokeSessions($staff);
        $staff->delete();
        app(ActivityLogger::class)->record(
            'staff-management',
            'staff.deleted',
            "Staff account {$staff->username} was deleted.",
            [
                'staff_id' => $staff->getKey(),
                'username' => $staff->username,
                'department_code' => $staff->department,
            ],
        );
        $this->confirmingDeleteId = null;
        $this->dispatch('alert', type: 'success', message: 'Staff account deleted.');
        $this->dispatch('staff-delete-close');
    }

    public function render(): View
    {
        return view('livewire.admin.staff-management', [
            'staffMembers' => User::query()
                ->with('departmentRecord')
                ->whereNotNull('department')
                ->when($this->departmentFilter !== '', fn ($query) => $query->where('department_id', $this->departmentFilter))
                ->when(trim($this->search) !== '', function ($query): void {
                    $search = '%'.trim($this->search).'%';
                    $query->where(function ($query) use ($search): void {
                        $query->where('name', 'like', $search)
                            ->orWhere('email', 'like', $search)
                            ->orWhere('username', 'like', $search);
                    });
                })
                ->orderBy('department_id')
                ->orderBy('name')
                ->get(),
            'departments' => Department::query()->orderBy('name')->get(),
        ])->extends('layouts.app', [
            'page_title' => 'Staff Management — '.config('app.name'),
            'page_label' => 'STAFF MANAGEMENT',
        ])->section('content');
    }
}
