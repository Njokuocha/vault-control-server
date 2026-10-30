<?php

namespace App\Livewire\Admin;

use App\Models\Admin;
use App\Services\ActivityLogger;
use App\Services\AdminTwoFactorService;
use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TwoFactorSettings extends Component
{
    public bool $enabled = false;

    public function mount(AdminTwoFactorService $twoFactor): void
    {
        abort_unless(Auth::guard('admin')->user() instanceof Admin, 403);
        $this->enabled = $twoFactor->enabled();
    }

    public function toggle(SettingService $settings): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 403);

        $this->enabled = ! app(AdminTwoFactorService::class)->enabled();
        $settings->set('admin_two_factor_enabled', $this->enabled ? '1' : '0');
        app(ActivityLogger::class)->record(
            'configuration',
            $this->enabled ? 'security.admin-two-factor-enabled' : 'security.admin-two-factor-disabled',
            $this->enabled
                ? 'Administrator enabled email verification for sign-in.'
                : 'Administrator disabled email verification for sign-in.',
            ['enabled' => $this->enabled],
            $admin,
        );
        $this->dispatch(
            'alert',
            type: 'success',
            message: $this->enabled
                ? 'Email verification is now required for administrator sign-in.'
                : 'Email verification is disabled for administrator sign-in.',
        );
    }

    public function render(): View
    {
        return view('livewire.admin.two-factor-settings', [
            'adminEmail' => Auth::guard('admin')->user()?->email,
        ]);
    }
}
