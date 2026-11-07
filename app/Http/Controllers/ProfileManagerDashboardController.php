<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonImage;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;

class ProfileManagerDashboardController extends Controller
{
    public function index(VersionedCache $cache): View
    {
        $summary = $cache->remember(
            'people',
            'pm:dashboard-summary',
            now()->addSeconds(30),
            fn (): array => [
                'total' => Person::query()->count(),
                'created_this_month' => Person::query()->where('created_at', '>=', now()->startOfMonth())->count(),
                'updated_this_month' => Person::query()
                    ->where('updated_at', '>=', now()->startOfMonth())
                    ->whereColumn('updated_at', '>', 'created_at')
                    ->count(),
                'gallery_items' => PersonImage::query()->count(),
            ],
        );
        $recentProfiles = $cache->remember(
            'people',
            'pm:dashboard-recent',
            now()->addSeconds(30),
            fn () => Person::query()->latest('updated_at')->limit(6)->get(),
        );

        return view('departments.pm.dashboard', compact('summary', 'recentProfiles'));
    }
}
