const widget = document.querySelector('[data-upload-tracker]');
const items = new Map();
const activeStatuses = new Set(['uploading', 'pending', 'processing', 'queued', 'finalizing']);
const terminalStatuses = new Set(['done', 'completed', 'failed', 'cancelled']);
let serverTotals = { total: 0, pending: 0 };
let expanded = false;

const list = widget?.querySelector('[data-upload-tracker-list]');
const empty = widget?.querySelector('[data-upload-tracker-empty]');
const totalLabel = widget?.querySelector('[data-upload-total]');
const pendingLabel = widget?.querySelector('[data-upload-pending]');
const badge = document.querySelector('[data-upload-tracker-badge]');

const formatBytes = (bytes) => {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const formatDuration = (seconds) => {
    if (!Number.isFinite(seconds)) return 'In progress';
    const elapsed = Math.max(0, Math.round(seconds));
    if (elapsed < 60) return `${elapsed} sec`;
    return `${Math.floor(elapsed / 60)} min ${String(elapsed % 60).padStart(2, '0')} sec`;
};

const formatUploadedAt = (value) => {
    const uploadedAt = new Date(value);
    if (Number.isNaN(uploadedAt.getTime())) return { label: 'Time unavailable', dateTime: '' };

    const elapsedSeconds = Math.max(0, Math.floor((Date.now() - uploadedAt.getTime()) / 1000));
    const label = elapsedSeconds < 60
        ? 'Just now'
        : elapsedSeconds < 60 * 60
            ? `${Math.floor(elapsedSeconds / 60)} min ago`
            : elapsedSeconds < 24 * 60 * 60
                ? uploadedAt.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
                : uploadedAt.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

    return { label, dateTime: uploadedAt.toISOString(), title: uploadedAt.toLocaleString() };
};

const isPreviewable = (mimeType, fileType) => {
    if (typeof mimeType === 'string' && (
        mimeType.startsWith('image/')
        || mimeType.startsWith('video/')
        || mimeType.startsWith('audio/')
        || mimeType.startsWith('text/')
        || ['application/pdf', 'application/json', 'application/xml', 'application/javascript', 'application/zip'].includes(mimeType)
    )) return true;

    return String(fileType ?? '').toLowerCase() === 'zip';
};

const previewUpload = async (upload) => {
    const response = await fetch(upload.preview_url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    let file;
    try {
        file = await response.json();
    } catch {
        throw new Error('The preview server returned an unreadable response.');
    }
    if (!response.ok) throw new Error(file.message ?? `Unable to preview this file (${response.status}).`);

    window.dispatchEvent(new CustomEvent('vault-file-preview-ready', {
        detail: { uuid: upload.file_uuid, file },
    }));
};

const runAction = async (upload, action) => {
    const current = items.get(upload.id);
    if (!current || current.removing) return;
    if (action === 'remove') {
        current.removing = true;
        render();
    }

    try {
        await upload.actions[action]();
    } catch (error) {
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : `Unable to ${action} ${upload.name}.`,
        });
    } finally {
        const latest = items.get(upload.id);
        if (latest?.removing) {
            latest.removing = false;
            render();
        }
    }
};

