<dialog class="video-viewer-dialog" data-video-viewer-dialog>
    <header class="video-viewer-header">
        <div><span class="section-kicker">PRIVATE VIDEO VIEWER</span><h2 data-video-viewer-title>Video preview</h2></div>
        <div class="video-viewer-actions">
            <a data-video-viewer-download download>DOWNLOAD</a>
            <button type="button" data-close-video-viewer aria-label="Close video viewer">×</button>
        </div>
    </header>
    <div class="video-viewer-stage">
        <video data-video-viewer-player controls playsinline preload="none"></video>
        <p class="video-viewer-fallback" data-video-viewer-fallback hidden>This format cannot be previewed in this browser. Use the download control to open the original file.</p>
    </div>
</dialog>
