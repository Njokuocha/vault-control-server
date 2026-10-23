<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Support\FormatBytes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    public function index(): View
    {
        $monthStart = Carbon::now()->startOfMonth();
        $files = File::query()
            ->latest()
            ->limit(500)
            ->get();

        return view('welcome', [
            'files' => $files,
            'fileCount' => File::query()->count(),
            'storageLabel' => FormatBytes::format((int) File::query()->sum('size')),
            'uploadedThisMonth' => File::query()->where('created_at', '>=', $monthStart)->count(),
            'lastActivity' => File::query()->latest()->value('created_at'),
            'fileGroups' => $files->groupBy(fn (File $file): string => $file->created_at->format('Y-m')),
            'groupLabels' => $this->groupLabels($files),
            'documentCount' => File::query()->whereIn('category', ['pdf', 'text', 'code'])->count(),
            'mediaCount' => File::query()->whereIn('category', ['image', 'video', 'audio'])->count(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function groupLabels(Collection $files): array
    {
        return $files
            ->mapWithKeys(fn (File $file): array => [
                $file->created_at->format('Y-m') => $file->created_at->format('F Y'),
            ])
            ->all();
    }
}