const render = () => {
    if (!widget || !list || !empty) return;

    const uploads = [...items.values()];
    const activeLegacy = uploads.filter((item) => item.kind === 'legacy-video' && activeStatuses.has(item.status)).length;
    const total = serverTotals.total + uploads.filter((item) => item.kind === 'legacy-video').length;
    const pending = serverTotals.pending + activeLegacy;
    totalLabel.textContent = String(total);
    pendingLabel.textContent = String(pending);
    badge.textContent = String(pending);
    badge.hidden = pending === 0;
    empty.hidden = uploads.length > 0;
    list.replaceChildren();

    const visible = expanded
        ? uploads
        : [
            ...uploads.filter((item) => activeStatuses.has(item.status)).slice(-8),
            ...uploads.filter((item) => !activeStatuses.has(item.status)).slice(-4),
        ];

    for (const upload of visible) {
        const item = document.createElement('article');
        item.className = 'video-upload-item';
        item.dataset.status = String(upload.status);
        const heading = document.createElement('div');
        heading.className = 'video-upload-item__heading';
        const name = document.createElement('strong');
        name.textContent = upload.name;
        const status = document.createElement('span');
        status.className = 'video-upload-item__status';
        status.textContent = String(upload.status).replaceAll('-', ' ');
        heading.append(name, status);

        const metadata = document.createElement('div');
        metadata.className = 'video-upload-item__metadata';
        const size = document.createElement('span');
        size.textContent = formatBytes(upload.size);
        const fileType = document.createElement('span');
        const nameParts = String(upload.name).split('.');
        const extension = String(upload.file_type ?? (nameParts.length > 1 ? nameParts.pop() : '')).trim();
        fileType.textContent = extension ? extension.toUpperCase() : 'FILE';
        metadata.append(size, fileType);

        const details = document.createElement('div');
        details.className = 'video-upload-item__details';
        const uploadedAt = formatUploadedAt(upload.uploaded_at ?? upload.tracked_at);
        const uploadedTime = document.createElement('time');
        uploadedTime.dateTime = uploadedAt.dateTime;
        uploadedTime.textContent = uploadedAt.label;
        if (uploadedAt.title) uploadedTime.title = uploadedAt.title;
        const timestamp = document.createElement('span');
        timestamp.className = 'video-upload-item__detail';
        timestamp.append(Object.assign(document.createElement('small'), { textContent: 'UPLOADED' }), uploadedTime);
        const timeLabel = document.createElement('span');
        timeLabel.className = 'video-upload-item__detail';
        const timeValue = document.createElement('span');
        const duration = typeof upload.duration_seconds === 'number' && Number.isFinite(upload.duration_seconds)
            ? Number(upload.duration_seconds)
            : (Date.now() - new Date(upload.uploaded_at ?? upload.tracked_at).getTime()) / 1000;
        timeLabel.append(
            Object.assign(document.createElement('small'), {
                textContent: terminalStatuses.has(upload.status) ? 'DURATION' : 'ELAPSED',
            }),
        );
        timeValue.textContent = formatDuration(duration);
        timeLabel.append(timeValue);
        details.append(timestamp, timeLabel);

        const percent = document.createElement('span');
        percent.textContent = `${Math.round(upload.progress ?? 0)}%`;

        const progress = document.createElement('progress');
        progress.max = 100;
        progress.value = Math.max(0, Math.min(100, upload.progress ?? 0));
        progress.setAttribute('aria-label', `Upload progress for ${upload.name}`);
        const progressLabel = document.createElement('span');
        progressLabel.className = 'video-upload-item__progress-label';
        progressLabel.textContent = `${Math.round(upload.progress ?? 0)}%`;
        const progressRow = document.createElement('div');
        progressRow.className = 'video-upload-item__progress-row';
        progressRow.append(progress, progressLabel);
        item.append(heading, metadata, details, progressRow);

        if (upload.error) {
            const error = document.createElement('p');
            error.className = 'video-upload-item__error';
            error.textContent = upload.error;
            item.append(error);
        }

        const actions = document.createElement('div');
        actions.className = 'video-upload-item__actions';
        if (upload.status === 'done' && upload.preview_url && upload.file_uuid && isPreviewable(upload.file_mime_type, upload.file_type)) {
            upload.actions = { ...upload.actions, view: () => previewUpload(upload) };
        }
        for (const action of ['view', 'cancel', 'resume', 'retry', 'remove']) {
            if (typeof upload.actions?.[action] !== 'function') continue;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'video-upload-item__action';
            button.textContent = action === 'remove' && upload.removing
                ? 'Removing...'
                : action[0].toUpperCase() + action.slice(1);
            button.disabled = upload.removing === true;
            button.addEventListener('click', () => runAction(upload, action));
            actions.append(button);
        }
        if (actions.childElementCount > 0) item.append(actions);
        list.append(item);
    }
};

export const updateUploadTrackerItem = (upload) => {
    const existing = items.get(upload.id) ?? {};
    items.set(upload.id, {
        ...existing,
        ...upload,
        tracked_at: upload.uploaded_at ?? existing.uploaded_at ?? existing.tracked_at ?? new Date().toISOString(),
    });
    render();
};

export const removeUploadTrackerItem = (id) => {
    items.delete(id);
    render();
};

export const setUploadTrackerTotals = ({ totalUploads, pendingUploads }) => {
    serverTotals = {
        total: Math.max(0, Number(totalUploads) || 0),
        pending: Math.max(0, Number(pendingUploads) || 0),
    };
    render();
};

export const openUploadTracker = () => {
    const currentWidget = document.querySelector('[data-upload-tracker]');
    if (!currentWidget) return;
    currentWidget.hidden = false;
    document.querySelector('[data-upload-tracker-toggle]')?.setAttribute('aria-expanded', 'true');
};

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const currentWidget = document.querySelector('[data-upload-tracker]');
    if (!currentWidget) return;

    if (event.target.closest('[data-upload-tracker-toggle]')) {
        currentWidget.hidden = !currentWidget.hidden;
        document.querySelector('[data-upload-tracker-toggle]')?.setAttribute('aria-expanded', String(!currentWidget.hidden));
    } else if (event.target.closest('[data-upload-tracker-close]')) {
        currentWidget.hidden = true;
        document.querySelector('[data-upload-tracker-toggle]')?.setAttribute('aria-expanded', 'false');
    } else if (event.target.closest('[data-upload-tracker-expand]')) {
        expanded = !expanded;
        currentWidget.classList.toggle('is-expanded', expanded);
        render();
    } else if (event.target.closest('[data-upload-tracker-clear]')) {
        for (const upload of items.values()) {
            if (terminalStatuses.has(upload.status) && typeof upload.actions?.remove === 'function') {
                runAction(upload, 'remove');
            }
        }
    }
});

if (widget) {
    render();
}
