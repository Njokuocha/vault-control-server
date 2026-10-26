<?php

namespace App\Livewire;

use App\Models\InvestigationCase;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class CaseIndex extends Component
{
    use WithPagination;

    public function render(): View
    {
        $page = $this->getPage();
        $cache = app(VersionedCache::class);

        $cases = $cache->remember(
            'cases',
            "index:page:{$page}",
            now()->addSeconds(30),
            fn () => InvestigationCase::query()
                ->withCount(['people', 'categories'])
                ->latest()
                ->paginate(24, ['*'], 'page', $page),
        );
        $classificationCounts = $cache->remember(
            'cases',
            'classification-counts',
            now()->addSeconds(30),
            fn () => InvestigationCase::query()
                ->select('classification', DB::raw('count(*) as aggregate'))
                ->groupBy('classification')
                ->pluck('aggregate', 'classification'),
        );

        return view('livewire.case-index', [
            'cases' => $cases,
            'classificationCounts' => $classificationCounts,
        ]);
    }
}
