<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use App\Models\Person;
use App\Services\GalleryCategoryAccess;
use App\Services\PersonMediaAccess;
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

    public function render(
        ReportAccess $reportAccess,
        GalleryCategoryAccess $galleryAccess,
        PersonMediaAccess $personMediaAccess,
    ): View
    {
        $isCaseManager = auth('web')->user()?->department === 'cfm';
        $relations = [
            'categories.images.file',
            'categories.images.categories',
            'categories.assignedImages.file',
            'categories.assignedImages.categories',
            'creator',
        ];
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
            $images = $category->images
                ->merge($category->assignedImages)
                ->unique('id')
                ->sortBy('id')
                ->values();
            $category->setRelation('images', $images);

            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->setAttribute('access_restricted', true);

                continue;
            }
            $images->loadMissing(['file', 'categories']);
            $category->setAttribute('access_restricted', false);
        }

        $linkedReportQuery = $reportAccess->visibleQuery()
            ->join('report_case_file', 'reports.id', '=', 'report_case_file.report_id')
            ->where('report_case_file.case_file_id', $caseFile->id)
            ->select('reports.*');
        $linkedReportCount = (clone $linkedReportQuery)->count('reports.id');
        $linkedReports = $linkedReportQuery
            ->withCount('comments')
            ->with(['media' => fn ($query) => $query->orderBy('sort_order')->orderBy('id')->limit(1)])
            ->latest('updated_at')
            ->orderByDesc('reports.id')
            ->limit(8)
            ->get();

        $linkedPeople = collect();
        $linkedPeopleCount = 0;
        $personMediaTokens = collect();
        if (! $isCaseManager) {
            $actor = auth('admin')->user() ?? auth('web')->user();
            $linkedPeopleCount = $caseFile->people()->count();
            $linkedPeople = $caseFile->people()
                ->with('mainImageFile')
                ->orderBy('people.name')
                ->orderBy('people.id')
                ->limit(20)
                ->get();
            $personMediaTokens = $linkedPeople->mapWithKeys(
                fn (Person $person): array => [
                    $person->getKey() => $person->main_image_path || $person->main_image_file_id
                        ? $personMediaAccess->activeToken(request(), $person->getKey(), $actor)
                        : null,
                ],
            );
        }

        return view('livewire.case-dossier', [
            'caseFile' => $caseFile,
            'linkedReports' => $linkedReports,
            'linkedReportCount' => $linkedReportCount,
            'linkedPeople' => $linkedPeople,
            'linkedPeopleCount' => $linkedPeopleCount,
            'personMediaTokens' => $personMediaTokens,
            'canManageGalleryAccess' => $galleryAccess->isAdministrator(request()),
        ]);
    }
}
