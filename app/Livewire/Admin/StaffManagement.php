<?php

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    public string $department = 'cfm';

    public string $password = '';

    public string $accountStatus = 'active';

    public ?TemporaryUploadedFile $profilePhoto = null;

    public ?int $confirmingDeleteId = null;

    public ?int $confirmingPasswordResetId = null;

    public ?string $temporaryPassword = null;

    public function edit(int $id): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->editingId = $staff->id;
        $this->name = $staff->name;
        $this->email = $staff->email;
        $this->username = $staff->username ?? '';
        $this->department = $staff->department;
        $this->accountStatus = $staff->account_status;
        $this->password = '';
        $this->profilePhoto = null;
        $this->resetValidation();
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'email', 'username', 'password', 'profilePhoto', 'confirmingDeleteId', 'confirmingPasswordResetId']);
        $this->department = 'cfm';
        $this->accountStatus = 'active';
        $this->resetValidation();
    }

    public function save(): void
    {
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
            'department' => ['required', Rule::in(['cfm', 'pm', 'fm'])],
            'accountStatus' => ['required', Rule::in(['active', 'suspended', 'disabled'])],
            'password' => [$this->editingId ? 'nullable' : 'required', 'string', 'min:12'],
            'profilePhoto' => ['nullable', 'image', 'max:5120'],
        ]);

        $staff->name = trim($validated['name']);
        $staff->email = strtolower(trim($validated['email']));
        $staff->username = strtolower(trim($validated['username']));
        $staff->department = $validated['department'];
        $staff->account_status = $validated['accountStatus'];
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

        $this->resetForm();
        $this->dispatch('alert', type: 'success', message: 'Staff account saved.');
    }

    public function resetPassword(int $id): void
    {
        abort_unless($this->confirmingPasswordResetId === $id, 403);
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->temporaryPassword = Str::password(18);
        $staff->password = $this->temporaryPassword;
        $staff->save();
        $this->confirmingPasswordResetId = null;
        $this->dispatch('alert', type: 'success', message: 'A replacement password was generated. Copy it now; it will not be shown again.');
    }

    public function toggleDeleteConfirmation(int $id): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->confirmingDeleteId = $this->confirmingDeleteId === $staff->id ? null : $staff->id;
    }

    public function togglePasswordResetConfirmation(int $id): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->confirmingPasswordResetId = $this->confirmingPasswordResetId === $staff->id ? null : $staff->id;
    }

    public function delete(int $id): void
    {
        abort_unless($this->confirmingDeleteId === $id, 403);
        User::query()->whereNotNull('department')->findOrFail($id)->delete();
        $this->confirmingDeleteId = null;
        $this->dispatch('alert', type: 'success', message: 'Staff account deleted.');
    }

    public function render(): View
    {
        return view('livewire.admin.staff-management', [
            'staffMembers' => User::query()->whereNotNull('department')->orderBy('department')->orderBy('name')->get(),
        ])->extends('layouts.app', [
            'page_title' => 'Staff Management — '.config('app.name'),
            'page_label' => 'STAFF MANAGEMENT',
        ])->section('content');
    }
}
