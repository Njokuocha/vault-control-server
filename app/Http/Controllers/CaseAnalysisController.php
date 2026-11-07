<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ActivityLog;
use App\Models\CaseAnalysisNote;
use App\Models\CaseAnalysisRecentAccess;
use App\Models\CaseCategory;
use App\Models\CaseImage;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\Report;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\GalleryCategoryAccess;
use App\Services\ReportAccess;
use App\Support\CaseAnalysisAccess;
use App\Support\CaseAnalysisCache;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CaseAnalysisController extends Controller
{
    public function index(Request $request, CaseAnalysisAccess $access)
    {
        $actor = $access->actor();
        $initialCaseId = $request->query('case');
        if ($initialCaseId !== null) {
            $validated = validator(['case' => $initialCaseId], ['case' => ['required', 'integer', 'min:1']])->validate();
            $caseFile = InvestigationCase::query()->findOrFail($validated['case']);
            $access->authorizeCase($caseFile, $actor);
        }

        return view('case-analysis.index', [
            'isAdmin' => $access->isAdministrator($actor),
            'initialCaseId' => $initialCaseId === null ? null : (int) $initialCaseId,
            'endpoints' => $this->endpoints(),
        ]);
    }

    public function search(Request $request, CaseAnalysisAccess $access): JsonResponse
    {
        $access->actor();
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $term = trim($validated['q']);
        $cases = InvestigationCase::query()
            ->where(function (Builder $query) use ($term): void {
                $query->where('public_id', 'like', '%'.$term.'%')
                    ->orWhere('title', 'like', '%'.$term.'%');
            })
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate(10, ['id', 'public_id', 'title', 'case_status', 'classification', 'created_at'], 'page', (int) ($validated['page'] ?? 1));

        return response()->json([
            'items' => $cases->getCollection()->map(fn (InvestigationCase $case): array => $this->caseListItem($case))->values(),
            'page' => $cases->currentPage(),
            'last_page' => $cases->lastPage(),
            'total' => $cases->total(),
        ]);
    }

    public function import(
        InvestigationCase $caseFile,
        CaseAnalysisAccess $access,
        CaseAnalysisCache $cache,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);
        $this->recordRecentAccess($caseFile, $actor);

        return response()->json([
            'case' => $cache->rememberSummary($caseFile->getKey(), fn (): array => $this->caseSummary($caseFile->fresh())),
        ]);
    }

    public function summary(
        InvestigationCase $caseFile,
        CaseAnalysisAccess $access,
        CaseAnalysisCache $cache,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);

        return response()->json([
            'case' => $cache->rememberSummary($caseFile->getKey(), fn (): array => $this->caseSummary($caseFile->fresh())),
        ]);
    }

    public function primary(
        InvestigationCase $caseFile,
        CaseAnalysisAccess $access,
        ReportAccess $reportAccess,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);

        $reports = $reportAccess->visibleQuery($actor)
            ->whereHas('cases', fn (Builder $query) => $query->whereKey($caseFile->getKey()))
            ->select(['id', 'uuid', 'body', 'reporter_type', 'reporter_id', 'is_hidden', 'created_at'])
            ->latest('created_at')
            ->limit(30)
            ->get();
        $staffAuthors = $reports->where('reporter_type', User::class)->pluck('reporter_id')->unique();
        $adminAuthors = $reports->where('reporter_type', Admin::class)->pluck('reporter_id')->unique();
        $users = $staffAuthors->isEmpty()
            ? collect()
            : User::query()->whereKey($staffAuthors)->get(['id', 'name'])->keyBy('id');
        $admins = $adminAuthors->isEmpty()
            ? collect()
            : Admin::query()->whereKey($adminAuthors)->get(['id', 'name'])->keyBy('id');

        $people = $caseFile->people()
            ->select(['people.id', 'people.public_id', 'people.name', 'people.occupation', 'people.flag'])
            ->orderBy('people.name')
            ->limit(50)
            ->get();
        $creator = $caseFile->creator()->first(['id', 'name', 'department']);
        $isAdmin = $access->isAdministrator($actor);
        $reportRoute = $isAdmin ? 'admin.reports.index' : 'cfm.reports.index';
        $peopleRoute = $isAdmin ? 'people.show' : null;

        return response()->json([
            'reports_truncated' => $reports->count() === 30,
            'people_truncated' => $people->count() === 50,
            'reports' => $reports->map(function (Report $report) use ($users, $admins, $reportRoute): array {
                $author = match ($report->reporter_type) {
                    User::class => $users->get($report->reporter_id),
                    Admin::class => $admins->get($report->reporter_id),
                    default => null,
                };
                $excerpt = Str::of($report->body)->squish()->limit(110)->toString();

                return [
                    'id' => 'report-'.$report->getKey(),
                    'record_id' => $report->getKey(),
                    'type' => 'report',
                    'title' => $excerpt !== '' ? $excerpt : 'Report '.$report->uuid,
                    'summary' => Str::of($report->body)->squish()->limit(260)->toString(),
                    'author' => $author?->name ?? 'Unknown author',
                    'author_type' => $report->reporter_type === Admin::class ? 'Admin' : 'Staff',
                    'status' => $report->is_hidden ? 'Restricted' : 'Visible',
                    'created_at' => $report->created_at?->toIso8601String(),
                    'url' => route($reportRoute, ['report' => $report->uuid]),
                ];
            })->values(),
            'people' => $people->map(fn (Person $person): array => [
                'id' => 'person-'.$person->getKey(),
                'record_id' => $person->getKey(),
                'type' => 'person',
                'title' => $person->name,
                'public_id' => $person->public_id,
                'summary' => collect([$person->occupation, $person->flag])->filter(fn ($value): bool => filled($value) && $value !== 'Unknown')->implode(' · '),
                'url' => $peopleRoute === null ? null : route($peopleRoute, $person),
            ])->values(),
            'staff' => $creator === null ? [] : [[
                'id' => 'creator-'.$creator->getKey(),
                'record_id' => $creator->getKey(),
                'type' => 'staff',
                'title' => $creator->name,
                'summary' => 'Case creator',
                'department' => strtoupper((string) $creator->department),
                'role' => 'Case creator',
            ]],
        ]);
    }

    public function secondary(
        Request $request,
        InvestigationCase $caseFile,
        CaseAnalysisAccess $access,
        GalleryCategoryAccess $galleryAccess,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);
        $visibleCategoryIds = $galleryAccess->visibleCaseCategoryIds($caseFile->getKey(), $request);
        $images = collect();
        if ($visibleCategoryIds !== []) {
            $images = CaseImage::query()
                ->whereHas('category', fn (Builder $query) => $query->where('case_file_id', $caseFile->getKey()))
                ->where(function (Builder $query) use ($visibleCategoryIds): void {
                    $query->whereIn('case_category_id', $visibleCategoryIds)
                        ->orWhereHas('categories', fn (Builder $categories) => $categories->whereIn('case_categories.id', $visibleCategoryIds));
                })
                ->with([
                    'category:id,case_file_id,title',
                    'categories:id,title',
                    'file:id,extension,mime_type',
                ])
                ->latest('created_at')
                ->limit(100)
                ->get();
        }
        $isAdmin = $access->isAdministrator($actor);
        $caseRoute = $isAdmin ? 'cases.image' : 'cfm.cases.image';
        $attachments = $images->map(function (CaseImage $image) use ($visibleCategoryIds, $caseRoute): array {
            $categoryNames = $image->categories
                ->filter(fn (CaseCategory $category): bool => in_array($category->getKey(), $visibleCategoryIds, true))
                ->pluck('title')
                ->unique()
                ->values();

            return [
                'id' => 'file-'.$image->getKey(),
                'record_id' => $image->getKey(),
                'type' => 'attachment',
                'title' => $image->original_name,
                'summary' => collect([$image->type, $image->file?->mime_type])->filter()->unique()->implode(' · '),
                'file_type' => $image->type ?? $image->file?->extension ?? 'file',
                'categories' => $categoryNames,
                'created_at' => $image->created_at?->toIso8601String(),
                'url' => route($caseRoute, ['caseFile' => $image->category->case_file_id, 'image' => $image]),
            ];
        });

        $activity = ActivityLog::query()
            ->where('category', 'case-management')
            ->whereIn('action', ['case.created', 'case.updated', 'case.deleted'])
            ->whereJsonContains('details->case_id', $caseFile->getKey())
            ->latest('occurred_at')
            ->limit(5)
            ->get();
        $userIds = $activity->where('actor_type', 'staff')->pluck('actor_id')->filter()->unique();
        $actorDepartments = $userIds->isEmpty()
            ? collect()
            : User::query()->whereKey($userIds)->pluck('department', 'id');

        return response()->json([
            'attachments' => $attachments,
            'attachments_truncated' => $images->count() === 100,
            'updates' => $activity->map(fn (ActivityLog $entry): array => [
                'id' => 'update-'.$entry->getKey(),
                'record_id' => $entry->getKey(),
                'type' => 'update',
                'title' => $entry->summary,
                'summary' => $entry->summary,
                'author' => $entry->actor_name,
                'author_type' => ucfirst((string) ($entry->actor_type ?? 'system')),
                'department' => $entry->actor_type === 'staff'
                    ? strtoupper((string) $actorDepartments->get((int) $entry->actor_id))
                    : ($entry->actor_type === 'admin' ? 'ADMINISTRATION' : null),
                'created_at' => $entry->occurred_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function recent(CaseAnalysisAccess $access): JsonResponse
    {
        $actor = $access->actor();
        $query = $this->recentQuery($actor);
        $items = $query->with(['caseFile:id,public_id,title,case_status'])
            ->orderByDesc('last_accessed_at')
            ->limit(10)
            ->get()
            ->filter(fn (CaseAnalysisRecentAccess $item): bool => $item->caseFile !== null)
            ->map(fn (CaseAnalysisRecentAccess $item): array => [
                'id' => $item->case_file_id,
                'public_id' => $item->caseFile->public_id,
                'title' => $item->caseFile->title,
                'status' => $item->caseFile->case_status,
                'last_accessed_at' => $item->last_accessed_at?->toIso8601String(),
            ])->values();

        return response()->json(['items' => $items]);
    }

    public function notes(InvestigationCase $caseFile, CaseAnalysisAccess $access): JsonResponse
    {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);

        return response()->json([
            'items' => CaseAnalysisNote::query()
                ->where('case_file_id', $caseFile->getKey())
                ->latest('updated_at')
                ->limit(100)
                ->get()
                ->map(fn (CaseAnalysisNote $note): array => $this->notePayload($note, $actor, $access))
                ->values(),
        ]);
    }

    public function storeNote(
        Request $request,
        InvestigationCase $caseFile,
        CaseAnalysisAccess $access,
        ActivityLogger $logger,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);
        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $note = CaseAnalysisNote::query()->create([
            'case_file_id' => $caseFile->getKey(),
            'author_type' => $actor::class,
            'author_id' => $actor->getAuthIdentifier(),
            'author_name' => (string) ($actor->name ?? $actor->getAuthIdentifier()),
            'body' => trim($validated['body']),
        ]);
        $logger->record('case-analysis', 'note.created', 'A shared case analysis note was created.', [
            'case_id' => $caseFile->getKey(),
            'note_id' => $note->getKey(),
        ], $actor, $request);

        return response()->json(['note' => $this->notePayload($note, $actor, $access)], 201);
    }

    public function updateNote(
        Request $request,
        InvestigationCase $caseFile,
        CaseAnalysisNote $note,
        CaseAnalysisAccess $access,
        CaseAnalysisCache $cache,
        ActivityLogger $logger,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);
        abort_unless((int) $note->case_file_id === (int) $caseFile->getKey(), 404);
        abort_unless($access->isAdministrator($actor) || $access->isNoteAuthor($note, $actor), 403);
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
        $updated = CaseAnalysisNote::query()
            ->whereKey($note->getKey())
            ->where('version', $validated['version'])
            ->update([
                'body' => trim($validated['body']),
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
        if ($updated !== 1) {
            return response()->json(['message' => 'This note changed since you opened it. Reload before editing.'], 409);
        }
        $cache->invalidate($caseFile->getKey());
        $note->refresh();
        $logger->record('case-analysis', 'note.updated', 'A shared case analysis note was updated.', [
            'case_id' => $caseFile->getKey(),
            'note_id' => $note->getKey(),
        ], $actor, $request);

        return response()->json(['note' => $this->notePayload($note, $actor, $access)]);
    }

    public function destroyNote(
        Request $request,
        InvestigationCase $caseFile,
        CaseAnalysisNote $note,
        CaseAnalysisAccess $access,
        CaseAnalysisCache $cache,
        ActivityLogger $logger,
    ): JsonResponse {
        $actor = $access->actor();
        $access->authorizeCase($caseFile, $actor);
        abort_unless((int) $note->case_file_id === (int) $caseFile->getKey(), 404);
        abort_unless($access->isAdministrator($actor) || $access->isNoteAuthor($note, $actor), 403);
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $deleted = CaseAnalysisNote::query()
            ->whereKey($note->getKey())
            ->where('version', $validated['version'])
            ->delete();
        if ($deleted !== 1) {
            return response()->json(['message' => 'This note changed since you opened it. Reload before deleting.'], 409);
        }
        $cache->invalidate($caseFile->getKey());
        $logger->record('case-analysis', 'note.deleted', 'A shared case analysis note was deleted.', [
            'case_id' => $caseFile->getKey(),
            'note_id' => $note->getKey(),
        ], $actor, $request);

        return response()->json(['status' => 'deleted']);
    }

    private function caseSummary(InvestigationCase $caseFile): array
    {
        return [
            'id' => $caseFile->getKey(),
            'public_id' => $caseFile->public_id,
            'title' => $caseFile->title,
            'status' => $caseFile->case_status,
            'classification' => $caseFile->classification,
            'opened_at' => $caseFile->created_at?->toIso8601String(),
            'updated_at' => $caseFile->updated_at?->toIso8601String(),
        ];
    }

    private function caseListItem(InvestigationCase $caseFile): array
    {
        return [
            'id' => $caseFile->getKey(),
            'public_id' => $caseFile->public_id,
            'title' => $caseFile->title,
            'status' => $caseFile->case_status,
            'classification' => $caseFile->classification,
            'created_at' => $caseFile->created_at?->toIso8601String(),
        ];
    }

    private function notePayload(CaseAnalysisNote $note, Authenticatable $actor, CaseAnalysisAccess $access): array
    {
        return [
            'id' => $note->getKey(),
            'body' => $note->body,
            'author' => $note->author_name,
            'author_type' => $note->author_type === Admin::class ? 'Admin' : 'Staff',
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
            'version' => $note->version,
            'can_edit' => $access->isAdministrator($actor) || $access->isNoteAuthor($note, $actor),
        ];
    }

    private function recordRecentAccess(InvestigationCase $caseFile, Authenticatable $actor): void
    {
        $isAdmin = $actor instanceof Admin;
        $identityColumn = $isAdmin ? 'admin_id' : 'user_id';
        $now = now();
        DB::table('case_analysis_recent_accesses')->upsert([[
            'case_file_id' => $caseFile->getKey(),
            'user_id' => $isAdmin ? null : $actor->getAuthIdentifier(),
            'admin_id' => $isAdmin ? $actor->getAuthIdentifier() : null,
            'last_accessed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]], [$identityColumn, 'case_file_id'], ['last_accessed_at', 'updated_at']);

        $excessIds = $this->recentQuery($actor)
            ->orderByDesc('last_accessed_at')
            ->skip(10)
            ->take(1000)
            ->pluck('id');
        if ($excessIds->isNotEmpty()) {
            CaseAnalysisRecentAccess::query()->whereIn('id', $excessIds)->delete();
        }
    }

    private function recentQuery(Authenticatable $actor): Builder
    {
        return CaseAnalysisRecentAccess::query()->where(
            $actor instanceof Admin ? 'admin_id' : 'user_id',
            $actor->getAuthIdentifier(),
        );
    }

    private function endpoints(): array
    {
        $prefix = request()->routeIs('cfm.*') ? 'cfm.' : 'admin.';

        return [
            'search' => route($prefix.'case-analysis.search'),
            'recent' => route($prefix.'case-analysis.recent'),
            'import' => route($prefix.'case-analysis.import', ['caseFile' => '__CASE__']),
            'summary' => route($prefix.'case-analysis.summary', ['caseFile' => '__CASE__']),
            'primary' => route($prefix.'case-analysis.primary', ['caseFile' => '__CASE__']),
            'secondary' => route($prefix.'case-analysis.secondary', ['caseFile' => '__CASE__']),
            'notes' => route($prefix.'case-analysis.notes.index', ['caseFile' => '__CASE__']),
            'noteStore' => route($prefix.'case-analysis.notes.store', ['caseFile' => '__CASE__']),
            'note' => route($prefix.'case-analysis.notes.update', ['caseFile' => '__CASE__', 'note' => '__NOTE__']),
            'noteDelete' => route($prefix.'case-analysis.notes.destroy', ['caseFile' => '__CASE__', 'note' => '__NOTE__']),
        ];
    }
}
