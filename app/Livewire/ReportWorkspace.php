<?php

namespace App\Livewire;

use App\Events\AgencyReportUpdated;
use App\Models\Admin;
use App\Models\InvestigationCase;
use App\Models\Person;
use App\Models\Report;
use App\Models\ReportComment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ReportAccess;
use App\Support\VersionedCache;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class ReportWorkspace extends Component
{
    use WithFileUploads;

    #[Url(as: 'report', history: true)]
    public ?string $selectedReport = null;

    public string $activeSection = 'report';

    public bool $composerOpen = false;

    public ?string $editingUuid = null;

    public string $body = '';

    /** @var array<int, TemporaryUploadedFile> */
    public array $uploads = [];

    /** @var array<int, int|string> */
    public array $caseIds = [];

    /** @var array<int, int|string> */
    public array $personIds = [];

    /** @var array<int, int|string> */
    public array $removeMediaIds = [];

    public string $caseSearch = '';

    public string $personSearch = '';

    public string $commentBody = '';

    public string $replyBody = '';

    public ?int $replyingToId = null;

    /** @var array<int, int|string> */
    public array $expandedReplyIds = [];

    public int $commentLimit = 15;

    public int $reportLimit = 30;

    public function mount(ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $visibleReports = $access->visibleQuery($actor);
        $this->selectedReport ??= $visibleReports->latest('updated_at')->value('uuid');
        session(['report_system_last_seen_id' => (int) Report::query()->max('id')]);
    }

    public function selectReport(string $uuid, ReportAccess $access): void
    {
        $this->authorizeWorkspace($access);
        $report = $access->visibleQuery()->where('uuid', $uuid)->firstOrFail();
        $this->selectedReport = $report->uuid;
        $this->activeSection = 'report';
        $this->commentLimit = 15;
        $this->replyingToId = null;
        $this->replyBody = '';
        $this->expandedReplyIds = [];
    }

    public function showSection(string $section, ReportAccess $access): void
    {
        $this->authorizeWorkspace($access);
        abort_unless(in_array($section, ['report', 'comments'], true), 404);
        $this->activeSection = $section;
    }

    public function openComposer(ReportAccess $access): void
    {
        $this->authorizeWorkspace($access);
        $this->resetComposer();
        $this->composerOpen = true;
    }

    public function editReport(string $uuid, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $access->visibleQuery($actor)->where('uuid', $uuid)->firstOrFail();
        abort_unless($access->canManage($report, $actor), 403);

        $this->resetComposer();
        $this->editingUuid = $report->uuid;
        $this->body = $report->body;
        if ($access->canLinkCases($actor)) {
            $this->caseIds = $report->cases()->pluck('case_files.id')->map(fn ($id): string => (string) $id)->all();
        }
        if ($access->canLinkPeople($actor)) {
            $this->personIds = $report->people()->pluck('people.id')->map(fn ($id): string => (string) $id)->all();
        }
        $this->composerOpen = true;
    }

    public function saveReport(ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $existing = $this->editingUuid === null
            ? null
            : $access->visibleQuery($actor)->where('uuid', $this->editingUuid)->firstOrFail();
        if ($existing !== null) {
            abort_unless($access->canManage($existing, $actor), 403);
        }

        $validated = $this->validate([
            'body' => ['required', 'string', 'max:20000'],
            'uploads' => ['array', 'max:12'],
            'uploads.*' => [
                'file',
                'max:51200',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/avif,video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska',
            ],
            'caseIds' => ['array', 'max:20'],
            'caseIds.*' => ['integer', 'distinct', Rule::exists('case_files', 'id')],
            'personIds' => ['array', 'max:20'],
            'personIds.*' => ['integer', 'distinct', Rule::exists('people', 'id')],
            'removeMediaIds' => ['array'],
            'removeMediaIds.*' => ['integer', 'distinct'],
        ]);
        abort_unless($access->canLinkCases($actor) || $this->caseIds === [], 403);
        abort_unless($access->canLinkPeople($actor) || $this->personIds === [], 403);

        $storedPaths = [];
        $removedPaths = [];
        try {
            $report = DB::transaction(function () use ($validated, $actor, $access, $existing, &$storedPaths, &$removedPaths): Report {
                $report = $existing ?? Report::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'body' => trim($validated['body']),
                    'reporter_type' => $actor::class,
                    'reporter_id' => $actor->getAuthIdentifier(),
                ]);
                if ($existing !== null) {
                    $report->update(['body' => trim($validated['body'])]);
                }

                if ($access->canLinkCases($actor)) {
                    $report->cases()->sync(array_map('intval', $validated['caseIds']));
                }
                if ($access->canLinkPeople($actor)) {
                    $report->people()->sync(array_map('intval', $validated['personIds']));
                }

                if ($validated['removeMediaIds'] !== []) {
                    $mediaToRemove = $report->media()->whereIn('id', $validated['removeMediaIds'])->get();
                    abort_if($mediaToRemove->count() !== count(array_unique(array_map('intval', $validated['removeMediaIds']))), 403);
                    $removedPaths = $mediaToRemove->pluck('path')->all();
                    $report->media()->whereIn('id', $mediaToRemove->modelKeys())->delete();
                }

                foreach ($validated['uploads'] as $upload) {
                    $path = $upload->store('reports/'.$report->id, config('vault.upload_disk', 'vault'));
                    if ($path === false) {
                        throw new \RuntimeException('A report attachment could not be stored on private storage.');
                    }
                    $storedPaths[] = $path;
                    $mimeType = (string) $upload->getMimeType();
                    $report->media()->create([
                        'path' => $path,
                        'original_name' => Str::limit(basename($upload->getClientOriginalName()), 255, ''),
                        'mime_type' => $mimeType,
                        'media_type' => str_starts_with($mimeType, 'image/') ? 'image' : 'video',
                        'size' => $upload->getSize(),
                        'sort_order' => (int) $report->media()->max('sort_order') + 1,
                    ]);
                }

                return $report;
            });
        } catch (Throwable $exception) {
            if ($storedPaths !== []) {
                Storage::disk(config('vault.upload_disk', 'vault'))->delete($storedPaths);
            }
            throw $exception;
        }

        if ($removedPaths !== [] && ! Storage::disk(config('vault.upload_disk', 'vault'))->delete($removedPaths)) {
            throw new \RuntimeException('The report was saved, but removed attachments could not be cleared from private storage.');
        }

        app(VersionedCache::class)->bump('reports');
        app(ActivityLogger::class)->record(
            'report-system',
            $existing === null ? 'report.created' : 'report.updated',
            $existing === null ? 'An agency report was published.' : 'An agency report was updated.',
            [
                'report_id' => $report->getKey(),
                'report_uuid' => $report->uuid,
                'attachment_count' => count($validated['uploads']),
                'case_links' => $access->canLinkCases($actor) ? count($validated['caseIds']) : null,
                'person_links' => $access->canLinkPeople($actor) ? count($validated['personIds']) : null,
            ],
            $actor,
        );
        event(new AgencyReportUpdated($report->id, $existing === null ? 'created' : 'updated'));

        $this->selectedReport = $report->uuid;
        $this->activeSection = 'report';
        $this->composerOpen = false;
        $this->resetComposer();
        $this->dispatch('alert', type: 'success', message: $existing === null ? 'Report published to the agency.' : 'Report updated.');
    }

    public function addComment(ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $this->selectedVisibleReport($access, $actor);
        $validated = $this->validate(['commentBody' => ['required', 'string', 'max:5000']]);

        $comment = $report->comments()->create([
            'author_type' => $actor::class,
            'author_id' => $actor->getAuthIdentifier(),
            'body' => trim($validated['commentBody']),
        ]);
        app(ActivityLogger::class)->record('report-system', 'report.comment-created', 'A comment was added to an agency report.', [
            'report_id' => $report->getKey(),
            'report_uuid' => $report->uuid,
            'comment_id' => $comment->getKey(),
        ], $actor);
        event(new AgencyReportUpdated($report->id, 'commented'));

        $this->reset('commentBody');
        $this->activeSection = 'comments';
        $this->dispatch('alert', type: 'success', message: 'Comment added.');
    }

    public function addReply(ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $this->selectedVisibleReport($access, $actor);
        abort_unless($this->canReply($report, $access, $actor), 403);
        abort_unless($this->replyingToId !== null, 403);
        $validated = $this->validate(['replyBody' => ['required', 'string', 'max:5000']]);
        $parent = $report->comments()->whereKey($this->replyingToId)->whereNull('parent_id')->firstOrFail();

        $reply = $report->comments()->create([
            'parent_id' => $parent->id,
            'author_type' => $actor::class,
            'author_id' => $actor->getAuthIdentifier(),
            'body' => trim($validated['replyBody']),
        ]);
        app(ActivityLogger::class)->record('report-system', 'report.reply-created', 'A privileged reply was added to an agency report comment.', [
            'report_id' => $report->getKey(),
            'report_uuid' => $report->uuid,
            'comment_id' => $reply->getKey(),
            'parent_comment_id' => $parent->id,
        ], $actor);
        event(new AgencyReportUpdated($report->id, 'replied'));

        $this->replyingToId = null;
        $this->reset('replyBody');
        $this->dispatch('alert', type: 'success', message: 'Reply added.');
    }

    public function toggleReplies(int $commentId, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $this->selectedVisibleReport($access, $actor);
        $comment = $report->comments()->whereKey($commentId)->whereNull('parent_id')->firstOrFail();
        abort_if(! $comment->replies()->exists(), 404);
        if (in_array($comment->id, array_map('intval', $this->expandedReplyIds), true)) {
            $this->expandedReplyIds = array_values(array_filter(
                array_map('intval', $this->expandedReplyIds),
                fn (int $id): bool => $id !== $comment->id,
            ));

            return;
        }
        $this->expandedReplyIds[] = $comment->id;
    }

    public function toggleMediaRemoval(int $mediaId, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $this->selectedVisibleReport($access, $actor);
        abort_unless($access->canManage($report, $actor), 403);
        $report->media()->findOrFail($mediaId);

        if (in_array($mediaId, array_map('intval', $this->removeMediaIds), true)) {
            $this->removeMediaIds = array_values(array_filter(
                array_map('intval', $this->removeMediaIds),
                fn (int $id): bool => $id !== $mediaId,
            ));

            return;
        }
        $this->removeMediaIds[] = $mediaId;
    }

    public function loadMoreComments(ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $this->selectedVisibleReport($access, $actor);
        $this->commentLimit += 15;
    }

    public function loadMoreReports(ReportAccess $access): void
    {
        $this->authorizeWorkspace($access);
        $this->reportLimit += 30;
    }

    #[On('agency-report-updated')]
    public function refreshReports(): void
    {
        $this->authorizeWorkspace(app(ReportAccess::class));
    }

    public function render(ReportAccess $access): View
    {
        $actor = $this->authorizeWorkspace($access);
        $actorCacheKey = hash('sha256', $actor::class.':'.$actor->getAuthIdentifier());
        $cache = app(VersionedCache::class);
        $totalReportCount = $cache->remember(
            'reports',
            "workspace:{$actorCacheKey}:count",
            now()->addSeconds(20),
            fn (): int => $access->visibleQuery($actor)->count(),
        );
        $reports = $cache->remember(
            'reports',
            "workspace:{$actorCacheKey}:feed:{$this->reportLimit}",
            now()->addSeconds(20),
            function () use ($access, $actor): Collection {
                $reports = $access->visibleQuery($actor)
                    ->with([
                        'reporter' => fn (MorphTo $relation) => $relation->morphWith([
                            User::class => ['departmentRecord'],
                        ]),
                        'latestComment.author',
                    ])
                    ->withCount(['comments', 'media'])
                    ->latest('updated_at')
                    ->limit($this->reportLimit)
                    ->get();

                $this->attachCommenterPreviews($reports);

                return $reports;
            },
        );

        if ($this->selectedReport === null && $reports->isNotEmpty()) {
            $this->selectedReport = $reports->first()->uuid;
        }
        $selected = $this->selectedReport === null
            ? null
            : $cache->remember(
                'reports',
                "workspace:{$actorCacheKey}:detail:{$this->selectedReport}",
                now()->addSeconds(20),
                fn (): ?Report => $access->visibleQuery($actor)
                    ->with([
                        'reporter' => fn (MorphTo $relation) => $relation->morphWith([
                            User::class => ['departmentRecord'],
                        ]),
                        'media',
                        'cases',
                        'people',
                    ])
                    ->withCount('comments')
                    ->where('uuid', $this->selectedReport)
                    ->first(),
            );
        if ($selected === null && $reports->isNotEmpty()) {
            $selected = $cache->remember(
                'reports',
                "workspace:{$actorCacheKey}:detail:{$reports->first()->uuid}",
                now()->addSeconds(20),
                fn (): ?Report => $access->visibleQuery($actor)
                    ->with([
                        'reporter' => fn (MorphTo $relation) => $relation->morphWith([
                            User::class => ['departmentRecord'],
                        ]),
                        'media',
                        'cases',
                        'people',
                    ])
                    ->withCount('comments')
                    ->whereKey($reports->first()->id)
                    ->first(),
            );
            $this->selectedReport = $selected?->uuid;
            $this->activeSection = 'report';
        }

        $mainComments = collect();
        $totalMainComments = 0;
        if ($selected !== null) {
            $expandedReplyIds = array_values(array_unique(array_map('intval', $this->expandedReplyIds)));
            $commentCacheKey = "workspace:{$actorCacheKey}:comments:{$selected->id}:{$this->commentLimit}:".hash(
                'sha256',
                implode(',', $expandedReplyIds),
            );
            $comments = $cache->remember(
                'reports',
                $commentCacheKey,
                now()->addSeconds(20),
                function () use ($selected, $expandedReplyIds): array {
                    $commentQuery = $selected->comments()->whereNull('parent_id');
                    $total = (clone $commentQuery)->count();
                    $comments = $commentQuery
                        ->with([
                            'author' => fn (MorphTo $relation) => $relation->morphWith([
                                User::class => ['departmentRecord'],
                            ]),
                        ])
                        ->withCount('replies')
                        ->orderByRaw('CASE WHEN author_type = ? THEN 0 ELSE 1 END', [Admin::class])
                        ->latest('updated_at')
                        ->limit($this->commentLimit)
                        ->get();

                    $expanded = $comments->whereIn('id', $expandedReplyIds);
                    if ($expanded->isNotEmpty()) {
                        $expanded->load([
                            'replies.author' => fn (MorphTo $relation) => $relation->morphWith([
                                User::class => ['departmentRecord'],
                            ]),
                        ]);
                    }

                    return [$comments, $total];
                },
            );
            [$mainComments, $totalMainComments] = $comments;
        }

        $isAdmin = $access->isAdministrator($actor);
        $currentDepartment = $actor instanceof User ? $actor->department : null;
        $layout = $isAdmin ? 'layouts.app' : 'layouts.department';
        $this->markSelectedReportSeen($selected);

        return view('livewire.report-workspace', [
            'reports' => $reports,
            'totalReportCount' => $totalReportCount,
            'hasMoreReports' => $totalReportCount > $this->reportLimit,
            'report' => $selected,
            'mainComments' => $mainComments,
            'totalMainComments' => $totalMainComments,
            'hasMoreComments' => $totalMainComments > $this->commentLimit,
            'isAdmin' => $isAdmin,
            'canLinkCases' => $access->canLinkCases($actor),
            'canLinkPeople' => $access->canLinkPeople($actor),
            'canEditReport' => $selected !== null && $access->canManage($selected, $actor),
            'canReply' => $selected !== null && $this->canReply($selected, $access, $actor),
            'caseOptions' => $this->composerOpen ? $this->caseOptions($access, $actor) : collect(),
            'personOptions' => $this->composerOpen ? $this->personOptions($access, $actor) : collect(),
            'department' => $currentDepartment,
        ])->extends($layout, [
            'page_title' => 'Report System — '.config('app.name'),
            'page_label' => 'REPORT SYSTEM',
        ])->section($isAdmin ? 'content' : 'department_content');
    }

    private function authorizeWorkspace(ReportAccess $access): Authenticatable
    {
        return $access->actor();
    }

    private function selectedVisibleReport(ReportAccess $access, Authenticatable $actor): Report
    {
        abort_unless($this->selectedReport !== null, 404);

        return $access->visibleQuery($actor)->where('uuid', $this->selectedReport)->with('media')->firstOrFail();
    }

    private function canReply(Report $report, ReportAccess $access, Authenticatable $actor): bool
    {
        return $access->isAdministrator($actor)
            || ($report->reporter_type === $actor::class && (int) $report->reporter_id === (int) $actor->getAuthIdentifier());
    }

    private function caseOptions(ReportAccess $access, Authenticatable $actor): Collection
    {
        if (! $access->canLinkCases($actor)) {
            return collect();
        }

        $search = trim($this->caseSearch);
        $selectedIds = array_values(array_unique(array_map('intval', $this->caseIds)));
        sort($selectedIds);
        $actorKey = hash('sha256', $actor::class.':'.$actor->getAuthIdentifier());

        return app(VersionedCache::class)->remember(
            'cases',
            'report-link:'.$actorKey.':'.hash('sha256', $search.':'.implode(',', $selectedIds)),
            now()->addSeconds(30),
            function () use ($search, $selectedIds): Collection {
                $matches = InvestigationCase::query()
                    ->when($search !== '', function ($query) use ($search): void {
                        $term = '%'.$search.'%';
                        $query->where(fn ($query) => $query->where('public_id', 'like', $term)->orWhere('title', 'like', $term));
                    })
                    ->latest('updated_at')
                    ->limit(20)
                    ->get(['id', 'public_id', 'title']);
                $selected = $selectedIds === []
                    ? collect()
                    : InvestigationCase::query()->whereIn('id', $selectedIds)->get(['id', 'public_id', 'title']);

                return $selected->merge($matches)->unique('id')->values();
            },
        );
    }

    private function personOptions(ReportAccess $access, Authenticatable $actor): Collection
    {
        if (! $access->canLinkPeople($actor)) {
            return collect();
        }

        $search = trim($this->personSearch);
        $selectedIds = array_values(array_unique(array_map('intval', $this->personIds)));
        sort($selectedIds);
        $actorKey = hash('sha256', $actor::class.':'.$actor->getAuthIdentifier());

        return app(VersionedCache::class)->remember(
            'people',
            'report-link:'.$actorKey.':'.hash('sha256', $search.':'.implode(',', $selectedIds)),
            now()->addSeconds(30),
            function () use ($search, $selectedIds): Collection {
                $matches = Person::query()
                    ->when($search !== '', function ($query) use ($search): void {
                        $term = '%'.$search.'%';
                        $query->where(fn ($query) => $query->where('public_id', 'like', $term)->orWhere('name', 'like', $term));
                    })
                    ->latest('updated_at')
                    ->limit(20)
                    ->get(['id', 'public_id', 'name']);
                $selected = $selectedIds === []
                    ? collect()
                    : Person::query()->whereIn('id', $selectedIds)->get(['id', 'public_id', 'name']);

                return $selected->merge($matches)->unique('id')->values();
            },
        );
    }

    private function attachCommenterPreviews(Collection $reports): void
    {
        if ($reports->isEmpty()) {
            return;
        }

        $rows = ReportComment::query()
            ->select(['report_id', 'author_type', 'author_id'])
            ->selectRaw('MAX(updated_at) as last_updated')
            ->whereIn('report_id', $reports->modelKeys())
            ->groupBy('report_id', 'author_type', 'author_id')
            ->orderByDesc('last_updated')
            ->get();
        $adminCommentReportIds = ReportComment::query()
            ->whereIn('report_id', $reports->modelKeys())
            ->where('author_type', Admin::class)
            ->distinct()
            ->pluck('report_id');

        $userIds = $rows->where('author_type', User::class)->pluck('author_id')->unique();
        $adminIds = $rows->where('author_type', Admin::class)->pluck('author_id')->unique();
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'name', 'profile_photo_path'])->keyBy('id');
        $admins = Admin::query()->whereIn('id', $adminIds)->get(['id', 'name'])->keyBy('id');
        $rowsByReport = $rows->groupBy('report_id');

        foreach ($reports as $report) {
            $commenters = $rowsByReport->get($report->id, collect())->map(function ($row) use ($users, $admins): ?array {
                $author = $row->author_type === User::class
                    ? $users->get($row->author_id)
                    : ($row->author_type === Admin::class ? $admins->get($row->author_id) : null);

                return $author === null ? null : [
                    'author' => $author,
                    'type' => $row->author_type,
                ];
            })->filter()->values();
            $report->setAttribute('commenter_count', $commenters->count());
            $report->setAttribute('recent_commenters', $commenters->take(5));
            $report->setAttribute('admin_commented', $adminCommentReportIds->contains($report->id));
        }
    }

    private function markSelectedReportSeen(?Report $report): void
    {
        if ($report !== null && $this->activeSection === 'comments') {
            session(['report_system_last_seen_id' => (int) Report::query()->max('id')]);
        }
    }

    private function resetComposer(): void
    {
        $this->reset([
            'editingUuid',
            'body',
            'uploads',
            'caseIds',
            'personIds',
            'removeMediaIds',
            'caseSearch',
            'personSearch',
        ]);
        $this->resetValidation();
    }
}
