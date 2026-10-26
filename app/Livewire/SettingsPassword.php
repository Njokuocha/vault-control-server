<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class SettingsPassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function save(): void
    {
        $validated = $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'newPassword' => ['required', 'string', 'min:12', 'same:newPasswordConfirmation'],
            'newPasswordConfirmation' => ['required', 'string', 'min:12'],
        ]);

        $user = Auth::user();
        abort_unless($user !== null, 401);
        $user->password = Hash::make($validated['newPassword']);
        $user->save();

        session()->regenerate();
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->dispatch('alert', type: 'success', message: 'Administrator password updated.');
    }

    public function render(): View
    {
        return view('livewire.settings-password');
    }
}
