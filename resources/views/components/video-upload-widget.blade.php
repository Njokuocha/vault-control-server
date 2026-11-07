@persist('upload-tracker')
    <section
        id="upload-tracker-panel"
        class="video-upload-widget"
        data-video-upload-widget
        data-upload-tracker
        data-initialize-url="{{ route('video-uploads.initialize') }}"
        data-transfer-initialize-url="{{ route('upload-transfers.initialize') }}"
        data-snapshot-url="{{ route('upload-transfers.index') }}"
        data-upload-page-url="{{ auth('web')->check() && auth('web')->user()->department === 'fm' ? route('fm.uploads.create') : route('uploads.create') }}"
        data-csrf-token="{{ csrf_token() }}"
        aria-label="Background upload tracker"
        hidden
    >
        <div class="video-upload-widget__header">
            <div>
                <span class="section-kicker">BACKGROUND TRANSFERS</span>
                <strong>TOTAL <span data-upload-total>0</span> <span aria-hidden="true">·</span> PENDING <span data-upload-pending>0</span></strong>
            </div>
            <div class="video-upload-widget__actions">
                <button type="button" data-upload-tracker-clear>Clear completed</button>
                <button type="button" data-upload-tracker-expand aria-label="Expand uploads">↗</button>
                <button type="button" data-upload-tracker-close aria-label="Close upload panel">×</button>
            </div>
        </div>
        <div class="video-upload-widget__list" data-upload-tracker-list aria-live="polite"></div>
        <p class="video-upload-widget__empty" data-upload-tracker-empty>No uploads are being tracked.</p>
    </section>
@endpersist
