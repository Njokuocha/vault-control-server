<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\StaffAccountStatusService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

class InactiveStaffIndex extends Component
{
    #[Url(as: 'status')]
    public string $statusFilter = 'frozen';

    #[Locked]
    public ?int $statusTargetId = null;

    public bool $statusModalOpen = false;

    public string $statusSelection = 'active';

    public string $freezeHours = '1';

    public function mount(): void
    {
        if (! in_array($this->statusFilter, ['frozen', 'disabled'], true)) {
            $this->statusFilter = 'frozen';
        }
    }

    public function updatedStatusFilter(): void
    {
        if (! in_array($this->statusFilter, ['frozen', 'disabled'], true)) {
            $this->statusFilter = 'frozen';
        }
    }

    public function openStatusModal(int $id): void
    {
        $staff = User::query()->whereNotNull('department')->findOrFail($id);
        $this->statusTargetId = $staff->id;
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
        $this->statusTargetId = null;
        $this->resetValidation();
        $this->dispatch('staff-status-close');
    }

    public function saveStatus(StaffAccountStatusService $service): void
    {
        abort_if($this->statusTargetId === null || ! $this->statusModalOpen, 403);
        $validated = $this->validate([
            'statusSelection' => ['required', 'in:active,frozen,disabled'],
            'freezeHours' => ['required_if:statusSelection,frozen', 'nullable', 'numeric', 'decimal:0,2', 'min:0.1', 'max:'.StaffAccountStatusService::MAX_FREEZE_HOURS],
        ]);
        $staff = User::query()->whereNotNull('department')->findOrFail($this->statusTargetId);
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
        $service->expireAllDue();
    }

    public function render(): View
    {
        $statusCounts = User::query()
            ->whereNotNull('department')
            ->whereIn('account_status', ['frozen', 'disabled'])
            ->selectRaw('account_status, COUNT(*) as aggregate')
            ->groupBy('account_status')
            ->pluck('aggregate', 'account_status');

        return view('livewire.admin.inactive-staff-index', [
            'staffMembers' => User::query()
                ->with('departmentRecord')
                ->whereNotNull('department')
                ->where('account_status', $this->statusFilter)
                ->orderBy('name')
                ->paginate(16),
            'statusCounts' => $statusCounts,
            'statusTarget' => $this->statusTargetId
                ? User::query()->whereNotNull('department')->find($this->statusTargetId)
                : null,
        ])->extends('layouts.app', [
            'page_title' => 'Non-active Staff — '.config('app.name'),
            'page_label' => 'NON-ACTIVE ACCOUNTS',
        ])->section('content');
    }
}
