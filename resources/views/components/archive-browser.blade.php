<div class="archive-browser" data-archive-browser data-files-url="{{ route('archive.files') }}" data-file-url="{{ url('/files') }}" hidden>
    <section class="archive-browser-window" role="dialog" aria-modal="true" aria-labelledby="full-archive-title">
        <header class="archive-browser-header">
            <div>
                <div class="section-kicker">REPOSITORY INDEX</div>
                <h2 id="full-archive-title">Full archive <span data-full-archive-total>—</span></h2>
            </div>
            <button type="button" class="archive-browser-close" data-archive-close aria-label="Close full archive">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </header>
        <div class="archive-browser-body">
            <section class="archive-browser-list-panel" aria-label="Archive records">
                <div class="archive-browser-filters">
                    <label class="archive-search-field">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.3"/><path d="m15.5 15.5 4.2 4.2"/></svg>
                        <input type="search" placeholder="Search name or classification" aria-label="Search archive by name or classification" data-full-archive-search>
                    </label>
                    <label class="archive-select-field">
                        <span>YEAR</span>
                        <select aria-label="Filter archive by year" data-full-archive-year><option value="">All</option></select>
                    </label>
                    <label class="archive-select-field">
                        <span>MONTH</span>
                        <select aria-label="Filter archive by month" data-full-archive-month>
                            <option value="">All</option>
                            @foreach (range(1, 12) as $month)
                                <option value="{{ $month }}">{{ \Illuminate\Support\Carbon::create()->month($month)->format('M') }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="archive-select-field archive-extension-field">
                        <span>TYPE</span>
                        <select aria-label="Filter archive by file extension" data-full-archive-extension>
                            <option value="">All extensions</option>
                        </select>
                    </label>
                    <label class="archive-select-field">
                        <span>UPLOAD METHOD</span>
                        <select aria-label="Filter archive by upload method" data-full-archive-upload-method>
                            <option value="">All methods</option>
                            <option value="api">API</option>
                            <option value="manual">Manual</option>
                        </select>
                    </label>
                </div>
                <div class="archive-browser-table-head" aria-hidden="true">
                    <span>RECORD</span><span>CLASSIFICATION</span><span>UPLOAD METHOD</span><span>DATE ADDED</span><span>SIZE</span><span></span>
                </div>
                <div class="archive-browser-records" data-full-archive-records tabindex="0">
                    <div class="archive-browser-state" data-full-archive-state>Open the archive to load records.</div>
                </div>
                <div class="archive-browser-loading" data-full-archive-loading hidden>Loading archive records…</div>
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
                    <footer class="archive-browser-preview-footer">
                        <span>PRIVATE FILE · AUTHENTICATED SESSION</span>
                        <a data-full-archive-preview-download href="#" download>DOWNLOAD</a>
                    </footer>
                </div>
            </section>
        </div>
    </section>
</div>
