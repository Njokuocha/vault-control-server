<div class="content-inner report-system-page">
    <header class="report-system-heading">
        <div>
            <span class="section-kicker">AGENCY-WIDE INTELLIGENCE · INTERNAL REPORTING</span>
            <h1>Report System</h1>
            <p>Field observations, incident updates, and verified agency reporting.</p>
        </div>
        <div class="report-system-heading__actions">
            @if ($isAdmin)
                <a class="button-secondary" href="{{ route('admin.reports.manage') }}" wire:navigate>REPORT CONTROL</a>
            @endif
            <button class="button-primary" type="button" wire:click="openComposer">＋ NEW REPORT</button>
        </div>
    </header>

    <div class="report-system-layout">
        <aside class="glass-panel report-feed-panel" aria-label="Agency reports">
            <div class="report-panel-heading">
                <div><span class="section-kicker">SITUATION FEED</span><h2>{{ $totalReportCount }} Reports</h2></div>
                <span class="report-live-mark"><i></i> LIVE</span>
            </div>
            <div class="report-feed-list">
                @forelse ($reports as $feedReport)
                    @php($reporter = $feedReport->reporter)
                    <button class="report-feed-item {{ $report?->id === $feedReport->id ? 'is-selected' : '' }}" type="button" wire:click="selectReport('{{ $feedReport->uuid }}')">
                        <span class="report-feed-item__node" aria-hidden="true"></span>
                        <span class="report-feed-item__meta">
                            <span>{{ $feedReport->created_at?->format('M d, Y · H:i') }} UTC</span>
                            @if ($feedReport->admin_commented)<b>ADMIN COMMENTED</b>@endif
                            @if ($feedReport->is_hidden)<em>RESTRICTED</em>@endif
                        </span>
                        <strong>{{ \Illuminate\Support\Str::limit($feedReport->body, 115) }}</strong>
                        <span class="report-feed-item__byline">{{ $reporter?->name ?? 'Former account' }} · {{ $feedReport->comments_count }} comments · {{ $feedReport->media->count() }} media</span>
                        @if ($feedReport->latestComment)
                            <span class="report-feed-item__latest"><i aria-hidden="true"></i><span><b>{{ $feedReport->latestComment->author?->name ?? 'Former account' }}:</b> {{ \Illuminate\Support\Str::limit($feedReport->latestComment->body, 78) }}</span></span>
                        @endif
                        <span class="report-feed-item__footer">
                            <span class="report-commenter-stack" aria-hidden="true">
                                @foreach ($feedReport->recent_commenters as $commenter)
                                    @php($commenterAuthor = $commenter['author'])
                                    @if ($commenterAuthor instanceof \App\Models\User && $commenterAuthor->profile_photo_path)
                                        <img src="{{ route('reports.commenter-photo', $commenterAuthor) }}" alt="" loading="lazy">
                                    @else
                                        <i>{{ strtoupper(substr($commenterAuthor->name ?? 'A', 0, 1)) }}</i>
                                    @endif
                                @endforeach
                            </span>
                            <span>{{ $feedReport->commenter_count }} {{ $feedReport->commenter_count === 1 ? 'staff' : 'staffs' }} commented</span>
                            <span class="report-feed-item__arrow" aria-hidden="true">↗</span>
                        </span>
                    </button>
                @empty
                    <div class="report-empty-state"><span class="ri ri-radar-line" aria-hidden="true"></span><strong>NO REPORTS ON FILE</strong><p>New agency reports will appear in this connected feed.</p></div>
                @endforelse
            </div>
            @if ($hasMoreReports)
                <button class="report-load-older" type="button" wire:click="loadMoreReports" wire:loading.attr="disabled" wire:target="loadMoreReports"><span wire:loading.remove wire:target="loadMoreReports">VIEW OLDER REPORTS</span><span wire:loading wire:target="loadMoreReports">LOADING…</span></button>
            @endif
            <div class="report-feed-footnote"><span></span> CONNECTED AGENCY FEED · {{ $reports->count() }} / {{ $totalReportCount }}</div>
        </aside>

        <main class="glass-panel report-detail-panel" aria-live="polite">
            @if ($report)
                <header class="report-detail-heading">
                    <div>
                        <span class="section-kicker">FIELD REPORT · {{ strtoupper(substr(str_replace('-', '', $report->uuid), 0, 12)) }}</span>
                        <h2>Situation record</h2>
                    </div>
                    <div class="report-detail-heading__actions">
                        @if ($canEditReport)
                            <button class="button-secondary" type="button" wire:click="editReport('{{ $report->uuid }}')">EDIT REPORT</button>
                        @endif
                        <span class="report-detail-status {{ $report->is_hidden ? 'is-restricted' : '' }}">{{ $report->is_hidden ? 'RESTRICTED' : 'INTERNAL' }}</span>
                    </div>
                </header>

                <nav class="report-detail-tabs" role="tablist" aria-label="Report sections">
                    <button id="report-tab" role="tab" aria-controls="report-tab-panel" aria-selected="{{ $activeSection === 'report' ? 'true' : 'false' }}" tabindex="{{ $activeSection === 'report' ? '0' : '-1' }}" type="button" class="{{ $activeSection === 'report' ? 'is-active' : '' }}" wire:click="showSection('report')"><span class="ri ri-file-list-3-line" aria-hidden="true"></span> REPORT</button>
                    <button id="comments-tab" role="tab" aria-controls="comments-tab-panel" aria-selected="{{ $activeSection === 'comments' ? 'true' : 'false' }}" tabindex="{{ $activeSection === 'comments' ? '0' : '-1' }}" type="button" class="{{ $activeSection === 'comments' ? 'is-active' : '' }}" wire:click="showSection('comments')"><span class="ri ri-chat-3-line" aria-hidden="true"></span> COMMENTS <b>{{ $report->comments_count }}</b></button>
                </nav>

                @if ($activeSection === 'report')
                    <article id="report-tab-panel" class="report-content-card" role="tabpanel" aria-labelledby="report-tab" tabindex="0">
                        @if ($report->admin_commented)
                            <div class="report-admin-commented"><span class="ri ri-shield-check-line" aria-hidden="true"></span> ADMIN COMMENTED</div>
                        @endif
                        <div class="report-author-line">
                            <span class="report-author-seal">{{ strtoupper(substr($report->reporter?->name ?? 'A', 0, 1)) }}</span>
                            <span><strong>{{ $report->reporter?->name ?? 'Former account' }}</strong><small>{{ $report->reporter instanceof \App\Models\User ? ($report->reporter->departmentRecord?->rank ?? 'AGENCY STAFF') : 'ADMINISTRATOR' }}</small></span>
                            <time datetime="{{ $report->created_at?->toIso8601String() }}">{{ $report->created_at?->format('M d, Y · H:i:s') }} UTC</time>
                        </div>
                        <div class="report-body">{{ $report->body }}</div>

                        @if ($report->media->isNotEmpty())
                            <div class="report-media-grid report-media-grid--{{ min(4, $report->media->count()) }}">
                                @foreach ($report->media->take(4) as $media)
                                    <button class="report-media-tile" type="button" wire:click="{{ $report->media->count() > 4 && $loop->last ? 'openGallery' : 'openMedia('.$media->id.')' }}" aria-label="{{ $report->media->count() > 4 && $loop->last ? 'Open all report attachments' : 'Preview '.$media->original_name }}">
                                        @if ($media->media_type === 'image')
                                            <img src="{{ route('reports.media', [$report, $media]) }}" alt="{{ $media->original_name }}" loading="lazy">
                                        @else
                                            <video src="{{ route('reports.media', [$report, $media]) }}" preload="metadata" muted playsinline aria-label="{{ $media->original_name }}"></video>
                                            <span class="report-video-mark"><i class="ri ri-play-fill" aria-hidden="true"></i> VIDEO</span>
                                        @endif
                                        @if ($report->media->count() > 4 && $loop->last)
                                            <span class="report-media-more"><i class="ri ri-gallery-line" aria-hidden="true"></i> +{{ $report->media->count() - 3 }} FILES</span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        @endif

                        @if ($report->cases->isNotEmpty() || $report->people->isNotEmpty())
                            <div class="report-linked-records">
                                <span class="section-kicker">CONNECTED RECORDS</span>
                                <div>
                                    @if ($report->cases->isNotEmpty())
                                        @if ($isAdmin || $department === 'cfm')
                                            @foreach ($report->cases as $caseFile)
                                                <a href="{{ route($isAdmin ? 'cases.show' : 'cfm.cases.show', $caseFile) }}" wire:navigate><i class="ri ri-folder-shield-2-line" aria-hidden="true"></i> CASE · {{ $caseFile->public_id }}</a>
                                            @endforeach
                                        @else
                                            <span class="is-restricted"><i class="ri ri-lock-line" aria-hidden="true"></i> CASE LINK · ACCESS RESTRICTED</span>
                                        @endif
                                    @endif
                                    @if ($report->people->isNotEmpty())
                                        @if ($isAdmin || $department === 'pm')
                                            @foreach ($report->people as $person)
                                                <a href="{{ route($isAdmin ? 'people.show' : 'pm.people.show', $person) }}" wire:navigate><i class="ri ri-user-search-line" aria-hidden="true"></i> PERSON · {{ $person->public_id }}</a>
                                            @endforeach
                                        @else
                                            <span class="is-restricted"><i class="ri ri-lock-line" aria-hidden="true"></i> PERSON LINK · ACCESS RESTRICTED</span>
                                        @endif
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if ($report->latestComment)
                            <button class="report-latest-comment" type="button" wire:click="showSection('comments')">
                                <span class="report-comment-node" aria-hidden="true"></span>
                                <span><small>MOST RECENT COMMENT · {{ $report->latestComment->updated_at?->diffForHumans() }}</small><strong>{{ $report->latestComment->author?->name ?? 'Former account' }}</strong><p>{{ \Illuminate\Support\Str::limit($report->latestComment->body, 240) }}</p></span>
                                <i class="ri ri-arrow-right-line" aria-hidden="true"></i>
                            </button>
                        @else
                            <div class="report-first-comment-note"><span class="ri ri-chat-new-line" aria-hidden="true"></span> No comments yet. Add the first field note.</div>
                        @endif
                    </article>
                @else
                    <section id="comments-tab-panel" class="report-comments-panel" role="tabpanel" aria-labelledby="comments-tab" tabindex="0">
                        <div class="report-comments-heading"><div><span class="section-kicker">AGENCY DISCUSSION</span><h3>{{ $totalMainComments }} Comments</h3></div><span>ADMIN PRIORITY · NEWEST FIRST</span></div>
                        <div class="report-comment-thread">
                            @forelse ($mainComments as $comment)
                                <article class="report-comment {{ $comment->author_type === \App\Models\Admin::class ? 'is-admin' : '' }}">
                                    <span class="report-comment__node" aria-hidden="true"></span>
                                    <div class="report-comment__body">
                                        <header>
                                            <span class="report-author-seal">{{ strtoupper(substr($comment->author?->name ?? 'A', 0, 1)) }}</span>
                                            <span><strong>{{ $comment->author?->name ?? 'Former account' }}</strong><small>{{ $comment->author_type === \App\Models\Admin::class ? 'ADMINISTRATOR · PRIORITY' : ($comment->author instanceof \App\Models\User ? strtoupper($comment->author->departmentRecord?->rank ?? 'AGENCY STAFF') : 'AGENCY STAFF') }}</small></span>
                                            <time>{{ $comment->updated_at?->diffForHumans() }}</time>
                                        </header>
                                        <p>{{ $comment->body }}</p>
                                        <footer>
                                            @if ($comment->replies->isNotEmpty())
                                                <button type="button" wire:click="toggleReplies({{ $comment->id }})">{{ in_array($comment->id, array_map('intval', $expandedReplyIds), true) ? 'HIDE' : 'VIEW' }} REPLIES · {{ $comment->replies->count() }}</button>
                                            @endif
                                            @if ($canReply)
                                                <button type="button" wire:click="beginReply({{ $comment->id }})">REPLY</button>
                                            @endif
                                        </footer>
                                        @if (in_array($comment->id, array_map('intval', $expandedReplyIds), true))
                                            <div class="report-replies">
                                                @foreach ($comment->replies as $reply)
                                                    <article class="report-reply">
                                                        <span class="report-reply__node" aria-hidden="true"></span>
                                                        <header><span class="report-author-seal">{{ strtoupper(substr($reply->author?->name ?? 'A', 0, 1)) }}</span><span><strong>{{ $reply->author?->name ?? 'Former account' }}</strong><small>{{ $reply->author_type === \App\Models\Admin::class ? 'ADMINISTRATOR' : ($reply->author instanceof \App\Models\User ? strtoupper($reply->author->departmentRecord?->rank ?? 'AGENCY STAFF') : 'AGENCY STAFF') }}</small></span><time>{{ $reply->updated_at?->diffForHumans() }}</time></header>
                                                        <p>{{ $reply->body }}</p>
                                                    </article>
                                                @endforeach
                                            </div>
                                        @endif
                                        @if ($replyingToId === $comment->id)
                                            <form class="report-reply-form" wire:submit="addReply">
                                                <label class="sr-only" for="report-reply-{{ $comment->id }}">Reply to comment</label>
                                                <textarea id="report-reply-{{ $comment->id }}" wire:model="replyBody" rows="2" maxlength="5000" placeholder="Write an official reply…"></textarea>
                                                @error('replyBody')<small>{{ $message }}</small>@enderror
                                                <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="addReply"><span wire:loading.remove wire:target="addReply">POST REPLY</span><span wire:loading wire:target="addReply">POSTING…</span></button>
                                                <button class="button-secondary" type="button" wire:click="cancelReply">CANCEL</button>
                                            </form>
                                        @endif
                                    </div>
                                </article>
                            @empty
                                <div class="report-empty-state"><span class="ri ri-chat-off-line" aria-hidden="true"></span><strong>NO COMMENTS YET</strong><p>Use the field note panel below to add context.</p></div>
                            @endforelse
                        </div>
                        @if ($hasMoreComments)
                            <button class="report-load-comments" type="button" wire:click="loadMoreComments" wire:loading.attr="disabled" wire:target="loadMoreComments"><span wire:loading.remove wire:target="loadMoreComments">VIEW MORE COMMENTS · 15</span><span wire:loading wire:target="loadMoreComments">LOADING…</span></button>
                        @endif
                        <form class="report-comment-composer" wire:submit="addComment">
                            <span class="section-kicker">ADD FIELD NOTE</span>
                            <label class="sr-only" for="report-comment-body">Comment on report</label>
                            <textarea id="report-comment-body" wire:model="commentBody" rows="3" maxlength="5000" placeholder="Add a relevant observation or operational update…"></textarea>
                            @error('commentBody')<small>{{ $message }}</small>@enderror
                            <div><small>Comments are visible to authorized agency personnel. Replies are reserved for the reporter and administrators.</small><button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="addComment"><span wire:loading.remove wire:target="addComment">POST COMMENT</span><span wire:loading wire:target="addComment">POSTING…</span></button></div>
                        </form>
                    </section>
                @endif
            @else
                <div class="report-empty-state report-empty-state--detail"><span class="ri ri-radar-line" aria-hidden="true"></span><strong>AWAITING FIRST REPORT</strong><p>Publish a field report to begin the agency situation record.</p><button class="button-primary" type="button" wire:click="openComposer">＋ CREATE REPORT</button></div>
            @endif
        </main>
    </div>

    @if ($composerOpen)
        <div class="report-modal-backdrop" role="presentation" wire:click.self="$set('composerOpen', false)">
            <section class="report-composer-modal" role="dialog" aria-modal="true" aria-labelledby="report-composer-title">
                <header><div><span class="section-kicker">{{ $editingUuid ? 'UPDATE RECORD' : 'NEW SITUATION REPORT' }}</span><h2 id="report-composer-title">{{ $editingUuid ? 'Edit report' : 'Publish a report' }}</h2></div><button type="button" aria-label="Close report form" wire:click="$set('composerOpen', false)">×</button></header>
                <form wire:submit="saveReport">
                    <label class="report-form-label" for="report-body">REPORT NARRATIVE <span>NO TITLE REQUIRED</span></label>
                    <textarea id="report-body" wire:model="body" rows="7" maxlength="20000" placeholder="Record the event, timing, location, source, and operational context…"></textarea>
                    @error('body')<small class="report-form-error">{{ $message }}</small>@enderror

                    @if ($report && $report->uuid === $editingUuid && $report->media->isNotEmpty())
                        <div class="report-existing-media"><span class="report-form-label">CURRENT MEDIA · SELECT TO REMOVE</span><div>
                            @foreach ($report->media as $media)
                                <button type="button" class="{{ in_array($media->id, array_map('intval', $removeMediaIds), true) ? 'is-marked-remove' : '' }}" wire:click="toggleMediaRemoval({{ $media->id }})" aria-label="{{ in_array($media->id, array_map('intval', $removeMediaIds), true) ? 'Keep' : 'Remove' }} {{ $media->original_name }}">
                                    @if ($media->media_type === 'image')<img src="{{ route('reports.media', [$report, $media]) }}" alt="">
                                    @else <span class="ri ri-video-line" aria-hidden="true"></span>@endif
                                    <small>{{ $media->original_name }}</small>
                                </button>
                            @endforeach
                        </div></div>
                    @endif

                    <label class="report-form-label" for="report-uploads">IMAGE / VIDEO ATTACHMENTS <span>UP TO 12 · 50 MB EACH</span></label>
                    <input id="report-uploads" type="file" wire:model="uploads" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska" multiple data-report-files>
                    <div class="report-upload-previews" data-report-previews aria-live="polite"></div>
                    <div wire:loading wire:target="uploads" class="report-upload-progress">SECURELY PREPARING SELECTED MEDIA…</div>
                    @error('uploads')<small class="report-form-error">{{ $message }}</small>@enderror
                    @error('uploads.*')<small class="report-form-error">{{ $message }}</small>@enderror

                    @if ($canLinkCases)
                        <label class="report-form-label" for="report-case-search">LINK CASE FILES <span>AVAILABLE TO CFM / ADMIN</span></label>
                        <input id="report-case-search" type="search" wire:model.live.debounce.300ms="caseSearch" placeholder="Search case reference or title">
                        <select class="report-link-select" wire:model="caseIds" multiple size="4" aria-label="Select case files to link">
                            @foreach ($caseOptions as $caseOption)<option value="{{ $caseOption->id }}">{{ $caseOption->public_id }} · {{ $caseOption->title }}</option>@endforeach
                        </select>
                    @endif
                    @if ($canLinkPeople)
                        <label class="report-form-label" for="report-person-search">LINK PERSON RECORDS <span>AVAILABLE TO PM / ADMIN</span></label>
                        <input id="report-person-search" type="search" wire:model.live.debounce.300ms="personSearch" placeholder="Search person reference or name">
                        <select class="report-link-select" wire:model="personIds" multiple size="4" aria-label="Select person records to link">
                            @foreach ($personOptions as $personOption)<option value="{{ $personOption->id }}">{{ $personOption->public_id }} · {{ $personOption->name }}</option>@endforeach
                        </select>
                    @endif
                    @error('caseIds.*')<small class="report-form-error">{{ $message }}</small>@enderror
                    @error('personIds.*')<small class="report-form-error">{{ $message }}</small>@enderror

                    <footer><button class="button-secondary" type="button" wire:click="$set('composerOpen', false)">CANCEL</button><button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveReport,uploads"><span wire:loading.remove wire:target="saveReport"> {{ $editingUuid ? 'SAVE REPORT' : 'PUBLISH REPORT' }}</span><span wire:loading wire:target="saveReport">SUBMITTING…</span></button></footer>
                </form>
            </section>
        </div>
    @endif

    @if ($galleryOpen && $report)
        <div class="report-gallery-backdrop" role="presentation" wire:click.self="closeGallery">
            <section class="report-gallery-modal" role="dialog" aria-modal="true" aria-labelledby="report-gallery-title">
                <header><div><span class="section-kicker">PRIVATE REPORT MEDIA</span><h2 id="report-gallery-title">Attachment gallery <small>{{ $report->media->count() }} FILES</small></h2></div><button type="button" aria-label="Close gallery" wire:click="closeGallery">×</button></header>
                <div class="report-gallery-grid">
                    @foreach ($report->media as $media)
                        <button type="button" wire:click="openMedia({{ $media->id }})">
                            @if ($media->media_type === 'image')<img src="{{ route('reports.media', [$report, $media]) }}" alt="{{ $media->original_name }}" loading="lazy">
                            @else <video src="{{ route('reports.media', [$report, $media]) }}" preload="metadata" muted playsinline></video><span class="report-video-mark"><i class="ri ri-play-fill" aria-hidden="true"></i> VIDEO</span>@endif
                            <small>{{ $media->original_name }}</small>
                        </button>
                    @endforeach
                </div>
            </section>
        </div>
    @endif

    @if ($previewMedia && $report)
        <div class="report-file-backdrop" role="presentation" wire:click.self="closeMedia">
            <section class="report-file-modal" role="dialog" aria-modal="true" aria-label="Report attachment preview">
                <header><span>{{ $previewMedia->original_name }}</span><button type="button" aria-label="Close preview" wire:click="closeMedia">×</button></header>
                @if ($previewMedia->media_type === 'image')
                    <img src="{{ route('reports.media', [$report, $previewMedia]) }}" alt="{{ $previewMedia->original_name }}">
                @else
                    <video src="{{ route('reports.media', [$report, $previewMedia]) }}" controls autoplay playsinline></video>
                @endif
            </section>
        </div>
    @endif
</div>
