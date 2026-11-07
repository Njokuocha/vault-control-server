<?php

namespace App\Http\Controllers;

use App\Models\InvestigationCase;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;

class CaseManagerDashboardController extends Controller
{
    public function index(VersionedCache $cache): View
    {
        $summary = $cache->remember(
            'cases',
            'cfm:dashboard-summary',
            now()->addSeconds(30),
            fn (): array => [
                'total' => InvestigationCase::query()->count(),
                'open' => InvestigationCase::query()->where('case_status', 'OPEN')->count(),
                'under_review' => InvestigationCase::query()->where('case_status', 'UNDER REVIEW')->count(),
                'sensitive' => InvestigationCase::query()->where('classification', 'HIGHLY SENSITIVE')->count(),
            ],
        );
        $recentCases = $cache->remember(
            'cases',
            'cfm:dashboard-recent',
            now()->addSeconds(30),
            fn () => InvestigationCase::query()->with('creator')->latest()->limit(6)->get(),
        );

        return view('departments.cfm.dashboard', compact('summary', 'recentCases'));
    }
}
