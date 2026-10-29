<?php

namespace App\Livewire\Admin;

use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class DepartmentStaffDirectory extends Component
{
    use WithPagination;

    #[Locked]
    public int $departmentId;

    public string $search = '';

    public function mount(Department $department): void
    {
        $this->departmentId = $department->id;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $department = Department::query()->findOrFail($this->departmentId);
        $staff = User::query()
            ->where('department_id', $department->id)
            ->whereNotNull('department')
            ->when(trim($this->search) !== '', function ($query): void {
                $term = '%'.trim($this->search).'%';
                $query->where(function ($query) use ($term): void {
                    $query->where('name', 'like', $term)
                        ->orWhere('username', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.department-staff-directory', [
            'department' => $department,
            'staffMembers' => $staff,
        ])->extends('layouts.app', [
            'page_title' => $department->name.' Staff — '.config('app.name'),
            'page_label' => 'DEPARTMENT STAFF',
        ])->section('content');
    }
}
