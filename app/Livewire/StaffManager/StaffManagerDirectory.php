<?php

namespace App\Livewire\StaffManager;

use App\Models\Department;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class StaffManagerDirectory extends Component
{
    use WithFileUploads;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $username = '';

    public string $departmentId = '';

    public string $password = '';

    public ?TemporaryUploadedFile $profilePhoto = null;

    #[Url(as: 'search', history: true)]
    public string $search = '';

    public string $departmentFilter = '';

    public function mount(): void
    {
        $this->authorizeStaffManager();
        $this->departmentId = (string) (Department::query()->where('code', 'cfm')->value('id') ?? '');
    }

    public function edit(int $id): void
    {
        $this->authorizeStaffManager();
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->editingId = $staff->getKey();
        $this->name = $staff->name;
        $this->email = $staff->email;
        $this->username = $staff->username ?? '';
        $this->profilePhoto = null;
        $this->resetValidation();
    }

    public function resetForm(): void
    {
        $this->authorizeStaffManager();
        $this->reset(['editingId', 'name', 'email', 'username', 'password', 'profilePhoto']);
        $this->departmentId = (string) (Department::query()->where('code', 'cfm')->value('id') ?? '');
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->authorizeStaffManager();
        $creating = $this->editingId === null;
        $staff = $creating
            ? new User
            : User::query()->whereNotNull('department')->findOrFail($this->editingId);
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
            'departmentId' => [$creating ? 'required' : 'nullable', 'integer', Rule::exists('departments', 'id')->whereNot('code', 'sm')],
            'password' => $creating ? ['required', 'string', 'min:12'] : ['prohibited'],
            'profilePhoto' => ['nullable', 'image', 'max:5120'],
        ]);

        $staff->name = trim($validated['name']);
        $staff->email = strtolower(trim($validated['email']));
        $staff->username = strtolower(trim($validated['username']));
        if ($creating) {
            $department = Department::query()->findOrFail((int) $validated['departmentId']);
            $staff->department_id = $department->getKey();
            $staff->department = $department->code;
            $staff->account_status = 'active';
            $staff->password = $validated['password'];
        } else {
            $department = $staff->departmentRecord;
        }
        $staff->save();

        if ($this->profilePhoto !== null) {
            $previousPhotoPath = $staff->profile_photo_path;
            $staff->profile_photo_path = $this->profilePhoto->store('staff-profiles/'.$staff->getKey(), 'vault');
            $staff->save();
            if ($previousPhotoPath !== null && $previousPhotoPath !== $staff->profile_photo_path) {
                Storage::disk('vault')->delete($previousPhotoPath);
            }
        }

        app(ActivityLogger::class)->record(
            'staff-management',
            $creating ? 'staff.created-by-manager' : 'staff.identity-updated',
            $creating ? "Staff login {$staff->username} was created by a Staff Manager." : "Staff identity {$staff->username} was updated by a Staff Manager.",
            [
                'staff_id' => $staff->getKey(),
                'username' => $staff->username,
                'department_code' => $staff->department,
                'account_status' => $staff->account_status,
                'profile_photo_changed' => $this->profilePhoto !== null,
            ],
        );

        $this->resetForm();
        $this->dispatch('alert', type: 'success', message: $creating ? 'Staff account created.' : 'Staff identity updated.');
    }

    public function render(): View
    {
        $this->authorizeStaffManager();
        $staffMembers = User::query()
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
            ->get();

        return view('livewire.staff-manager.staff-management', [
            'staffMembers' => $staffMembers,
            'departments' => Department::query()->where('code', '!=', 'sm')->orderBy('name')->get(),
        ])->extends('layouts.department', [
            'page_title' => 'Staff Directory — '.config('app.name'),
        ])->section('department_content');
    }

    private function authorizeStaffManager(): void
    {
        $actor = Auth::guard('web')->user();
        abort_unless(
            $actor instanceof User
                && $actor->department === 'sm'
                && $actor->account_status === 'active',
            403,
        );
    }
}
