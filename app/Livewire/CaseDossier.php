<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use App\Services\GalleryCategoryAccess;
use App\Services\ReportAccess;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class CaseDossier extends Component
{
    use WithPagination;

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

    public function render(ReportAccess $reportAccess, GalleryCategoryAccess $galleryAccess): View
    {
        $isCaseManager = auth('web')->user()?->department === 'cfm';
        $relations = [
            'categories.images.file',
            'categories.images.categories',
            'categories.assignedImages.file',
            'categories.assignedImages.categories',
            'creator',
        ];
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
        $caseFile->loadMissing($relations);
        $visibleCategoryIds = $galleryAccess->visibleCaseCategoryIds($caseFile->getKey(), request());
        foreach ($caseFile->categories as $category) {
            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->setRelation('images', collect());
                $category->setAttribute('access_restricted', true);

                continue;
            }
            $images = $category->images
                ->merge($category->assignedImages)
                ->unique('id')
                ->sortBy('id')
                ->values();
            $images->loadMissing(['file', 'categories']);
            $category->setRelation('images', $images);
            $category->setAttribute('access_restricted', false);
        }

        $linkedReports = $reportAccess->visibleQuery()
            ->join('report_case_file', 'reports.id', '=', 'report_case_file.report_id')
            ->where('report_case_file.case_file_id', $caseFile->id)
            ->select('reports.*')
            ->withCount('comments')
            ->latest('updated_at')
            ->orderByDesc('reports.id')
            ->paginate(10, ['reports.*'], 'linkedReportsPage');

        return view('livewire.case-dossier', [
            'caseFile' => $caseFile,
            'linkedReports' => $linkedReports,
            'canManageGalleryAccess' => $galleryAccess->isAdministrator(request()),
        ]);
    }
}
