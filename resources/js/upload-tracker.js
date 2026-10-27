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
        const heading = document.createElement('div');
        heading.className = 'video-upload-item__heading';
        const name = document.createElement('strong');
        name.textContent = upload.name;
        const status = document.createElement('span');
        status.textContent = String(upload.status).replaceAll('-', ' ');
        heading.append(name, status);

        const metadata = document.createElement('div');
        metadata.className = 'video-upload-item__metadata';
        const size = document.createElement('span');
        size.textContent = formatBytes(upload.size);
        const percent = document.createElement('span');
        percent.textContent = `${Math.round(upload.progress ?? 0)}%`;
        metadata.append(size, percent);

        const progress = document.createElement('progress');
        progress.max = 100;
        progress.value = Math.max(0, Math.min(100, upload.progress ?? 0));
        progress.setAttribute('aria-label', `Upload progress for ${upload.name}`);
        item.append(heading, metadata, progress);

        if (upload.error) {
            const error = document.createElement('p');
            error.className = 'video-upload-item__error';
            error.textContent = upload.error;
            item.append(error);
        }

        const actions = document.createElement('div');
        actions.className = 'video-upload-item__actions';
        for (const action of ['cancel', 'resume', 'retry', 'remove']) {
            if (typeof upload.actions?.[action] !== 'function') continue;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'video-upload-item__action';
            button.textContent = action[0].toUpperCase() + action.slice(1);
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    await upload.actions[action]();
                } catch (error) {
                    window.vaultAlert({
                        type: 'error',
                        message: error instanceof Error ? error.message : `Unable to ${action} ${upload.name}.`,
                    });
                } finally {
                    button.disabled = false;
                }
            });
            actions.append(button);
        }
        if (actions.childElementCount > 0) item.append(actions);
        list.append(item);
    }
};

export const updateUploadTrackerItem = (upload) => {
    const existing = items.get(upload.id) ?? {};
    items.set(upload.id, { ...existing, ...upload });
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
                Promise.resolve(upload.actions.remove()).catch((error) => {
                    window.vaultAlert({
                        type: 'error',
                        message: error instanceof Error ? error.message : `Unable to remove ${upload.name}.`,
                    });
                });
            }
        }
    }
});

if (widget) {
    render();
}
