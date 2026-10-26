<div class="archive-browser" data-archive-browser data-file-url="{{ url('/files') }}" @unless($isOpen) hidden @endunless wire:keydown.escape="close" wire:click.self="close">
    @if ($isOpen)
        <section class="archive-browser-window" role="dialog" aria-modal="true" aria-labelledby="full-archive-title">
            <header class="archive-browser-header">
                <div>
                    <div class="section-kicker">REPOSITORY INDEX</div>
                    <h2 id="full-archive-title">Full archive <span data-full-archive-total>{{ number_format($files->total()) }} RECORDS</span></h2>
                </div>
                <button type="button" class="archive-browser-close" wire:click="close" aria-label="Close full archive">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
                </button>
            </header>
            <div class="archive-browser-body">
                <section class="archive-browser-list-panel" aria-label="Archive records">
                    <div class="archive-browser-filters">
                        <label class="archive-search-field">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg>
                            <input type="search" placeholder="Search name or classification" aria-label="Search archive by name or classification" data-full-archive-search wire:model.live.debounce.250ms="search">
                        </label>
                        <label class="archive-select-field">
                            <span>YEAR</span>
                            <select aria-label="Filter archive by year" data-full-archive-year wire:model.live="year"><option value="">All</option>@foreach ($years as $option)<option value="{{ $option }}">{{ $option }}</option>@endforeach</select>
                        </label>
                        <label class="archive-select-field">
                            <span>MONTH</span>
                            <select aria-label="Filter archive by month" data-full-archive-month wire:model.live="month">
                                <option value="">All</option>
                                @foreach (range(1, 12) as $option)<option value="{{ $option }}">{{ \Illuminate\Support\Carbon::create()->month($option)->format('M') }}</option>@endforeach
                            </select>
                        </label>
                        <label class="archive-select-field archive-extension-field">
                            <span>TYPE</span>
                            <select aria-label="Filter archive by file extension" data-full-archive-extension wire:model.live="extension">
                                <option value="">All extensions</option>
                                @foreach ($extensions as $option)<option value="{{ $option }}">{{ $option === '__none__' ? 'No extension' : '.'.$option }}</option>@endforeach
                            </select>
                        </label>
                        <label class="archive-select-field">
                            <span>UPLOAD METHOD</span>
                            <select aria-label="Filter archive by upload method" data-full-archive-upload-method wire:model.live="uploadMethod">
                                <option value="">All methods</option><option value="api">API</option><option value="manual">Manual</option>
                            </select>
                        </label>
                    </div>
                    <div class="archive-browser-table-head" aria-hidden="true">
                        <span>RECORD</span><span>CLASSIFICATION</span><span>UPLOAD METHOD</span><span>DATE ADDED</span><span>SIZE</span><span></span>
                    </div>
                    <div class="archive-browser-records" data-full-archive-records tabindex="0">
                        @forelse ($files->getCollection()->groupBy(fn ($file) => $file->created_at->format('Y-m')) as $monthKey => $monthFiles)
                            <section class="archive-month" data-archive-month="{{ $monthKey }}">
                                <h3 class="archive-month-heading">{{ $monthFiles->first()->created_at->format('F Y') }} <span>{{ $monthFiles->count() }} FILE{{ $monthFiles->count() === 1 ? '' : 'S' }}</span></h3>
                                @foreach ($monthFiles as $file)
                                    <article class="archive-record {{ $selectedUuid === $file->uuid ? 'is-selected' : '' }}">
                                        <div class="archive-record-name"><span class="archive-file-icon" aria-hidden="true">{{ strtoupper($file->extension ?: 'FILE') }}</span><span><strong>{{ $file->original_name }}</strong><small>{{ $file->mime_type }}</small></span></div>
                                        <span class="archive-record-category">{{ strtoupper($file->category) }}</span>
                                        <span class="upload-method-badge upload-method-{{ $file->upload_method ?? 'unknown' }}">{{ strtoupper($file->upload_method ?? 'UNKNOWN') }}</span>
                                        <time class="archive-record-date" datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('M j, Y · H:i') }}</time>
                                        <span class="archive-record-size">{{ \App\Support\FormatBytes::format($file->size) }}</span>
                                        <div class="archive-record-actions">
                                            <button type="button" data-full-archive-preview="{{ $file->uuid }}" wire:click="preview('{{ $file->uuid }}')" wire:loading.attr="disabled" wire:target="preview">OPEN</button>
                                            <a href="{{ route('files.download', ['file' => $file->uuid]) }}" aria-label="Download {{ $file->original_name }}">↓</a>
                                        </div>
                                    </article>
                                @endforeach
                            </section>
                        @empty
                            <div class="archive-browser-state" data-full-archive-state>No archive records match the selected filters.</div>
                        @endforelse
                    </div>
                    <div class="archive-browser-loading" wire:loading wire:target="search,year,month,extension,uploadMethod,previousPage,nextPage,gotoPage,preview">Updating archive…</div>
                    @if ($files->hasPages())<div class="records-pagination">{{ $files->links() }}</div>@endif
                </section>
                <section class="archive-browser-preview" aria-label="Selected file preview">
                    <div class="archive-browser-preview-empty" data-full-archive-preview-empty>
                        <span class="archive-preview-mark" aria-hidden="true">V</span>
                        <strong>SELECT A RECORD</strong>
                        <span>Open a file from the archive to preview it here.</span>
                    </div>
                    <div class="archive-browser-preview-view" data-full-archive-preview-view hidden>
                        <header class="archive-browser-preview-header">
                            <span class="preview-file-mark" data-full-archive-preview-type>FILE</span>
                            <span><strong data-full-archive-preview-title></strong><small data-full-archive-preview-meta></small></span>
                        </header>
                        <div class="archive-browser-preview-content" data-full-archive-preview-content></div>
                        <footer class="archive-browser-preview-footer"><span>PRIVATE FILE · AUTHENTICATED SESSION</span><a data-full-archive-preview-download href="#" download>DOWNLOAD</a></footer>
                    </div>
                </section>
            </div>
        </section>
    @endif
</div>
