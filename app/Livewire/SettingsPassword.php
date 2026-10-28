<?php

namespace App\Livewire;

use App\Models\Admin;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SettingsPassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function save(): void
    {
        $validated = $this->validate([
            'currentPassword' => ['required', 'current_password:admin'],
            'newPassword' => ['required', 'string', 'min:12', 'same:newPasswordConfirmation'],
            'newPasswordConfirmation' => ['required', 'string', 'min:12'],
        ]);

        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin, 401);
        $admin->password = $validated['newPassword'];
        $admin->save();

        session()->regenerate();
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->dispatch('alert', type: 'success', message: 'Administrator password updated.');
    }

    public function render(): View
    {
        return view('livewire.settings-password');
    }
}
