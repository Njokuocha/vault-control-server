<?php

namespace App\Http\Controllers;

use App\Models\File;
use App\Support\FormatBytes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\VersionedCache;

class DashboardController extends Controller
{
    public function index(): View
    {
        $monthStart = Carbon::now()->startOfMonth();
        $files = File::query()
            ->select(['id', 'uuid', 'original_name', 'extension', 'mime_type', 'category', 'upload_method', 'size', 'created_at'])
            ->latest()
            ->limit(20)
            ->get();
        $metrics = app(VersionedCache::class)->remember('files', 'dashboard-metrics', now()->addSeconds(30), function () use ($monthStart): array {
            return [
                'fileCount' => File::query()->count(),
                'storageLabel' => FormatBytes::format((int) File::query()->sum('size')),
                'uploadedThisMonth' => File::query()->where('created_at', '>=', $monthStart)->count(),
                'lastActivity' => File::query()->latest()->value('created_at'),
                'documentCount' => File::query()->whereIn('category', ['pdf', 'text', 'code'])->count(),
                'mediaCount' => File::query()->whereIn('category', ['image', 'video', 'audio'])->count(),
            ];
        });

        return view('welcome', [
            'files' => $files,
            ...$metrics,
            'fileGroups' => $files->groupBy(fn (File $file): string => $file->created_at->format('Y-m')),
            'groupLabels' => $this->groupLabels($files),
        ]);
    }

    public function snapshot(): JsonResponse
    {
        $monthStart = Carbon::now()->startOfMonth();
        $metrics = app(VersionedCache::class)->remember('files', 'dashboard-metrics', now()->addSeconds(30), function () use ($monthStart): array {
            return [
                'fileCount' => File::query()->count(),
                'storageLabel' => FormatBytes::format((int) File::query()->sum('size')),
                'uploadedThisMonth' => File::query()->where('created_at', '>=', $monthStart)->count(),
                'lastActivity' => File::query()->latest()->value('created_at'),
                'documentCount' => File::query()->whereIn('category', ['pdf', 'text', 'code'])->count(),
                'mediaCount' => File::query()->whereIn('category', ['image', 'video', 'audio'])->count(),
            ];
        });
        $files = File::query()
            ->select(['uuid', 'original_name', 'extension', 'mime_type', 'category', 'upload_method', 'size', 'created_at'])
            ->latest()
            ->limit(20)
            ->get()
            ->map(fn (File $file): array => [
                'uuid' => $file->uuid,
                'name' => $file->original_name,
                'extension' => $file->extension,
                'mime' => $file->mime_type,
                'category' => $file->category,
                'upload_method' => $file->upload_method,
                'size' => $file->size,
                'created_at' => $file->created_at?->toIso8601String(),
                'date_label' => $file->created_at?->format('M j, Y · H:i'),
                'month_key' => $file->created_at?->format('Y-m'),
                'month_label' => $file->created_at?->format('F Y'),
                'download_url' => route('files.download', ['file' => $file->uuid]),
            ])
            ->values();

        return response()->json([
            'metrics' => $metrics,
            'files' => $files,
        ]);
    }

    public function archiveFiles(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'regex:/^\d{4}$/'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'extension' => ['nullable', 'regex:/^(?:__none__|[a-zA-Z0-9]{1,20})$/'],
            'upload_method' => ['nullable', 'in:api,manual'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = File::query()->select([
            'id',
            'uuid',
            'original_name',
            'extension',
            'mime_type',
            'category',
            'upload_method',
            'size',
            'created_at',
        ]);

        if (! empty($validated['q'])) {
            $search = $validated['q'];
            $query->where(function ($query) use ($search): void {
                $query->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('category', 'like', '%'.$search.'%');
            });
        }

        if (! empty($validated['year'])) {
            $query->whereYear('created_at', (int) $validated['year']);
        }

        if (! empty($validated['month'])) {
            $query->whereMonth('created_at', (int) $validated['month']);
        }

        if (! empty($validated['extension'])) {
            if ($validated['extension'] === '__none__') {
                $query->whereNull('extension');
            } else {
                $query->where('extension', strtolower($validated['extension']));
            }
        }

        if (! empty($validated['upload_method'])) {
            $query->where('upload_method', $validated['upload_method']);
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(100)->withQueryString();
        $driver = DB::connection()->getDriverName();
        $yearExpression = match ($driver) {
            'sqlite' => "strftime('%Y', created_at)",
            'mysql', 'mariadb' => 'YEAR(created_at)',
            'pgsql' => 'EXTRACT(YEAR FROM created_at)',
            default => null,
        };
        $years = app(VersionedCache::class)->remember('files', 'archive-years', now()->addMinutes(5), function () use ($yearExpression): array {
            if ($yearExpression === null) {
                return [];
            }

            return File::query()->selectRaw($yearExpression.' as year')
                ->distinct()
                ->orderByDesc('year')
                ->pluck('year')
                ->map(fn ($year): string => (string) $year)
                ->all();
        });
        $extensions = app(VersionedCache::class)->remember('files', 'archive-extensions', now()->addMinutes(5), function (): array {
            $extensions = File::query()
                ->whereNotNull('extension')
                ->where('extension', '!=', '')
                ->distinct()
                ->orderBy('extension')
                ->pluck('extension')
                ->map(fn ($extension): string => (string) $extension)
                ->all();

            if (File::query()->whereNull('extension')->exists()) {
                $extensions[] = '__none__';
            }

            return $extensions;
        });

        return response()->json([
            'records' => $page->getCollection()->map(fn (File $file): array => [
                'id' => $file->id,
                'uuid' => $file->uuid,
                'name' => $file->original_name,
                'extension' => $file->extension,
                'mime' => $file->mime_type,
                'category' => $file->category,
                'upload_method' => $file->upload_method,
                'size' => $file->size,
                'created_at' => $file->created_at?->toIso8601String(),
                'date_label' => $file->created_at?->format('M j, Y · H:i'),
                'month_key' => $file->created_at?->format('Y-m'),
                'month_label' => $file->created_at?->format('F Y'),
                'preview_url' => route('files.preview', ['file' => $file->uuid]),
                'download_url' => route('files.download', ['file' => $file->uuid]),
            ])->values(),
            'years' => $years,
            'extensions' => $extensions,
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
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
