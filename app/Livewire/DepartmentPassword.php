<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class DepartmentPassword extends Component
{
    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPasswordConfirmation = '';

    public function save(): void
    {
        abort_unless(Auth::guard('web')->user()?->department === 'cfm', 403);

        $validated = $this->validate([
            'currentPassword' => ['required', 'current_password:web'],
            'newPassword' => ['required', 'string', 'min:12', 'same:newPasswordConfirmation'],
            'newPasswordConfirmation' => ['required', 'string', 'min:12'],
        ]);

        $user = Auth::guard('web')->user();
        $user->password = $validated['newPassword'];
        $user->save();

        session()->regenerate();
        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');
        $this->dispatch('alert', type: 'success', message: 'Your password has been updated.');
    }

    public function render(): View
    {
        return view('livewire.department-password');
    }
}
