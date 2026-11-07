<?php

namespace App\Livewire;

use App\Http\Controllers\FileController;
use App\Models\File;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class DashboardLatestFiles extends Component
{
    public string $search = '';

    public string $filter = 'all';

    public ?string $selectedUuid = null;

    #[Locked]
    public string $fileRoutePrefix = 'files';

    public function mount(): void
    {
        if (request()->routeIs('fm.*')) {
            $this->fileRoutePrefix = 'fm.files';
        }
    }

    #[On('dashboard-archive-search')]
    public function updateSearch(string $search): void
    {
        $this->search = mb_substr($search, 0, 255);
    }

    #[On('dashboard-archive-filter')]
    public function updateFilter(string $filter): void
    {
        if (in_array($filter, ['all', 'documents', 'media'], true)) {
            $this->filter = $filter;
        }
    }

    #[On('refresh-file-dashboard')]
    public function refreshFromBroadcast(): void
    {
    }

    public function preview(string $uuid): void
    {
        $file = File::query()->where('uuid', $uuid)->firstOrFail();
        $preview = app(FileController::class)->preview($file, $this->fileRoutePrefix)->getData(true);
        $this->selectedUuid = $file->uuid;
        $this->dispatch('vault-file-preview-ready', file: $preview, uuid: $file->uuid);
    }

    #[On('refresh-private-preview')]
    public function refreshPreview(string $uuid): void
    {
        if ($this->selectedUuid === $uuid) {
            $this->preview($uuid);
        }
    }

    public function render(): View
    {
        $cache = app(VersionedCache::class);
        $archiveMetrics = $cache->remember('files', 'dashboard-archive-metrics', now()->addSeconds(30), fn (): array => [
            'fileCount' => File::query()->standaloneArchive()->count(),
            'documentCount' => File::query()->standaloneArchive()->whereIn('category', ['pdf', 'text', 'code'])->count(),
            'mediaCount' => File::query()->standaloneArchive()->whereIn('category', ['image', 'video', 'audio'])->count(),
        ]);
        $latestFiles = $cache->remember(
            'files',
            'dashboard-latest',
            now()->addSeconds(30),
            fn (): Collection => File::query()
                ->standaloneArchive()
                ->select(['id', 'uuid', 'original_name', 'extension', 'mime_type', 'category', 'upload_method', 'size', 'created_at'])
                ->latest()->limit(20)->get(),
        );
        $query = mb_strtolower(trim($this->search));
        $files = $latestFiles->filter(function (File $file) use ($query): bool {
            $group = in_array($file->category, ['image', 'video', 'audio'], true)
                ? 'media'
                : (in_array($file->category, ['pdf', 'text', 'code'], true) ? 'documents' : 'other');

            return ($this->filter === 'all' || $group === $this->filter)
                && ($query === '' || str_contains(mb_strtolower($file->original_name.' '.$file->mime_type.' '.$file->category), $query));
        })->values();

        return view('livewire.dashboard-latest-files', [
            'fileGroups' => $files->groupBy(fn (File $file): string => $file->created_at->format('Y-m')),
            'groupLabels' => $files->mapWithKeys(fn (File $file): array => [
                $file->created_at->format('Y-m') => $file->created_at->format('F Y'),
            ])->all(),
            ...$archiveMetrics,
            'visibleCount' => $files->count(),
            'groupCount' => $files->groupBy(fn (File $file): string => $file->created_at->format('Y-m'))->count(),
            'fileRoutePrefix' => $this->fileRoutePrefix,
        ]);
    }
}
