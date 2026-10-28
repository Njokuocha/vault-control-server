<?php

namespace App\Livewire;

use App\Services\SettingService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class SettingsUploadPolicy extends Component
{
    public int $maxUploadMb;

    public int $maxVideoUploadMb;

    public bool $apiUploadsEnabled;

    public function mount(SettingService $settings): void
    {
        $this->maxUploadMb = $settings->maxUploadMegabytes();
        $this->maxVideoUploadMb = $settings->maxVideoUploadMegabytes();
        $this->apiUploadsEnabled = $settings->apiUploadsEnabled();
    }

    public function save(SettingService $settings): void
    {
        $validated = $this->validate([
            'maxUploadMb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'maxVideoUploadMb' => ['required', 'integer', 'min:0', 'max:1048576'],
            'apiUploadsEnabled' => ['required', 'boolean'],
        ]);

        $settings->set('max_upload_mb', (string) $validated['maxUploadMb']);
        $settings->set('max_video_upload_mb', (string) $validated['maxVideoUploadMb']);
        $settings->set('api_upload_enabled', $validated['apiUploadsEnabled'] ? '1' : '0');

        $this->dispatch('alert', type: 'success', message: 'Upload policy updated.');
    }

    public function render(): View
    {
        return view('livewire.settings-upload-policy');
    }
}
