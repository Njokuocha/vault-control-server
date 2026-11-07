<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use App\Services\GalleryCategoryAccess;
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

    public function render(GalleryCategoryAccess $galleryAccess): View
    {
        $caseFile = $this->caseFileId === null
            ? new InvestigationCase
            : InvestigationCase::query()
                ->with(['categories.images.file', 'categories.images.categories', 'categories.assignedImages.file', 'categories.assignedImages.categories'])
                ->findOrFail($this->caseFileId);
        $visibleCategoryIds = $caseFile->exists
            ? $galleryAccess->visibleCaseCategoryIds($caseFile->getKey(), request())
            : [];
        foreach ($caseFile->categories as $category) {
            if (! in_array($category->getKey(), $visibleCategoryIds, true)) {
                $category->setRelation('images', collect());

                continue;
            }

            $category->setRelation(
                'images',
                $category->images->merge($category->assignedImages)->unique('id')->sortBy('id')->values(),
            );
        }

        return view('livewire.case-form-page', [
            'caseFile' => $caseFile,
        ]);
    }
}
