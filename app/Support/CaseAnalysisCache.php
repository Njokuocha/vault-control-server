<?php

namespace App\Support;

use Closure;

class CaseAnalysisCache
{
    public function rememberSummary(int $caseId, Closure $resolver): mixed
    {
        return app(VersionedCache::class)->remember(
            'case-analysis-'.$caseId,
            'summary',
            now()->addMinutes(5),
            $resolver,
        );
    }

    public function invalidate(int $caseId): void
    {
        app(VersionedCache::class)->bumpAfterCommit('case-analysis-'.$caseId);
    }

    /**
     * @param  iterable<int>  $caseIds
     */
    public function invalidateMany(iterable $caseIds): void
    {
        foreach (collect($caseIds)->map(fn ($caseId): int => (int) $caseId)->unique() as $caseId) {
            if ($caseId > 0) {
                $this->invalidate($caseId);
            }
        }
    }
}
