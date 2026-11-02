<dialog class="video-viewer-dialog" data-video-viewer-dialog aria-labelledby="video-viewer-title">
    <header class="video-viewer-header">
        <div class="video-viewer-heading">
            <span class="section-kicker">PRIVATE VIDEO PLAYBACK</span>
            <h2 data-video-viewer-title id="video-viewer-title">Video preview</h2>
        </div>
        <div class="video-viewer-actions">
            <nav class="video-mode-selector" data-video-mode-selector aria-label="Video player mode">
                <span data-video-mode-indicator aria-hidden="true"></span>
                <button type="button" data-video-mode="normal" aria-pressed="true">NORMAL</button>
                <button type="button" data-video-mode="standard" aria-pressed="false">STANDARD</button>
                <button type="button" data-video-mode="professional" aria-pressed="false">PRO</button>
            </nav>
            <a data-video-viewer-download download aria-label="Download video" title="Download video">
                <i class="ri ri-download-2-line" data-video-viewer-download-icon aria-hidden="true"></i>
                <span>DOWNLOAD</span>
                <span class="video-download-spinner" data-video-viewer-download-spinner aria-hidden="true" hidden></span>
            </a>
            <button type="button" data-close-video-viewer aria-label="Close video viewer">×</button>
        </div>
    </header>
    <div class="video-viewer-stage" data-video-viewer-stage data-video-mode="normal">
        <div class="video-viewer-player-host" data-video-viewer-player-host></div>
        <div class="video-professional-hud" data-video-professional-hud hidden>
            <span><i></i> PLAYBACK · CAM 01</span>
            <span data-video-hud-time>00:00:00 / 00:00:00</span>
        </div>
        <div class="video-professional-controls" data-video-professional-controls hidden>
            <div class="video-professional-timeline">
                <span data-video-current-time>00:00</span>
                <input type="range" min="0" max="1000" value="0" step="1" data-video-seek aria-label="Seek through video">
                <span data-video-duration>00:00</span>
            </div>
            <div class="video-professional-toolbar">
                <div class="video-professional-group">
                    <button type="button" data-video-seek-back aria-label="Go back 10 seconds" title="Back 10 seconds"><i class="ri ri-replay-10-line" aria-hidden="true"></i></button>
                    <button type="button" data-video-play aria-label="Play video" title="Play"><i class="ri ri-play-fill" aria-hidden="true"></i></button>
                    <button type="button" data-video-seek-forward aria-label="Go forward 10 seconds" title="Forward 10 seconds"><i class="ri ri-forward-10-line" aria-hidden="true"></i></button>
                    <button type="button" data-video-loop aria-label="Enable video loop" aria-pressed="false" title="Loop"><i class="ri ri-repeat-line" aria-hidden="true"></i></button>
                </div>
                <div class="video-professional-group video-professional-adjustments">
                    <button type="button" data-video-mute aria-label="Mute video" title="Mute"><i class="ri ri-volume-up-line" aria-hidden="true"></i></button>
                    <input type="range" min="0" max="1" value="1" step="0.05" data-video-volume aria-label="Video volume">
                    <label class="video-speed-control">SPEED
                        <select data-video-speed aria-label="Playback speed">
                            <option value="0.5">0.5×</option>
                            <option value="0.75">0.75×</option>
                            <option value="1" selected>1×</option>
                            <option value="1.25">1.25×</option>
                            <option value="1.5">1.5×</option>
                            <option value="2">2×</option>
                        </select>
                    </label>
                    <button type="button" data-video-grayscale aria-label="Enable grayscale video" aria-pressed="false" title="Grayscale"><i class="ri ri-contrast-2-line" aria-hidden="true"></i></button>
                    <button type="button" data-video-fullscreen aria-label="Enter fullscreen" title="Fullscreen"><i class="ri ri-fullscreen-line" aria-hidden="true"></i></button>
                </div>
            </div>
        </div>
        <p class="video-viewer-fallback" data-video-viewer-fallback hidden>This format cannot be previewed in this browser. Use the download control to open the original file.</p>
    </div>
</dialog>
