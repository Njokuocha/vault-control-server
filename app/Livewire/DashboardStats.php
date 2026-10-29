<?php

namespace App\Livewire;

use App\Models\File;
use App\Support\FormatBytes;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Component;

class DashboardStats extends Component
{
    #[On('refresh-file-dashboard')]
    public function refreshFromBroadcast(): void
    {
    }

    public function render(): View
    {
        $monthStart = Carbon::now()->startOfMonth();
        $metrics = app(VersionedCache::class)->remember(
            'files',
            'dashboard-metrics',
            now()->addSeconds(30),
            fn (): array => [
                'fileCount' => File::query()->count(),
                'storageLabel' => FormatBytes::format((int) File::query()->sum('size')),
                'uploadedThisMonth' => File::query()->where('created_at', '>=', $monthStart)->count(),
                'lastActivity' => File::query()->latest()->value('created_at'),
                'documentCount' => File::query()->whereIn('category', ['pdf', 'text', 'code'])->count(),
                'mediaCount' => File::query()->whereIn('category', ['image', 'video', 'audio'])->count(),
            ],
        );

        return view('livewire.dashboard-stats', $metrics);
    }
}
