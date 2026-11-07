<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\Department;
use App\Models\User;
use App\Services\StaffAccountStatusService;
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

    public bool $statusModalOpen = false;

    public string $statusSelection = 'active';

    public string $freezeHours = '1';

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

    public function openStatusModal(): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($this->staffId);
        $this->statusSelection = in_array($staff->account_status, ['frozen', 'disabled'], true)
            ? $staff->account_status
            : 'active';
        if ($staff->account_status === 'frozen' && $staff->frozen_until?->isFuture()) {
            $hours = max(0.1, ceil(now()->diffInSeconds($staff->frozen_until) / 36) / 100);
            $this->freezeHours = rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.');
        } else {
            $this->freezeHours = '1';
        }
        $this->statusModalOpen = true;
        $this->resetValidation();
    }

    public function cancelStatusModal(): void
    {
        $this->statusModalOpen = false;
        $this->resetValidation();
        $this->dispatch('staff-status-close');
    }

    public function saveStatus(StaffAccountStatusService $service): void
    {
        abort_if(! $this->statusModalOpen, 403);
        $validated = $this->validate([
            'statusSelection' => ['required', 'in:active,frozen,disabled'],
            'freezeHours' => ['required_if:statusSelection,frozen', 'nullable', 'numeric', 'decimal:0,2', 'min:0.1', 'max:'.StaffAccountStatusService::MAX_FREEZE_HOURS],
        ]);
        $staff = User::query()->whereNotNull('department')->findOrFail($this->staffId);
        $service->change(
            $staff,
            $validated['statusSelection'],
            $validated['statusSelection'] === 'frozen' ? (float) $validated['freezeHours'] : null,
        );

        $this->cancelStatusModal();
        $this->dispatch('alert', type: 'success', message: 'Staff account status updated.');
    }

    public function refreshFreezeExpiry(StaffAccountStatusService $service): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($this->staffId);
        $service->expireIfDue($staff);
    }

    public function render(): View
    {
        $staff = User::query()->with('departmentRecord')->whereNotNull('department')->findOrFail($this->staffId);
        if ($staff->account_status === 'frozen' && $staff->frozen_until?->isPast()) {
            $staff = app(StaffAccountStatusService::class)->expireIfDue($staff);
        }
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
            'statusTarget' => $staff,
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
