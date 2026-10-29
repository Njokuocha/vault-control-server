<section class="archive-panel" aria-labelledby="archive-title">
    <div class="archive-toolbar">
        <div><div class="section-kicker">REPOSITORY INDEX</div><h2 id="archive-title">File archive <span class="archive-count" data-archive-count>{{ number_format($visibleCount) }}</span></h2></div>
        <div class="archive-controls" role="group" aria-label="File type filters">
            <button class="filter-button {{ $filter === 'all' ? 'is-selected' : '' }}" type="button" data-archive-filter="all" wire:click="updateFilter('all')" aria-pressed="{{ $filter === 'all' ? 'true' : 'false' }}">ALL RECORDS <span data-archive-filter-count="fileCount">{{ number_format($fileCount) }}</span></button>
            <button class="filter-button {{ $filter === 'documents' ? 'is-selected' : '' }}" type="button" data-archive-filter="documents" wire:click="updateFilter('documents')" aria-pressed="{{ $filter === 'documents' ? 'true' : 'false' }}">DOCUMENTS <span data-archive-filter-count="documentCount">{{ number_format($documentCount) }}</span></button>
            <button class="filter-button {{ $filter === 'media' ? 'is-selected' : '' }}" type="button" data-archive-filter="media" wire:click="updateFilter('media')" aria-pressed="{{ $filter === 'media' ? 'true' : 'false' }}">MEDIA <span data-archive-filter-count="mediaCount">{{ number_format($mediaCount) }}</span></button>
        </div>
    </div>
    <div class="archive-table-head" aria-hidden="true"><span>RECORD</span><span>{{ $fileRoutePrefix === 'fm.files' ? 'FILE TYPE' : 'CLASSIFICATION' }}</span><span>UPLOAD METHOD</span><span>DATE ADDED</span><span>SIZE</span><span></span></div>
    @if ($fileCount === 0)
        <div class="archive-empty" data-empty-state>
            <div class="empty-ornament" aria-hidden="true"><span class="orbit orbit-one"></span><span class="orbit orbit-two"></span><span class="orbit-center"><svg viewBox="0 0 48 48" fill="none"><path d="M8 14.5h12l3.2 3.8H40v17H8z" stroke="currentColor" stroke-width="1.5"/><path d="M8 14.5V11h12l3.2 3.5" stroke="currentColor" stroke-width="1.5"/><path d="m19.5 25 3 3 6-6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span></div>
            <div class="empty-code"><span></span> ARCHIVE STATUS <span class="empty-code-muted">/ 000</span></div><h3>Archive is clear</h3><p>There are no records in this repository yet. Uploaded files will appear here automatically.</p>
            <div class="empty-divider"><span></span><b>INGESTION CHANNEL</b><span></span></div>
            <div class="endpoint-card"><span class="endpoint-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m8.5 7-5 5 5 5M15.5 7l5 5-5 5M14 4l-4 16"/></svg></span><span class="endpoint-copy"><span>ACTIVE UPLOAD API</span><code>POST&nbsp; /api/upload</code></span><span class="endpoint-lock" title="Key-protected endpoint"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5.5" y="10" width="13" height="10" rx="1.5"/><path d="M8.5 10V7a3.5 3.5 0 0 1 7 0v3M12 14v2"/></svg></span></div>
        </div>
        <div class="archive-records" data-archive-records hidden></div>
        <div class="archive-full-link-row" hidden><button type="button" class="archive-full-link" data-archive-open>VIEW FULL ARCHIVE <span aria-hidden="true">↗</span></button></div>
        <div class="archive-filter-empty" data-filter-empty hidden>No files match the current search or filter.</div>
    @else
        <div class="archive-records" data-archive-records>
            @foreach ($fileGroups as $month => $monthFiles)
                <section class="archive-month" data-archive-month>
                    <h3 class="archive-month-heading">{{ $groupLabels[$month] }} <span>{{ $monthFiles->count() }} FILE{{ $monthFiles->count() === 1 ? '' : 'S' }}</span></h3>
                    @foreach ($monthFiles as $file)
                        @php($filterGroup = in_array($file->category, ['image', 'video', 'audio'], true) ? 'media' : (in_array($file->category, ['pdf', 'text', 'code'], true) ? 'documents' : 'other'))
                        <article class="archive-record" data-archive-record data-archive-search="{{ strtolower($file->original_name.' '.$file->mime_type.' '.$file->category) }}" data-archive-category="{{ $filterGroup }}">
                            <div class="archive-record-name"><span class="archive-file-icon" aria-hidden="true">{{ strtoupper($file->extension ?: 'FILE') }}</span><span><strong>{{ $file->original_name }}</strong><small>{{ $file->mime_type }}</small></span></div>
                            <span class="archive-record-category">{{ strtoupper($file->category) }}</span>
                            <span class="upload-method-badge upload-method-{{ $file->upload_method ?? 'unknown' }}">{{ strtoupper($file->upload_method ?? 'UNKNOWN') }}</span>
                            <time class="archive-record-date" datetime="{{ $file->created_at->toIso8601String() }}">{{ $file->created_at->format('M j, Y · H:i') }}</time>
                            <span class="archive-record-size">{{ \App\Support\FormatBytes::format($file->size) }}</span>
                            <div class="archive-record-actions"><button type="button" data-archive-preview="{{ $file->uuid }}" wire:click="preview('{{ $file->uuid }}')" wire:loading.attr="disabled" wire:target="preview" aria-label="Preview {{ $file->original_name }}">OPEN</button><a href="{{ route($fileRoutePrefix.'.download', ['file' => $file->uuid]) }}" aria-label="Download {{ $file->original_name }}">↓</a></div>
                        </article>
                    @endforeach
                </section>
            @endforeach
        </div>
        <div class="archive-full-link-row"><button type="button" class="archive-full-link" data-archive-open>VIEW FULL ARCHIVE <span aria-hidden="true">↗</span></button></div>
        <div class="archive-filter-empty" data-filter-empty hidden>No files match the current search or filter.</div>
    @endif
    <div class="archive-footer"><span><span class="status-dot status-dot-muted"></span> INDEX SYNCHRONIZED</span><span data-dashboard-record-summary>{{ number_format($visibleCount) }} LATEST OF {{ number_format($fileCount) }} RECORDS <b>·</b> {{ $groupCount }} GROUPS</span></div>
</section>
