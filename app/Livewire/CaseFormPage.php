<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CaseFormPage extends Component
{
    #[Locked]
    public ?int $caseFileId = null;

    public int $maxUploadMb;

    public int $maxVideoUploadMb;

    public function mount(InvestigationCase $caseFile, int $maxUploadMb, int $maxVideoUploadMb): void
    {
        $this->caseFileId = $caseFile->exists ? $caseFile->getKey() : null;
        $this->maxUploadMb = $maxUploadMb;
        $this->maxVideoUploadMb = $maxVideoUploadMb;
    }

    public function render(): View
    {
        $caseFile = $this->caseFileId === null
            ? new InvestigationCase
            : InvestigationCase::query()->with('categories.images')->findOrFail($this->caseFileId);

        return view('livewire.case-form-page', [
            'caseFile' => $caseFile,
        ]);
    }
}
