<?php

namespace App\Http\Controllers;

use App\Models\CaseImage;
use App\Models\File;
use App\Models\InvestigationCase;
use App\Models\VideoUpload;
use App\Http\Controllers\FileController;
use App\Services\PublicRecordId;
use App\Services\SettingService;
use App\Support\VersionedCache;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class InvestigationCaseController extends Controller
{
    public function index(): View
    {
        $page = request()->integer('page', 1);
        $cache = app(VersionedCache::class);

        return view('cases.index', [
            'cases' => $cache->remember(
                'cases',
                "index:page:{$page}",
                now()->addSeconds(30),
                fn () => InvestigationCase::query()->withCount(['people', 'categories'])->latest()->paginate(24),
            ),
            'classificationCounts' => $cache->remember(
                'cases',
                'classification-counts',
                now()->addSeconds(30),
                fn () => InvestigationCase::query()
                    ->select('classification', DB::raw('count(*) as aggregate'))
                    ->groupBy('classification')
                    ->pluck('aggregate', 'classification'),
            ),
        ]);
    }

    public function create(SettingService $settings): View
    {
        return view('cases.form', [
            'caseFile' => new InvestigationCase,
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
        ]);
    }

    public function store(Request $request, PublicRecordId $ids): RedirectResponse
    {
        $validated = $this->validateCase($request);
        $storedPaths = [];

        try {
            $caseFile = DB::transaction(function () use ($validated, $ids, &$storedPaths): InvestigationCase {
                $caseFile = InvestigationCase::query()->create([
                    'public_id' => $ids->generate(InvestigationCase::class, 'CSE'),
                    'title' => $this->unknown($validated['title'] ?? null),
                    'classification' => $validated['classification'] ?? 'CLASSIFIED',
                    'case_status' => $validated['case_status'] ?? 'OPEN',
                    'narrative' => $this->unknown($validated['narrative'] ?? null),
                    'comment' => $this->unknown($validated['comment'] ?? null),
                ]);
                $this->saveCategories($caseFile, $validated['categories'] ?? [], $storedPaths);

                return $caseFile;
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }

        return redirect()->route('cases.show', $caseFile)->with('status', 'Case file created.');
    }

    public function show(InvestigationCase $caseFile): View
    {
        $caseFile = app(VersionedCache::class)->remember(
            'cases',
            'record:'.$caseFile->getKey(),
            now()->addSeconds(30),
            fn (): InvestigationCase => $caseFile->load(['categories.images', 'people']),
        );

        return view('cases.show', ['caseFile' => $caseFile]);
    }

    public function edit(InvestigationCase $caseFile, SettingService $settings): View
    {
        $caseFile->load('categories.images');

        return view('cases.form', [
            'caseFile' => $caseFile,
            'maxUploadMb' => $settings->maxUploadMegabytes(),
            'maxVideoUploadMb' => $settings->maxVideoUploadMegabytes(),
        ]);
    }

    public function update(Request $request, InvestigationCase $caseFile): RedirectResponse
    {
        $validated = $this->validateCase($request);
        $storedPaths = [];
        $deletedPaths = [];
        $deletedFileIds = [];
        $deletedUploadIds = [];
        try {
            DB::transaction(function () use ($validated, $caseFile, &$storedPaths, &$deletedPaths, &$deletedFileIds, &$deletedUploadIds): void {
                $caseFile->update([
                    'title' => $this->unknown($validated['title'] ?? null),
                    'classification' => $validated['classification'] ?? 'CLASSIFIED',
                    'case_status' => $validated['case_status'] ?? 'OPEN',
                    'narrative' => $this->unknown($validated['narrative'] ?? null),
                    'comment' => $this->unknown($validated['comment'] ?? null),
                ]);
                foreach ($validated['delete_categories'] ?? [] as $categoryId) {
                    $category = $caseFile->categories()->with('images')->whereKey($categoryId)->first();
                    if ($category === null) {
                        continue;
                    }
                    $deletedPaths = [...$deletedPaths, ...$category->images->pluck('path')->all()];
                    $deletedFileIds = [...$deletedFileIds, ...$category->images->pluck('file_id')->filter()->all()];
                    $category->delete();
                }
                if ($deletedFileIds !== []) {
                    $deletedUploadIds = VideoUpload::query()->whereIn('file_id', $deletedFileIds)->pluck('id')->all();
                }
                $this->saveCategories($caseFile, $validated['categories'] ?? [], $storedPaths);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }
        Storage::disk(config('vault.upload_disk'))->delete($deletedPaths);
        if ($deletedFileIds !== []) {
            File::query()->whereIn('id', $deletedFileIds)->get()->each(
                static fn (File $file) => $file->delete(),
            );
        }
        if ($deletedUploadIds !== []) {
            VideoUpload::query()->whereIn('id', $deletedUploadIds)->delete();
        }

        return redirect()->route('cases.show', $caseFile)->with('status', 'Case file updated.');
    }

    public function image(InvestigationCase $caseFile, CaseImage $image, bool $download = false): mixed
    {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        if ($image->type === 'video') {
            $file = $image->file;
            abort_unless($file instanceof File, 404);

            return $download
                ? app(FileController::class)->download($file)
                : app(FileController::class)->stream(request(), $file);
        }
        abort_unless(Storage::disk(config('vault.upload_disk'))->exists($image->path), 404);

        return Storage::disk(config('vault.upload_disk'))->response($image->path, null, [
            'Content-Disposition' => $download ? 'attachment' : 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroyImage(InvestigationCase $caseFile, CaseImage $image): JsonResponse
    {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
        $file = $image->file;
        $videoUploadId = $file
            ? VideoUpload::query()->where('file_id', $file->id)->value('id')
            : null;
        Storage::disk(config('vault.upload_disk'))->delete($image->path);
        $image->delete();
        if ($file) {
            $file->delete();
            if ($videoUploadId !== null) {
                VideoUpload::query()->whereKey($videoUploadId)->delete();
            }
        }

        return response()->json(['status' => 'deleted']);
    }

    private function validateCase(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'classification' => ['nullable', Rule::in(['CLASSIFIED', 'HIGHLY SENSITIVE', 'ACCESSIBLE'])],
            'case_status' => ['nullable', Rule::in(['OPEN', 'UNDER REVIEW', 'CLOSED'])],
            'narrative' => ['nullable', 'string', 'max:50000'],
            'comment' => ['nullable', 'string', 'max:20000'],
            'categories' => ['nullable', 'array', 'max:30'],
            'categories.*.id' => ['nullable', 'integer', 'exists:case_categories,id'],
            'categories.*.title' => ['nullable', 'string', 'max:255'],
            'categories.*.description' => ['nullable', 'string', 'max:5000'],
            'categories.*.images' => ['nullable', 'array', 'max:40'],
            'categories.*.images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'categories.*.video_upload_tokens' => ['nullable', 'array', 'max:20'],
            'categories.*.video_upload_tokens.*' => ['required', 'uuid', 'distinct', 'exists:video_uploads,token'],
            'delete_categories' => ['nullable', 'array'],
            'delete_categories.*' => ['integer', 'exists:case_categories,id'],
        ]);
    }

    private function saveCategories(InvestigationCase $caseFile, array $categories, array &$storedPaths): void
    {
        foreach ($categories as $index => $categoryData) {
            $attributes = [
                'title' => $this->unknown($categoryData['title'] ?? null),
                'description' => $this->unknown($categoryData['description'] ?? null),
                'sort_order' => $index,
            ];
            $category = isset($categoryData['id'])
                ? $caseFile->categories()->whereKey($categoryData['id'])->firstOrFail()
                : $caseFile->categories()->make();
            $category->fill($attributes);
            $caseFile->categories()->save($category);

            foreach ($categoryData['images'] ?? [] as $image) {
                if (! $image instanceof UploadedFile) {
                    continue;
                }

                $extension = strtolower($image->guessExtension() ?: 'img');
                $path = $image->storeAs('cases/'.$caseFile->id.'/evidence', Str::uuid().'.'.$extension, config('vault.upload_disk'));
                if (! is_string($path)) {
                    throw new \RuntimeException('Unable to store the case evidence image.');
                }
                $storedPaths[] = $path;
                $category->images()->create([
                    'path' => $path,
                    'original_name' => Str::limit(basename(str_replace('\\', '/', $image->getClientOriginalName())), 255, ''),
                ]);
            }

            $this->attachVideosToCategory($category, $categoryData['video_upload_tokens'] ?? []);
        }
    }

    private function attachVideosToCategory(\App\Models\CaseCategory $category, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }

        $uploads = VideoUpload::query()
            ->whereIn('token', $tokens)
            ->where('status', 'completed')
            ->whereNull('attached_at')
            ->with('file')
            ->lockForUpdate()
            ->get();

        if ($uploads->count() !== count($tokens) || $uploads->contains(
            fn (VideoUpload $upload): bool => ! $upload->file || $upload->file->category !== 'video'
        )) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'categories' => 'One or more selected videos are not available to attach.',
            ]);
        }

        foreach ($uploads as $upload) {
            $category->images()->create([
                'path' => $upload->file->stored_path,
                'original_name' => $upload->file->original_name,
                'type' => 'video',
                'file_id' => $upload->file_id,
            ]);
            $upload->forceFill(['attached_at' => now()])->save();
        }
    }

    private function unknown(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'Unknown' : $value;
    }
}
