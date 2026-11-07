@persist('file-preview-modal')
    <div class="file-preview-backdrop" data-file-preview hidden>
        <section class="file-preview-window" role="dialog" aria-modal="true" aria-labelledby="file-preview-title">
            <header class="file-preview-titlebar">
                <div><span class="preview-file-mark" aria-hidden="true">FILE</span><span><strong id="file-preview-title" data-preview-title>File preview</strong><small data-preview-meta></small></span></div>
                <button type="button" data-preview-close aria-label="Close file preview"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
            </header>
            <div class="file-preview-content" data-preview-content></div>
            <footer class="file-preview-footer"><span>PRIVATE FILE · AUTHENTICATED SESSION</span><a data-preview-download href="#" download>DOWNLOAD</a></footer>
        </section>
    </div>
@endpersist
