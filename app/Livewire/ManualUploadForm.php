<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class ManualUploadForm extends Component
{
    public int $maxUploadMb;

    public int $maxVideoUploadMb;

    public int $selectedCount = 0;

    #[Locked]
    public string $uploadRoute = 'uploads.create';

    public function mount(int $maxUploadMb, int $maxVideoUploadMb): void
    {
        $this->maxUploadMb = $maxUploadMb;
        $this->maxVideoUploadMb = $maxVideoUploadMb;
        if (request()->routeIs('fm.*')) {
            $this->uploadRoute = 'fm.uploads.create';
        }
    }

    #[On('manual-upload-selection')]
    public function updateSelection(int $count): void
    {
        $this->selectedCount = max(0, $count);
    }

    public function render(): View
    {
        return view('livewire.manual-upload-form');
    }
}
