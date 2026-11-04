<section class="content-inner report-system-page report-editor-page" data-report-draft-key="{{ $draftKey }}">
    <header class="report-editor-heading">
        <div>
            <span class="section-kicker">{{ $report ? 'UPDATE RECORD' : 'NEW SITUATION REPORT' }}</span>
            <h1>{{ $report ? 'Edit report' : 'Publish a report' }}</h1>
            <p>Prepare the narrative, attach evidence, and connect authorized case or person records.</p>
        </div>
        <a class="button-secondary" href="{{ route($isAdmin ? 'admin.reports.index' : auth()->user()->department.'.reports.index') }}" wire:navigate>← REPORT SYSTEM</a>
    </header>

    <form class="report-editor-form" data-report-editor-form wire:submit="saveReport">
        <div class="report-editor-layout">
            <main class="report-editor-main">
                <section class="report-editor-section">
                    <label class="report-form-label" for="report-body">REPORT NARRATIVE <span>NO TITLE REQUIRED</span></label>
                    <div class="report-editor-tools" role="toolbar" aria-label="Report text formatting">
                        <button type="button" data-report-format-command="bold" aria-label="Bold" title="Bold"><strong>B</strong></button>
                        <button type="button" data-report-format-command="italic" aria-label="Italic" title="Italic"><em>I</em></button>
                        <button type="button" data-report-format-command="underline" aria-label="Underline" title="Underline"><u>U</u></button>
                        <button type="button" data-report-format-command="strikeThrough" aria-label="Strikethrough" title="Strikethrough"><s>S</s></button>
                        <span aria-hidden="true"></span>
                        <button type="button" data-report-format-command="insertUnorderedList" aria-label="Bulleted list" title="Bulleted list"><i class="ri ri-list-unordered"></i></button>
                        <button type="button" data-report-format-command="insertOrderedList" aria-label="Numbered list" title="Numbered list"><i class="ri ri-list-ordered"></i></button>
                        <button type="button" data-report-format-command="formatBlock" data-report-format-value="blockquote" aria-label="Quote" title="Quote"><i class="ri ri-double-quotes-l"></i></button>
                        <label class="report-editor-size-control">
                            <span class="sr-only">Limited text size</span>
                            <select data-report-font-size aria-label="Report text size">
                                <option value="p">Body</option>
                                <option value="h3">Caption</option>
                                <option value="h2">Section title</option>
                            </select>
                        </label>
                        <button type="button" data-report-format-command="removeFormat" aria-label="Clear formatting" title="Clear formatting"><i class="ri ri-format-clear"></i></button>
                        <button class="report-editor-preview-trigger" type="button" data-report-preview-open aria-label="Preview report"><i class="ri ri-eye-line" aria-hidden="true"></i> PREVIEW</button>
                    </div>
                    <div id="report-body" class="report-rich-editor" data-report-editor contenteditable="true" role="textbox" aria-multiline="true" aria-label="Report narrative" data-placeholder="Record the event, timing, location, source, and operational context…">{!! app(\App\Support\ReportBodyFormatter::class)->format($body) !!}</div>
                    @error('body')<small class="report-form-error">{{ $message }}</small>@enderror
                </section>

                @if ($report && $report->media->isNotEmpty())
                    <section class="report-editor-section report-editor-attachments">
                        <span class="report-form-label">CURRENT MEDIA · SELECT TO REMOVE</span>
                        <div class="report-existing-media">
                            <div>
                                @foreach ($report->media as $media)
                                    <button type="button" data-report-media-toggle class="{{ in_array($media->id, array_map('intval', $removeMediaIds), true) ? 'is-marked-remove' : '' }}" wire:click="toggleMediaRemoval({{ $media->id }})" aria-pressed="{{ in_array($media->id, array_map('intval', $removeMediaIds), true) ? 'true' : 'false' }}" aria-label="{{ in_array($media->id, array_map('intval', $removeMediaIds), true) ? 'Keep' : 'Remove' }} attachment {{ $loop->iteration }}">
                                        @if ($media->media_type === 'image')
                                            <img src="{{ route('reports.media.thumbnail', [$report, $media]) }}" alt="Report attachment {{ $loop->iteration }}">
                                        @else
                                            <video wire:key="report-edit-video-{{ $report->id }}-{{ $media->id }}" class="report-video-thumbnail" data-report-video-thumbnail data-thumbnail-url="{{ route('reports.media', [$report, $media]) }}" preload="none" muted playsinline hidden aria-hidden="true"></video>
                                            <span class="report-video-mark" aria-hidden="true"><i class="ri ri-play-fill"></i></span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

                <section class="report-editor-section">
                    <label class="report-form-label" for="report-uploads">IMAGE / VIDEO ATTACHMENTS <span>UP TO 12 · 50 MB EACH</span></label>
                    <label class="report-file-picker" for="report-uploads" data-report-dropzone>
                        <i class="ri ri-upload-cloud-2-line" aria-hidden="true"></i>
                        <span><strong>Choose or drop images and videos</strong><small>Adding files keeps the current selection · Up to 12 files, 50 MB each</small></span>
                        <b>SELECT FILES</b>
                        <input id="report-uploads" type="file" wire:model="uploads" accept="image/jpeg,image/png,image/gif,image/webp,image/avif,video/mp4,video/webm,video/quicktime,video/x-msvideo,video/x-matroska" multiple data-report-files>
                    </label>
                    <div class="report-upload-previews" data-report-previews wire:ignore aria-live="polite"></div>
                    <small class="report-draft-status" data-report-draft-status role="status" aria-live="polite"></small>
                    <div wire:loading wire:target="uploads" class="report-upload-progress">SECURELY PREPARING SELECTED MEDIA…</div>
                    @error('uploads')<small class="report-form-error">{{ $message }}</small>@enderror
                    @error('uploads.*')<small class="report-form-error">{{ $message }}</small>@enderror
                </section>
            </main>

            <aside class="report-editor-links" aria-label="Linked records">
                <header><span class="section-kicker">CONNECTED RECORDS</span><h2>Link context</h2><p>Search results update as you type. Selected records remain attached while you write.</p></header>
                @if ($canLinkCases)
                    <section class="report-editor-link-group">
                        <label class="report-form-label" for="report-case-search">CASE FILES <span>CFM / ADMIN</span></label>
                        <input id="report-case-search" type="search" wire:model.live.debounce.180ms="caseSearch" placeholder="Search reference or title">
                        @php($selectedCaseIds = array_map('intval', $caseIds))
                        <div class="report-link-selected" aria-label="Selected case files">
                            @forelse ($caseOptions->whereIn('id', $selectedCaseIds) as $caseOption)
                                <article class="report-link-card">
                                    <span class="report-link-card__icon ri ri-folder-shield-2-line" aria-hidden="true"></span>
                                    <span class="report-link-card__copy"><strong>{{ $caseOption->public_id }}</strong><small>{{ $caseOption->title }}</small></span>
                                    <button type="button" wire:click="toggleCaseLink({{ $caseOption->id }})" wire:loading.attr="disabled" wire:target="toggleCaseLink" aria-label="Remove {{ $caseOption->public_id }} from this report"><i class="ri ri-close-line" aria-hidden="true"></i></button>
                                </article>
                            @empty
                                <p class="report-link-empty">No case files selected yet.</p>
                            @endforelse
                        </div>
                        <div class="report-link-results" aria-label="Matching case files">
                            @forelse ($caseOptions->whereNotIn('id', $selectedCaseIds) as $caseOption)
                                <button class="report-link-result" type="button" wire:click="toggleCaseLink({{ $caseOption->id }})" wire:loading.attr="disabled" wire:target="toggleCaseLink" aria-label="Link {{ $caseOption->public_id }}">
                                    <i class="ri ri-add-line" aria-hidden="true"></i><span><strong>{{ $caseOption->public_id }}</strong><small>{{ $caseOption->title }}</small></span>
                                </button>
                            @empty
                                <p class="report-link-empty">{{ mb_strlen(trim($caseSearch)) < 2 ? 'Type at least 2 characters to search case files.' : 'No matching case files.' }}</p>
                            @endforelse
                        </div>
                        @error('caseIds')<small class="report-form-error">{{ $message }}</small>@enderror
                        @error('caseIds.*')<small class="report-form-error">{{ $message }}</small>@enderror
                    </section>
                @endif
                @if ($canLinkPeople)
                    <section class="report-editor-link-group">
                        <label class="report-form-label" for="report-person-search">PERSON RECORDS <span>PM / ADMIN</span></label>
                        <input id="report-person-search" type="search" wire:model.live.debounce.180ms="personSearch" placeholder="Search reference or name">
                        @php($selectedPersonIds = array_map('intval', $personIds))
                        <div class="report-link-selected" aria-label="Selected person records">
                            @forelse ($personOptions->whereIn('id', $selectedPersonIds) as $personOption)
                                <article class="report-link-card">
                                    <span class="report-link-card__icon ri ri-user-search-line" aria-hidden="true"></span>
                                    <span class="report-link-card__copy"><strong>{{ $personOption->public_id }}</strong><small>{{ $personOption->name }}</small></span>
                                    <button type="button" wire:click="togglePersonLink({{ $personOption->id }})" wire:loading.attr="disabled" wire:target="togglePersonLink" aria-label="Remove {{ $personOption->public_id }} from this report"><i class="ri ri-close-line" aria-hidden="true"></i></button>
                                </article>
                            @empty
                                <p class="report-link-empty">No person records selected yet.</p>
                            @endforelse
                        </div>
                        <div class="report-link-results" aria-label="Matching person records">
                            @forelse ($personOptions->whereNotIn('id', $selectedPersonIds) as $personOption)
                                <button class="report-link-result" type="button" wire:click="togglePersonLink({{ $personOption->id }})" wire:loading.attr="disabled" wire:target="togglePersonLink" aria-label="Link {{ $personOption->public_id }}">
                                    <i class="ri ri-add-line" aria-hidden="true"></i><span><strong>{{ $personOption->public_id }}</strong><small>{{ $personOption->name }}</small></span>
                                </button>
                            @empty
                                <p class="report-link-empty">{{ mb_strlen(trim($personSearch)) < 2 ? 'Type at least 2 characters to search people.' : 'No matching person records.' }}</p>
                            @endforelse
                        </div>
                        @error('personIds')<small class="report-form-error">{{ $message }}</small>@enderror
                        @error('personIds.*')<small class="report-form-error">{{ $message }}</small>@enderror
                    </section>
                @endif
                @if (! $canLinkCases && ! $canLinkPeople)
                    <p class="report-link-empty">Record linking is restricted to authorized departments.</p>
                @endif
            </aside>
        </div>

        <footer class="report-editor-actions">
            <button class="button-secondary report-discard-button" type="button" data-report-discard>DISCARD</button>
            <button class="button-primary" type="submit" wire:loading.attr="disabled" wire:target="saveReport,uploads">
                <span wire:loading.remove wire:target="saveReport,uploads">{{ $report ? 'SAVE REPORT' : 'PUBLISH REPORT' }}</span>
                <span wire:loading wire:target="uploads">PREPARING MEDIA…</span>
                <span wire:loading wire:target="saveReport">PUBLISHING…</span>
            </button>
        </footer>
    </form>

    <div class="report-preview-backdrop" data-report-preview-backdrop hidden role="presentation">
        <section class="report-preview-modal" role="dialog" aria-modal="true" aria-labelledby="report-preview-title">
            <header><div><span class="section-kicker">REPORT PREVIEW</span><h2 id="report-preview-title">Situation report</h2></div><button type="button" data-report-preview-close aria-label="Close report preview">×</button></header>
            <div class="report-preview-content" data-report-preview-content></div>
        </section>
    </div>
</section>
