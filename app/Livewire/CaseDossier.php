<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class CaseDossier extends Component
{
    #[Locked]
    public InvestigationCase $caseFile;

    public function mount(InvestigationCase $caseFile): void
    {
        $this->caseFile = $caseFile;
    }

    public function render(): View
    {
        $caseFile = app(VersionedCache::class)->remember(
            'cases',
            'record:'.$this->caseFile->getKey(),
            now()->addSeconds(30),
            fn (): InvestigationCase => InvestigationCase::query()
                ->with(['categories.images', 'people'])
                ->findOrFail($this->caseFile->getKey()),
        );

        return view('livewire.case-dossier', [
            'caseFile' => $caseFile,
        ]);
    }
}
