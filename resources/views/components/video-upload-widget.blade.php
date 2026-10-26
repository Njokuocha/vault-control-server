@persist('video-upload-widget')
    <section
        class="video-upload-widget"
        data-video-upload-widget
        data-initialize-url="{{ route('video-uploads.initialize') }}"
        data-csrf-token="{{ csrf_token() }}"
        aria-label="Background video uploads"
        hidden
    >
        <div class="video-upload-widget__header">
            <div>
                <span class="section-kicker">BACKGROUND TRANSFERS</span>
                <strong>VIDEO UPLOADS <span data-video-upload-count>0</span></strong>
            </div>
            <div class="video-upload-widget__actions">
                <button type="button" data-video-upload-clear>Clear completed</button>
                <button type="button" data-video-upload-expand aria-label="Expand uploads">↗</button>
                <button type="button" data-video-upload-close aria-label="Close upload panel">×</button>
            </div>
        </div>
        <div class="video-upload-widget__list" data-video-upload-list aria-live="polite"></div>
        <p class="video-upload-widget__empty" data-video-upload-empty>No video transfers are active.</p>
    </section>
@endpersist
