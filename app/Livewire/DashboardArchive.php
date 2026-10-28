<?php

namespace App\Livewire;

use App\Http\Controllers\FileController;
use App\Models\File;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class DashboardArchive extends Component
{
    use WithPagination;

    public string $search = '';

    public string $year = '';

    public string $month = '';

    public string $extension = '';

    public string $uploadMethod = '';

    public bool $isOpen = false;

    public ?string $selectedUuid = null;

    #[On('open-archive')]
    public function open(): void
    {
        $this->isOpen = true;
    }

    #[On('close-archive')]
    public function close(): void
    {
        $this->isOpen = false;
        $this->dispatch('archive-closed');
    }

    #[On('refresh-archive')]
    public function refreshArchive(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->validateOnly('search', ['search' => ['nullable', 'string', 'max:255']]);
        $this->resetPage();
    }

    public function updatedYear(): void
    {
        $this->validateOnly('year', ['year' => ['nullable', 'regex:/^\d{4}$/']]);
        $this->resetPage();
    }

    public function updatedMonth(): void
    {
        $this->validateOnly('month', ['month' => ['nullable', 'integer', 'between:1,12']]);
        $this->resetPage();
    }

    public function updatedExtension(): void
    {
        $this->validateOnly('extension', ['extension' => ['nullable', 'regex:/^(?:__none__|[a-zA-Z0-9]{1,20})$/']]);
        $this->resetPage();
    }

    public function updatedUploadMethod(): void
    {
        $this->validateOnly('uploadMethod', ['uploadMethod' => ['nullable', 'in:api,manual']]);
        $this->resetPage();
    }

    public function preview(string $uuid): void
    {
        $file = File::query()->where('uuid', $uuid)->firstOrFail();
        $preview = app(FileController::class)->preview($file)->getData(true);
        $this->selectedUuid = $file->uuid;
        $this->dispatch('archive-preview-ready', file: $preview, uuid: $file->uuid);
    }

    public function render(): View
    {
        $files = null;
        $years = [];
        $extensions = [];

        if ($this->isOpen) {
            $validated = $this->validate([
                'search' => ['nullable', 'string', 'max:255'],
                'year' => ['nullable', 'regex:/^\d{4}$/'],
                'month' => ['nullable', 'integer', 'between:1,12'],
                'extension' => ['nullable', 'regex:/^(?:__none__|[a-zA-Z0-9]{1,20})$/'],
                'uploadMethod' => ['nullable', 'in:api,manual'],
            ]);
            $page = $this->getPage();
            $filters = [
                'q' => trim((string) ($validated['search'] ?? '')),
                'year' => (string) ($validated['year'] ?? ''),
                'month' => (string) ($validated['month'] ?? ''),
                'extension' => strtolower((string) ($validated['extension'] ?? '')),
                'upload_method' => (string) ($validated['uploadMethod'] ?? ''),
                'page' => $page,
            ];
            $files = app(VersionedCache::class)->remember(
                'files',
                'archive-results:'.hash('sha256', json_encode($filters, JSON_THROW_ON_ERROR)),
                now()->addSeconds(30),
                function () use ($filters) {
                    return $this->filteredFiles($filters)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id')
                        ->paginate(
                            100,
                            ['id', 'uuid', 'original_name', 'extension', 'mime_type', 'category', 'upload_method', 'size', 'created_at'],
                            'page',
                            $filters['page'],
                        );
                },
            );

            $driver = DB::connection()->getDriverName();
            $yearExpression = match ($driver) {
                'sqlite' => "strftime('%Y', created_at)",
                'mysql', 'mariadb' => 'YEAR(created_at)',
                'pgsql' => 'EXTRACT(YEAR FROM created_at)',
                default => null,
            };
            if ($yearExpression !== null) {
                $years = app(VersionedCache::class)->remember(
                    'files',
                    'archive-years',
                    now()->addMinutes(5),
                    fn (): array => File::query()->selectRaw($yearExpression.' as year')
                        ->distinct()->orderByDesc('year')->pluck('year')->map(fn ($year): string => (string) $year)->all(),
                );
            }
            $extensions = app(VersionedCache::class)->remember(
                'files',
                'archive-extensions',
                now()->addMinutes(5),
                function (): array {
                    $extensions = File::query()->whereNotNull('extension')->where('extension', '!=', '')
                        ->distinct()->orderBy('extension')->pluck('extension')->map(fn ($extension): string => (string) $extension)->all();
                    if (File::query()->whereNull('extension')->exists()) {
                        $extensions[] = '__none__';
                    }

                    return $extensions;
                },
            );
        }

        return view('livewire.dashboard-archive', [
            'files' => $files,
            'years' => $years,
            'extensions' => $extensions,
        ]);
    }

    private function filteredFiles(array $filters)
    {
        return File::query()
            ->select(['id', 'uuid', 'original_name', 'extension', 'mime_type', 'category', 'upload_method', 'size', 'created_at'])
            ->when($filters['q'] !== '', function ($query) use ($filters): void {
                $query->where(function ($query) use ($filters): void {
                    $query->where('original_name', 'like', '%'.$filters['q'].'%')
                        ->orWhere('category', 'like', '%'.$filters['q'].'%');
                });
            })
            ->when($filters['year'] !== '', fn ($query) => $query->whereYear('created_at', (int) $filters['year']))
            ->when($filters['month'] !== '', fn ($query) => $query->whereMonth('created_at', (int) $filters['month']))
            ->when($filters['extension'] !== '', function ($query) use ($filters): void {
                if ($filters['extension'] === '__none__') {
                    $query->whereNull('extension');
                } else {
                    $query->where('extension', $filters['extension']);
                }
            })
            ->when($filters['upload_method'] !== '', fn ($query) => $query->where('upload_method', $filters['upload_method']));
    }
}
