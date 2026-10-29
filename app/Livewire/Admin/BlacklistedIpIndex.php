<?php

namespace App\Livewire\Admin;

use App\Models\BlacklistedIp;
use App\Models\ActivityLog;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class BlacklistedIpIndex extends Component
{
    use WithPagination;

    public ?int $confirmingRemoveId = null;

    #[On('refresh-auth-security')]
    public function refreshSecurityData(): void
    {
        $this->resetPage();
        session()->put('security_activity_last_seen_id', (int) ActivityLog::query()->max('id'));
    }

    public function confirmRemove(int $id): void
    {
        $this->confirmingRemoveId = BlacklistedIp::query()->findOrFail($id)->id;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemoveId = null;
    }

    public function remove(int $id): void
    {
        abort_unless($this->confirmingRemoveId === $id, 403);
        $record = BlacklistedIp::query()->findOrFail($id);
        $record->delete();
        app(ActivityLogger::class)->record(
            'security',
            'ip.unblocked',
            "IP address {$record->ip_address} was removed from the authentication blacklist.",
            ['ip_address' => $record->ip_address, 'failed_attempts' => $record->failed_attempts],
        );
        $this->confirmingRemoveId = null;
        $this->dispatch('alert', type: 'success', message: 'IP address removed from the blacklist.');
    }

    public function render(): View
    {
        return view('livewire.admin.blacklisted-ip-index', [
            'records' => BlacklistedIp::query()->latest('triggered_at')->paginate(20),
            'removeTarget' => $this->confirmingRemoveId ? BlacklistedIp::query()->find($this->confirmingRemoveId) : null,
        ])->extends('layouts.app', [
            'page_title' => 'Blacklisted IP Addresses — '.config('app.name'),
            'page_label' => 'BLACKLISTED IPS',
        ])->section('content');
    }
}
