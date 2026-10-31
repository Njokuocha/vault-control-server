<?php

namespace App\Livewire;

use App\Events\AgencyReportUpdated;
use App\Jobs\DeleteImageThumbnailJob;
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

    public function selectReport(string $uuid, int $reportId, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $this->cachedReportDetail($access, $actor, $uuid, $reportId);
        abort_if($report === null, 404);
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

    public function closeComposer(ReportAccess $access): void
    {
        $this->authorizeWorkspace($access);
        $this->composerOpen = false;
        $this->resetComposer();
    }

    public function editReport(string $uuid, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        $report = $this->cachedReportDetail($access, $actor, $uuid);
        abort_if($report === null, 404);
        abort_unless($access->canManage($report, $actor), 403);

        $this->resetComposer();
        $this->editingUuid = $report->uuid;
        $this->body = $report->body;
        if ($access->canLinkCases($actor)) {
            $this->caseIds = $report->cases->pluck('id')->map(fn ($id): string => (string) $id)->all();
        }
        if ($access->canLinkPeople($actor)) {
            $this->personIds = $report->people->pluck('id')->map(fn ($id): string => (string) $id)->all();
        }
        $this->composerOpen = true;
    }

    public function toggleCaseLink(int $id, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        abort_unless($access->canLinkCases($actor), 403);
        InvestigationCase::query()->findOrFail($id);

        $selectedIds = array_values(array_unique(array_map('intval', $this->caseIds)));
        if (in_array($id, $selectedIds, true)) {
            $selectedIds = array_values(array_diff($selectedIds, [$id]));
        } elseif (count($selectedIds) >= 20) {
            $this->addError('caseIds', 'A report can link up to 20 case files.');

            return;
        } else {
            $selectedIds[] = $id;
        }

        $this->caseIds = array_map('strval', $selectedIds);
        $this->resetValidation('caseIds');
    }

    public function togglePersonLink(int $id, ReportAccess $access): void
    {
        $actor = $this->authorizeWorkspace($access);
        abort_unless($access->canLinkPeople($actor), 403);
        Person::query()->findOrFail($id);

        $selectedIds = array_values(array_unique(array_map('intval', $this->personIds)));
        if (in_array($id, $selectedIds, true)) {
            $selectedIds = array_values(array_diff($selectedIds, [$id]));
        } elseif (count($selectedIds) >= 20) {
            $this->addError('personIds', 'A report can link up to 20 person records.');

            return;
        } else {
            $selectedIds[] = $id;
        }

        $this->personIds = array_map('strval', $selectedIds);
        $this->resetValidation('personIds');
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
            $reportUuid = $existing?->uuid ?? (string) Str::uuid();
            $storedMedia = [];
            foreach ($validated['uploads'] as $upload) {
                $path = $upload->store('reports/'.$reportUuid, config('vault.upload_disk', 'vault'));
                if ($path === false) {
                    throw new \RuntimeException('A report attachment could not be stored on private storage.');
                }
                $storedPaths[] = $path;
                $storedMedia[] = [
                    'path' => $path,
                    'original_name' => Str::limit(basename($upload->getClientOriginalName()), 255, ''),
                    'mime_type' => (string) $upload->getMimeType(),
                    'media_type' => str_starts_with((string) $upload->getMimeType(), 'image/') ? 'image' : 'video',
                    'size' => $upload->getSize(),
                ];
            }

            $report = DB::transaction(function () use ($validated, $actor, $access, $existing, $reportUuid, $storedMedia, &$removedPaths): Report {
                $report = $existing ?? Report::query()->create([
                    'uuid' => $reportUuid,
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
                    $mediaToRemove->where('media_type', 'image')->each(
                        fn (ReportMedia $media) => DeleteImageThumbnailJob::dispatch($media->path)->afterCommit(),
                    );
                    $report->media()->whereIn('id', $mediaToRemove->modelKeys())->delete();
                }

                $sortOrder = (int) $report->media()->max('sort_order');
                foreach ($storedMedia as $media) {
                    $report->media()->create([
                        ...$media,
                        'sort_order' => ++$sortOrder,
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
        $this->dispatch('report-comment-submitted');
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

    #[On('agency-report-list-updated')]
    public function refreshReports(): void
    {
        $this->authorizeWorkspace(app(ReportAccess::class));
    }

    #[On('agency-report-comments-updated')]
    public function refreshReportComments(): void
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
            : $this->cachedReportDetail(
                $access,
                $actor,
                $this->selectedReport,
                $reports->firstWhere('uuid', $this->selectedReport)?->id,
            );
        if ($selected === null && $reports->isNotEmpty()) {
            $selected = $this->cachedReportDetail($access, $actor, $reports->first()->uuid, $reports->first()->id);
            $this->selectedReport = $selected?->uuid;
            $this->activeSection = 'report';
        }

        $mainComments = collect();
        $totalMainComments = 0;
        if ($selected !== null && $this->activeSection === 'comments') {
            $expandedReplyIds = array_values(array_unique(array_map('intval', $this->expandedReplyIds)));
            $commentCacheKey = "workspace:{$actorCacheKey}:comments:{$selected->id}:{$this->commentLimit}:".hash(
                'sha256',
                implode(',', $expandedReplyIds),
            );
            $comments = $cache->remember(
                'report-content-'.$selected->id,
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
            'selectedReportId' => $selected?->id,
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

    private function cachedReportDetail(
        ReportAccess $access,
        Authenticatable $actor,
        string $uuid,
        ?int $reportId = null,
    ): ?Report {
        $reportId ??= $access->visibleQuery($actor)->where('uuid', $uuid)->value('id');
        if ($reportId === null) {
            return null;
        }

        $actorCacheKey = hash('sha256', $actor::class.':'.$actor->getAuthIdentifier());

        return app(VersionedCache::class)->remember(
            'report-content-'.$reportId,
            "workspace:{$actorCacheKey}:detail:{$uuid}",
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
                ->whereKey($reportId)
                ->where('uuid', $uuid)
                ->first(),
            ['report-related'],
        );
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
                $matches = $search === '' ? collect() : InvestigationCase::query()
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
                $matches = $search === '' ? collect() : Person::query()
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

        $commenterRows = ReportComment::query()
            ->leftJoin('users as commenter_users', function ($join): void {
                $join->on('commenter_users.id', '=', 'report_comments.author_id')
                    ->where('report_comments.author_type', '=', User::class);
            })
            ->leftJoin('admins as commenter_admins', function ($join): void {
                $join->on('commenter_admins.id', '=', 'report_comments.author_id')
                    ->where('report_comments.author_type', '=', Admin::class);
            })
            ->select([
                'report_comments.report_id',
                'report_comments.author_type',
                'report_comments.author_id',
            ])
            ->selectRaw('MAX(report_comments.updated_at) as last_updated')
            ->whereIn('report_comments.report_id', $reports->modelKeys())
            ->where(function ($query): void {
                $query->whereNotNull('commenter_users.id')
                    ->orWhereNotNull('commenter_admins.id');
            })
            ->groupBy('report_comments.report_id', 'report_comments.author_type', 'report_comments.author_id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY report_comments.report_id ORDER BY MAX(report_comments.updated_at) DESC, report_comments.author_type, report_comments.author_id) as preview_rank')
            ->selectRaw('COUNT(*) OVER (PARTITION BY report_comments.report_id) as commenter_count')
            ->selectRaw(
                'MAX(CASE WHEN report_comments.author_type = ? THEN 1 ELSE 0 END) OVER (PARTITION BY report_comments.report_id) as admin_commented',
                [Admin::class],
            );
        $rows = DB::query()
            ->fromSub($commenterRows, 'report_commenters')
            ->where('preview_rank', '<=', 5)
            ->orderByDesc('last_updated')
            ->get();

        $userIds = $rows->where('author_type', User::class)->pluck('author_id')->unique();
        $adminIds = $rows->where('author_type', Admin::class)->pluck('author_id')->unique();
        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get(['id', 'name', 'profile_photo_path'])->keyBy('id');
        $admins = $adminIds->isEmpty()
            ? collect()
            : Admin::query()->whereIn('id', $adminIds)->get(['id', 'name'])->keyBy('id');
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
            $report->setAttribute('commenter_count', (int) $rowsByReport->get($report->id, collect())->first()?->commenter_count);
            $report->setAttribute('recent_commenters', $commenters->take(5));
            $report->setAttribute('admin_commented', (bool) $rowsByReport->get($report->id, collect())->first()?->admin_commented);
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
