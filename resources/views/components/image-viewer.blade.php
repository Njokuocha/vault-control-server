<dialog class="image-viewer-dialog" data-vault-image-viewer aria-labelledby="vault-image-viewer-title">
    <header class="image-viewer-header">
        <div class="image-viewer-heading"><span class="section-kicker">PRIVATE IMAGE VIEWER</span><h2 id="vault-image-viewer-title" data-vault-image-title>Image preview</h2></div>
        <div class="image-viewer-tools">
            <div class="image-zoom-controls" aria-label="Image zoom controls">
                <button type="button" data-vault-image-zoom-out aria-label="Zoom out" title="Zoom out">−</button>
                <span data-vault-image-zoom-level aria-live="polite">100%</span>
                <button type="button" data-vault-image-zoom-in aria-label="Zoom in" title="Zoom in">+</button>
            </div>
            <label class="image-grayscale-toggle">
                <input type="checkbox" data-vault-image-grayscale>
                <span class="image-grayscale-switch" aria-hidden="true"></span>
                <span>GRAYSCALE</span>
            </label>
            <a class="image-viewer-download" data-vault-image-download download aria-label="Download original image" title="Download original image">
                <svg data-vault-image-download-icon viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11m-4-4 4 4 4-4"/><path d="M5 15v4a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4"/></svg>
                <span class="image-download-spinner" data-vault-image-download-spinner hidden aria-hidden="true"></span>
            </a>
            <button type="button" data-vault-image-close aria-label="Close image viewer">×</button>
        </div>
    </header>
    <div class="image-viewer-stage" data-vault-image-stage>
        <img data-vault-image alt="" draggable="false">
        <div class="image-viewer-info-pane" aria-label="Image details and controls">
            <div class="image-viewer-metadata">
                <span><small>DIMENSIONS</small><strong data-vault-image-dimensions>—</strong></span>
                <span><small>EXTENSION</small><strong data-vault-image-extension>—</strong></span>
            </div>
            <div class="image-viewer-actions">
                <button type="button" data-vault-image-slow-zoom aria-label="Start slow zoom" aria-pressed="false" title="Start slow zoom">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path data-vault-image-motion-play d="m10 8 6 4-6 4z"/><path data-vault-image-motion-pause d="M9 8v8m6-8v8" hidden/></svg>
                </button>
                <button type="button" data-vault-image-rotate aria-label="Rotate image 90 degrees" title="Rotate image 90 degrees">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a8 8 0 0 0-14.5-4L4 9"/><path d="M4 4v5h5m-5 4a8 8 0 0 0 14.5 4L20 15"/><path d="M20 20v-5h-5"/></svg>
                </button>
            </div>
        </div>
        <span class="image-viewer-hint" aria-hidden="true">SCROLL TO ZOOM · DRAG TO PAN</span>
    </div>
</dialog>
