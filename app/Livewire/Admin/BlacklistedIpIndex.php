<?php

namespace App\Livewire\Admin;

use App\Models\ActivityLog;
use App\Models\BlacklistedIp;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class BlacklistedIpIndex extends Component
{
    use WithPagination;

    public ?int $confirmingRemoveId = null;

    public bool $addModalOpen = false;

    public string $ipAddress = '';

    public string $reason = '';

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

    public function openAddModal(): void
    {
        $this->resetValidation();
        $this->ipAddress = '';
        $this->reason = '';
        $this->addModalOpen = true;
    }

    public function closeAddModal(): void
    {
        $this->addModalOpen = false;
        $this->resetValidation();
    }

    public function add(ActivityLogger $logger): void
    {
        $packedAddress = inet_pton($this->ipAddress);
        if ($packedAddress !== false) {
            $this->ipAddress = inet_ntop($packedAddress);
        }

        $validated = $this->validate([
            'ipAddress' => ['required', 'ip', 'max:45', Rule::unique('blacklisted_ips', 'ip_address')],
            'reason' => ['required', 'string', 'max:160'],
        ]);
        $packedAddress = inet_pton($validated['ipAddress']);
        if ($packedAddress === false) {
            throw new \UnexpectedValueException('The validated IP address could not be normalized.');
        }

        $ip = inet_ntop($packedAddress);
        $record = BlacklistedIp::query()->create([
            'ip_address' => $ip,
            'failed_attempts' => null,
            'triggered_at' => null,
            'last_attempt_at' => null,
            'reason' => $validated['reason'],
            'source' => 'manual',
        ]);
        $logger->record(
            'security',
            'ip.blacklisted_manually',
            "IP address {$ip} was manually added to the authentication blacklist.",
            ['ip_address' => $ip, 'blacklisted_ip_id' => $record->id, 'reason' => $record->reason],
        );

        $this->addModalOpen = false;
        $this->ipAddress = '';
        $this->reason = '';
        $this->resetPage();
        $this->dispatch('alert', type: 'success', message: 'IP address added to the blacklist.');
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
        $this->resetPage();
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
