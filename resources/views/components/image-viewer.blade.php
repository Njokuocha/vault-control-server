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
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v11m-4-4 4 4 4-4"/><path d="M5 15v4a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-4"/></svg>
            </a>
            <button type="button" data-vault-image-close aria-label="Close image viewer">×</button>
        </div>
    </header>
    <div class="image-viewer-stage" data-vault-image-stage>
        <img data-vault-image alt="" draggable="false">
        <span class="image-viewer-hint" aria-hidden="true">SCROLL TO ZOOM · DRAG TO PAN</span>
    </div>
</dialog>
