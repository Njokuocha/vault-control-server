<?php

namespace App\Livewire;

use App\Services\ActivityLogger;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class WhitelistIpPolicyToggle extends Component
{
    public bool $enabled = false;

    public function mount(SettingService $settings): void
    {
        $value = $settings->get('whitelist_ip_enforcement');
        if ($value !== null && ! in_array($value, ['0', '1'], true)) {
            throw new \UnexpectedValueException('The whitelist IP enforcement setting is invalid.');
        }

        $this->enabled = $value === '1';
    }

    public function toggle(ActivityLogger $logger): void
    {
        $this->enabled = ! $this->enabled;
        app(SettingService::class)->set('whitelist_ip_enforcement', $this->enabled ? '1' : '0');
        $logger->record(
            'security',
            'ip.whitelist_policy_updated',
            'Whitelist-only authentication was '.($this->enabled ? 'enabled' : 'disabled').'.',
            ['enabled' => $this->enabled],
        );
        $this->dispatch('alert', type: 'success', message: 'IP whitelist policy updated.');
    }

    public function render(): View
    {
        return view('livewire.whitelist-ip-policy-toggle');
    }
}
