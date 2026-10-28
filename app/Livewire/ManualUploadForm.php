<?php

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class ManualUploadForm extends Component
{
    public int $maxUploadMb;

    public int $maxVideoUploadMb;

    public int $selectedCount = 0;

    public function mount(int $maxUploadMb, int $maxVideoUploadMb): void
    {
        $this->maxUploadMb = $maxUploadMb;
        $this->maxVideoUploadMb = $maxVideoUploadMb;
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
