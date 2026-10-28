<?php

namespace App\Livewire;

use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

class SettingsUploadKey extends Component
{
    public string $customKey = '';

    public function generate(): void
    {
        $this->customKey = Str::random(64);
        $this->dispatch('alert', type: 'success', message: 'A secure key was generated. Save it to rotate the active key.');
    }

    public function rotate(SettingService $settings): void
    {
        $validated = $this->validate([
            'customKey' => ['nullable', 'string', 'max:255'],
        ]);

        $customKey = trim((string) ($validated['customKey'] ?? ''));
        $settings->set('upload_key', $customKey !== '' ? $customKey : Str::random(64));
        $this->customKey = '';
        $this->dispatch('alert', type: 'success', message: 'Upload key updated. The previous key is no longer valid.');
    }

    public function render(SettingService $settings): View
    {
        return view('livewire.settings-upload-key', [
            'uploadKey' => $settings->get('upload_key'),
            'uploadEndpoint' => url('/api/upload'),
            'apiUploadsEnabled' => $settings->apiUploadsEnabled(),
        ]);
    }
}
