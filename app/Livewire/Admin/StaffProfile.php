<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\Department;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class StaffProfile extends Component
{
    use WithPagination;

    #[Locked]
    public int $staffId;

    #[Locked]
    public string $section = 'profile';

    public string $search = '';

    public function mount(User $user, string $section = 'profile'): void
    {
        abort_unless($user->department !== null, 404);
        abort_unless(in_array($section, ['profile', 'authentication-history', 'activity'], true), 404);

        $this->staffId = $user->id;
        $this->section = $section;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $staff = User::query()->with('departmentRecord')->whereNotNull('department')->findOrFail($this->staffId);
        $authEvents = AuthEvent::query()
            ->where('account_type', 'staff')
            ->where('account_id', (string) $staff->id)
            ->latest('occurred_at');
        $activityLogs = ActivityLog::query()
            ->where('actor_type', 'staff')
            ->where('actor_id', (string) $staff->id)
            ->latest('occurred_at');

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            if ($this->section === 'authentication-history') {
                $authEvents->where(function ($query) use ($term): void {
                    $query->where('ip_address', 'like', $term)
                        ->orWhere('status', 'like', $term)
                        ->orWhere('result_detail', 'like', $term);
                });
            } elseif ($this->section === 'activity') {
                $activityLogs->where(function ($query) use ($term): void {
                    $query->where('summary', 'like', $term)
                        ->orWhere('category', 'like', $term)
                        ->orWhere('action', 'like', $term);
                });
            }
        }

        return view('livewire.admin.staff-profile', [
            'staff' => $staff,
            'department' => Department::query()->find($staff->department_id),
            'lastAuthenticatedAt' => AuthEvent::query()
                ->where('account_type', 'staff')
                ->where('account_id', (string) $staff->id)
                ->where('status', 'passed')
                ->latest('occurred_at')
                ->value('occurred_at'),
            'recentAuthEvents' => $this->section === 'profile' ? $authEvents->limit(5)->get() : null,
            'recentActivityLogs' => $this->section === 'profile' ? $activityLogs->limit(5)->get() : null,
            'authEvents' => $this->section === 'authentication-history' ? $authEvents->paginate(20) : null,
            'activityLogs' => $this->section === 'activity' ? $activityLogs->paginate(20) : null,
        ])->extends('layouts.app', [
            'page_title' => $staff->name.' — Staff Profile',
            'page_label' => $this->section === 'profile' ? 'STAFF PROFILE' : strtoupper(str_replace('-', ' ', $this->section)),
        ])->section('content');
    }
}
