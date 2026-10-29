<?php

namespace App\Livewire\Admin;

use App\Models\WhitelistedIp;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class WhitelistedIpIndex extends Component
{
    use WithPagination;

    public bool $addModalOpen = false;

    public string $ipAddress = '';

    public ?int $confirmingRemoveId = null;

    public function openAddModal(): void
    {
        $this->resetValidation();
        $this->ipAddress = '';
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
            'ipAddress' => ['required', 'ip', 'max:45', Rule::unique('whitelisted_ips', 'ip_address')],
        ]);
        $packedAddress = inet_pton($validated['ipAddress']);
        if ($packedAddress === false) {
            throw new \UnexpectedValueException('The validated IP address could not be normalized.');
        }

        $ip = inet_ntop($packedAddress);
        $record = WhitelistedIp::query()->create(['ip_address' => $ip]);
        $logger->record(
            'security',
            'ip.whitelisted',
            "IP address {$ip} was added to the authentication whitelist.",
            ['ip_address' => $ip, 'whitelisted_ip_id' => $record->id],
        );

        $this->addModalOpen = false;
        $this->ipAddress = '';
        $this->resetPage();
        $this->dispatch('alert', type: 'success', message: 'IP address added to the whitelist.');
    }

    public function confirmRemove(int $id): void
    {
        $this->confirmingRemoveId = WhitelistedIp::query()->findOrFail($id)->id;
    }

    public function cancelRemove(): void
    {
        $this->confirmingRemoveId = null;
    }

    public function remove(int $id, ActivityLogger $logger): void
    {
        abort_unless($this->confirmingRemoveId === $id, 403);
        $record = WhitelistedIp::query()->findOrFail($id);
        $record->delete();
        $logger->record(
            'security',
            'ip.unwhitelisted',
            "IP address {$record->ip_address} was removed from the authentication whitelist.",
            ['ip_address' => $record->ip_address],
        );

        $this->confirmingRemoveId = null;
        $this->resetPage();
        $this->dispatch('alert', type: 'success', message: 'IP address removed from the whitelist.');
    }

    public function render(): View
    {
        return view('livewire.admin.whitelisted-ip-index', [
            'records' => WhitelistedIp::query()->orderBy('ip_address')->paginate(20),
            'removeTarget' => $this->confirmingRemoveId ? WhitelistedIp::query()->find($this->confirmingRemoveId) : null,
        ])->extends('layouts.app', [
            'page_title' => 'Whitelisted IP Addresses — '.config('app.name'),
            'page_label' => 'WHITELISTED IPS',
        ])->section('content');
    }
}
