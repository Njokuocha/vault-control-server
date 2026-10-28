<?php

namespace App\Livewire;

use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;

class SettingsImagePin extends Component
{
    public string $imagePin = '';

    public string $imagePinConfirmation = '';

    public function save(SettingService $settings): void
    {
        $validated = $this->validate([
            'imagePin' => ['required', 'digits:4', 'same:imagePinConfirmation'],
            'imagePinConfirmation' => ['required', 'digits:4'],
        ]);

        $settings->set('person_image_pin_hash', Hash::make($validated['imagePin']));
        session()->forget('unlocked_person_images');
        $this->reset('imagePin', 'imagePinConfirmation');
        $this->dispatch('alert', type: 'success', message: 'Person image access PIN updated.');
    }

    public function render(SettingService $settings): View
    {
        return view('livewire.settings-image-pin', [
            'imagePinConfigured' => $settings->get('person_image_pin_hash') !== null,
        ]);
    }
}
