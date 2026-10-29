<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use App\Services\ReportAccess;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class CaseDossier extends Component
{
    #[Locked]
    public InvestigationCase $caseFile;

    public function mount(InvestigationCase $caseFile): void
    {
        $this->caseFile = $caseFile;
    }

    #[On('refresh-case-dossier')]
    public function refreshForUpload(int $caseId): void
    {
        if ($caseId === $this->caseFile->getKey()) {
            app(VersionedCache::class)->bump('cases');
        }
    }

    public function render(ReportAccess $reportAccess): View
    {
        $isCaseManager = auth('web')->user()?->department === 'cfm';
        $relations = ['categories.images', 'creator'];
        if (! $isCaseManager) {
            $relations[] = 'people';
        }

        $caseFile = app(VersionedCache::class)->remember(
            'cases',
            'record:'.($isCaseManager ? 'cfm:' : '').$this->caseFile->getKey(),
            now()->addSeconds(30),
            fn (): InvestigationCase => InvestigationCase::query()
                ->with($relations)
                ->findOrFail($this->caseFile->getKey()),
        );
        $linkedReports = $reportAccess->visibleQuery()
            ->whereHas('cases', fn ($query) => $query->whereKey($caseFile->id))
            ->withCount('comments')
            ->latest('updated_at')
            ->get();

        return view('livewire.case-dossier', [
            'caseFile' => $caseFile,
            'linkedReports' => $linkedReports,
        ]);
    }
}
