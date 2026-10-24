<?php

namespace App\Http\Controllers;

use App\Models\CaseImage;
use App\Models\InvestigationCase;
use App\Services\PublicRecordId;
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
        return view('cases.index', [
            'cases' => InvestigationCase::query()->withCount(['people', 'categories'])->latest()->paginate(24),
            'classificationCounts' => InvestigationCase::query()
                ->select('classification', DB::raw('count(*) as aggregate'))
                ->groupBy('classification')
                ->pluck('aggregate', 'classification'),
        ]);
    }

    public function create(): View
    {
        return view('cases.form', ['caseFile' => new InvestigationCase]);
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
        $caseFile->load(['categories.images', 'people']);

        return view('cases.show', ['caseFile' => $caseFile]);
    }

    public function edit(InvestigationCase $caseFile): View
    {
        $caseFile->load('categories.images');

        return view('cases.form', ['caseFile' => $caseFile]);
    }

    public function update(Request $request, InvestigationCase $caseFile): RedirectResponse
    {
        $validated = $this->validateCase($request);
        $storedPaths = [];
        $deletedPaths = [];
        try {
            DB::transaction(function () use ($validated, $caseFile, &$storedPaths, &$deletedPaths): void {
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
                    $category->delete();
                }
                $this->saveCategories($caseFile, $validated['categories'] ?? [], $storedPaths);
            });
        } catch (Throwable $exception) {
            Storage::disk(config('vault.upload_disk'))->delete($storedPaths);
            throw $exception;
        }
        Storage::disk(config('vault.upload_disk'))->delete($deletedPaths);

        return redirect()->route('cases.show', $caseFile)->with('status', 'Case file updated.');
    }

    public function image(InvestigationCase $caseFile, CaseImage $image, bool $download = false): mixed
    {
        abort_unless($image->category()->where('case_file_id', $caseFile->id)->exists(), 404);
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
        Storage::disk(config('vault.upload_disk'))->delete($image->path);
        $image->delete();

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
        }
    }

    private function unknown(?string $value): string
    {
        $value = trim((string) $value);

        return $value === '' ? 'Unknown' : $value;
    }
}
