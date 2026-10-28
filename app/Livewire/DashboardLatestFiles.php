<?php

namespace App\Livewire;

use App\Http\Controllers\FileController;
use App\Models\File;
use App\Support\FormatBytes;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class DashboardLatestFiles extends Component
{
    public string $search = '';

    public string $filter = 'all';

    public ?string $selectedUuid = null;

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

    #[On('echo-private:admin.vault,.file.uploaded')]
    #[On('echo-private:admin.vault,.file.edited')]
    #[On('echo-private:admin.vault,.file.deleted')]
    public function refreshFromBroadcast(): void
    {
    }

    public function preview(string $uuid): void
    {
        $file = File::query()->where('uuid', $uuid)->firstOrFail();
        $preview = app(FileController::class)->preview($file)->getData(true);
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
        $metrics = $cache->remember('files', 'dashboard-metrics', now()->addSeconds(30), function (): array {
            $monthStart = Carbon::now()->startOfMonth();

            return [
                'fileCount' => File::query()->count(),
                'storageLabel' => FormatBytes::format((int) File::query()->sum('size')),
                'uploadedThisMonth' => File::query()->where('created_at', '>=', $monthStart)->count(),
                'lastActivity' => File::query()->latest()->value('created_at'),
                'documentCount' => File::query()->whereIn('category', ['pdf', 'text', 'code'])->count(),
                'mediaCount' => File::query()->whereIn('category', ['image', 'video', 'audio'])->count(),
            ];
        });
        $latestFiles = $cache->remember(
            'files',
            'dashboard-latest',
            now()->addSeconds(30),
            fn (): Collection => File::query()
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
            ...$metrics,
            'visibleCount' => $files->count(),
            'groupCount' => $files->groupBy(fn (File $file): string => $file->created_at->format('Y-m'))->count(),
        ]);
    }
}
