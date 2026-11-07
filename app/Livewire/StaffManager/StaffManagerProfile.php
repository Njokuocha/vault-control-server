<?php

namespace App\Livewire\StaffManager;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class StaffManagerProfile extends Component
{
    public int $staffId;

    public function mount(User $user): void
    {
        $this->authorizeStaffManager();
        abort_unless($user->department !== null, 404);
        $this->staffId = $user->getKey();
    }

    public function render(): View
    {
        $this->authorizeStaffManager();
        $staff = User::query()->with('departmentRecord')->whereNotNull('department')->findOrFail($this->staffId);

        return view('livewire.staff-manager.staff-profile', [
            'staff' => $staff,
        ])->extends('layouts.department', [
            'page_title' => $staff->name.' — Staff Profile',
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
