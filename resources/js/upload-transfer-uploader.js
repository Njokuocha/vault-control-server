import {
    openUploadTracker,
    removeUploadTrackerItem,
    setUploadTrackerTotals,
    updateUploadTrackerItem,
} from './upload-tracker';

const transferWidget = document.querySelector('[data-upload-tracker]');
const resumeStorageKey = 'vault.upload-transfer-resume.v1';
const transfers = new Map();
let resumeToken = null;
let selectedResumeToken = null;
let snapshotRequest = null;

const isUploadForm = (form) => form instanceof HTMLFormElement && form.matches('[data-manual-upload]');
const transferKey = (id) => `transfer:${id}`;
const resumeKey = (file) => JSON.stringify([file.name, file.size, file.lastModified]);
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const updateSelectionLabel = (form, count) => {
    const label = form.closest('[data-manual-upload-component]')?.querySelector('[data-upload-selection]');
    if (label) {
        label.textContent = count > 0
            ? `${count} file${count === 1 ? '' : 's'} selected.`
            : 'No files selected.';
    }
    window.Livewire?.dispatch('manual-upload-selection', { count });
};

const readResumeTokens = () => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(resumeStorageKey) ?? '{}');
        return saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    } catch (error) {
        console.error('Unable to read upload resume information.', error);
        window.vaultAlert({ type: 'warning', message: 'This browser could not restore saved upload resume information.' });
        return {};
    }
};

const rememberResumeToken = (file, token) => {
    try {
        const saved = readResumeTokens();
        saved[resumeKey(file)] = token;
        sessionStorage.setItem(resumeStorageKey, JSON.stringify(saved));
    } catch (error) {
        console.error('Unable to save upload resume information.', error);
        window.vaultAlert({ type: 'warning', message: 'This browser could not save the upload resume reference.' });
    }
};

const forgetResumeToken = (task) => {
    if (!task.file || !task.token) return;
    try {
        const saved = readResumeTokens();
        if (saved[resumeKey(task.file)] === task.token) delete saved[resumeKey(task.file)];
        sessionStorage.setItem(resumeStorageKey, JSON.stringify(saved));
    } catch (error) {
        console.error('Unable to clear upload resume information.', error);
    }
};

const parseResponse = async (response) => {
    let result;
    try {
        result = await response.json();
    } catch {
        throw new Error('The upload server returned an unreadable response.');
    }
    if (!response.ok) throw new Error(result.message ?? 'The upload request failed.');
    return result;
};

const publishTransfer = (task) => {
    const actions = {};
    if (['uploading', 'pending'].includes(task.status)) {
        actions.cancel = () => cancelTransfer(task);
        if (task.status === 'uploading' && task.file && task.error) actions.retry = () => retryTransfer(task);
    }
    if (task.status === 'uploading' && !task.file) actions.resume = () => selectFileForResume(task);
    if (['failed', 'cancelled'].includes(task.status)) {
        actions.retry = () => task.file ? retryTransfer(task) : selectFileForResume(task);
    }
    if (['done', 'failed', 'cancelled'].includes(task.status)) actions.remove = () => removeTransfer(task);

    updateUploadTrackerItem({
        id: transferKey(task.id),
        kind: 'server-transfer',
        name: task.name,
        size: task.size,
        status: task.status,
        progress: task.progress ?? 0,
        error: task.error,
        actions,
    });
};

const updateTask = (task, updates) => {
    Object.assign(task, updates);
    publishTransfer(task);
};

const setTotals = (payload) => {
    setUploadTrackerTotals({
        totalUploads: payload.total_uploads,
        pendingUploads: payload.pending_uploads,
    });
};

const refreshSnapshot = async () => {
    if (!transferWidget) return;
    if (snapshotRequest) return snapshotRequest;

    snapshotRequest = (async () => {
        const response = await fetch(transferWidget.dataset.snapshotUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const snapshot = await parseResponse(response);
        setTotals(snapshot);
        const visibleIds = new Set(snapshot.uploads.map((upload) => Number(upload.id)));
        for (const [id, task] of transfers) {
            if (!visibleIds.has(Number(id)) && !task.file) {
                transfers.delete(id);
                removeUploadTrackerItem(transferKey(id));
            }
        }

        snapshot.uploads.forEach((upload) => {
            const id = Number(upload.id);
            const existing = transfers.get(id);
            const task = existing ?? { id };
            Object.assign(task, upload, {
                id,
                receivedChunks: new Set(upload.received_chunks ?? []),
                file: existing?.file ?? null,
                xhr: existing?.xhr ?? null,
            });
            transfers.set(id, task);
            publishTransfer(task);
        });
    })();

    try {
        await snapshotRequest;
    } finally {
        snapshotRequest = null;
    }
};

const fetchStatus = async (task) => {
    const response = await fetch(task.status_url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });
    const result = await parseResponse(response);
    updateTask(task, {
        status: result.status,
        progress: result.progress,
        receivedChunks: new Set(result.received_chunks ?? []),
        error: result.error,
    });
    return result;
};

const sendChunk = (task, index, blob, completedBytes) => new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    task.xhr = xhr;
    xhr.open('POST', task.chunk_url.replace('__INDEX__', String(index)));
    xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
    xhr.setRequestHeader('Accept', 'application/json');
    const body = new FormData();
    body.append('chunk', blob, 'chunk.part');

    xhr.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) return;
        task.progress = Math.min(99, ((completedBytes + event.loaded) / task.size) * 100);
        publishTransfer(task);
    });
    xhr.addEventListener('load', () => {
        task.xhr = null;
        let result;
        try {
            result = JSON.parse(xhr.responseText);
        } catch {
            reject(new Error('The upload server returned an unreadable response.'));
            return;
        }
        if (xhr.status < 200 || xhr.status >= 300) {
            reject(new Error(result.message ?? 'The upload chunk was rejected.'));
            return;
        }
        resolve(result);
    });
    xhr.addEventListener('error', () => {
        task.xhr = null;
        reject(new Error('A network error interrupted the upload.'));
    });
    xhr.addEventListener('abort', () => {
        task.xhr = null;
        reject(new DOMException('The upload was cancelled.', 'AbortError'));
    });
    xhr.send(body);
});

const sendChunks = async (task) => {
    task.cancelRequested = false;
    const received = task.receivedChunks instanceof Set ? task.receivedChunks : new Set(task.received_chunks ?? []);
    let completedBytes = [...received].reduce((total, index) => (
        total + Math.min(task.chunk_size, task.size - (index * task.chunk_size))
    ), 0);
    updateTask(task, {
        status: 'uploading',
        progress: Math.min(99, (completedBytes / task.size) * 100),
        receivedChunks: received,
        error: null,
    });

    for (let index = 0; index < task.chunk_count; index += 1) {
        if (task.cancelRequested) return;
        if (received.has(index)) continue;
        const start = index * task.chunk_size;
        const end = Math.min(task.size, start + task.chunk_size);
        const result = await sendChunk(task, index, task.file.slice(start, end), completedBytes);
        completedBytes += end - start;
        received.add(index);
        task.receivedChunks = received;
        task.progress = Math.min(100, (completedBytes / task.size) * 100);
        if (result.status === 'pending') {
            updateTask(task, { status: 'pending', progress: 100 });
            await fetchStatus(task);
            return;
        }
        publishTransfer(task);
    }

    if (!task.cancelRequested) {
        updateTask(task, { status: 'pending', progress: 100 });
        await fetchStatus(task);
    }
};

const initializeTransfer = async (file) => {
    const response = await fetch(transferWidget.dataset.transferInitializeUrl, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({ name: file.name, size: file.size }),
    });
    const initialized = await parseResponse(response);
    const task = {
        ...initialized,
        name: file.name,
        size: file.size,
        file,
        progress: 0,
        receivedChunks: new Set(initialized.received_chunks ?? []),
        cancelRequested: false,
        error: null,
    };
    transfers.set(Number(task.id), task);
    rememberResumeToken(file, task.token);
    publishTransfer(task);
    return task;
};

const validateFile = (file, form) => {
    const globalLimitMb = Number(form.dataset.maxUploadMb ?? 0);
    const videoLimitMb = Number(form.dataset.maxVideoUploadMb ?? 0);
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    const videoExtensions = new Set(['3gp', 'avi', 'flv', 'm4v', 'mkv', 'mov', 'mp4', 'mpeg', 'mpg', 'ogv', 'ts', 'webm', 'wmv']);
    const isVideo = file.type.startsWith('video/') || videoExtensions.has(extension);
    const limitMb = isVideo && videoLimitMb > 0 ? Math.min(globalLimitMb, videoLimitMb) : globalLimitMb;

    if (file.size < 1) return 'Empty files cannot be uploaded.';
    if (limitMb > 0 && file.size > limitMb * 1024 * 1024) {
        return `${file.name} exceeds the ${limitMb} MB upload limit.`;
    }
    return null;
};

const getResumeCandidate = (file, forcedToken = null) => {
    const candidates = [...transfers.values()].filter((task) => (
        task.name === file.name
        && Number(task.size) === file.size
        && ['uploading', 'failed', 'cancelled'].includes(task.status)
    ));
    if (forcedToken) return candidates.find((task) => task.token === forcedToken) ?? null;

    const storedToken = readResumeTokens()[resumeKey(file)];
    if (storedToken) {
        const remembered = candidates.find((task) => task.token === storedToken);
        if (remembered) return remembered;
    }
    return candidates.length === 1 ? candidates[0] : null;
};

const startFile = async (file, form, forcedToken = null) => {
    const validationError = validateFile(file, form);
    if (validationError) {
        window.vaultAlert({ type: 'error', message: validationError });
        return;
    }

    try {
        let task = getResumeCandidate(file, forcedToken);
        if (task?.file && task.file !== file && ['uploading', 'pending', 'processing'].includes(task.status)) {
            window.vaultAlert({ type: 'info', message: `${file.name} is already being transferred.` });
            return;
        }
        if (!task) task = await initializeTransfer(file);
        task.file = file;
        task.cancelRequested = false;
        rememberResumeToken(file, task.token);

        if (['failed', 'cancelled'].includes(task.status)) {
            const response = await fetch(task.retry_url, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
                credentials: 'same-origin',
            });
            const retry = await parseResponse(response);
            Object.assign(task, retry, {
                id: Number(retry.id),
                file,
                name: file.name,
                size: file.size,
                status: 'uploading',
            });
            task.receivedChunks = new Set(retry.received_chunks ?? []);
        }

        if (task.status === 'uploading') await sendChunks(task);
        else await fetchStatus(task);
    } catch (error) {
        const task = [...transfers.values()].find((candidate) => candidate.file === file);
        if (task && !task.cancelRequested) {
            try {
                const current = await fetchStatus(task);
                if (current.status === 'uploading') {
                    updateTask(task, {
                        status: 'failed',
                        error: error instanceof Error ? error.message : 'The upload failed.',
                    });
                }
            } catch {
                updateTask(task, {
                    status: 'failed',
                    error: error instanceof Error ? error.message : 'The upload failed.',
                });
            }
        } else if (!(error instanceof DOMException && error.name === 'AbortError')) {
            window.vaultAlert({
                type: 'error',
                message: error instanceof Error ? error.message : `Unable to upload ${file.name}.`,
            });
        }
    }
};

const selectFileForResume = (task) => {
    openUploadTracker();
    const input = document.querySelector('[data-upload-input]');
    if (input instanceof HTMLInputElement) {
        resumeToken = task.token;
        input.click();
        return;
    }

    if (typeof window.Livewire?.navigate === 'function') {
        window.Livewire.navigate(transferWidget.dataset.uploadPageUrl);
        window.vaultAlert({ type: 'info', message: 'Open the upload tracker on the Manual upload page and choose Resume again to select the file.' });
    } else {
        window.location.assign(transferWidget.dataset.uploadPageUrl);
    }
};

const cancelTransfer = async (task) => {
    if (!task.cancel_url || !['uploading', 'pending'].includes(task.status)) {
        throw new Error('This upload can no longer be cancelled.');
    }
    const confirmed = await window.vaultConfirm({
        title: 'Cancel upload',
        message: `Cancel ${task.name} and discard its partial upload?`,
        confirmLabel: 'CANCEL UPLOAD',
        destructive: true,
    });
    if (!confirmed) return;

    task.cancelRequested = true;
    task.xhr?.abort();
    const response = await fetch(task.cancel_url, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });
    await parseResponse(response);
    updateTask(task, { status: 'cancelled', progress: 0, error: null });
    forgetResumeToken(task);
    await refreshSnapshot();
};

const retryTransfer = async (task) => {
    if (!task.file) {
        selectFileForResume(task);
        return;
    }
    const current = await fetchStatus(task);
    if (current.status === 'uploading') {
        await sendChunks(task);
        return;
    }
    if (!['failed', 'cancelled'].includes(current.status)) {
        throw new Error('This upload is not currently eligible for retry.');
    }
    const response = await fetch(task.retry_url, {
        method: 'POST',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });
    const retry = await parseResponse(response);
    Object.assign(task, retry, {
        id: Number(retry.id),
        name: task.file.name,
        size: task.file.size,
        receivedChunks: new Set(retry.received_chunks ?? []),
        cancelRequested: false,
    });
    transfers.set(task.id, task);
    rememberResumeToken(task.file, task.token);
    await sendChunks(task);
};

const removeTransfer = async (task) => {
    const response = await fetch(task.remove_url, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });
    await parseResponse(response);
    transfers.delete(Number(task.id));
    forgetResumeToken(task);
    removeUploadTrackerItem(transferKey(task.id));
    await refreshSnapshot();
};

const handleSelection = (input) => {
    const form = input.closest('[data-manual-upload]');
    if (!form) return;
    form.dataset.selectedFiles = '';
    const files = [...(input.files ?? [])];
    const forcedToken = resumeToken;
    resumeToken = null;
    selectedResumeToken = forcedToken;
    updateSelectionLabel(form, files.length);
    const submit = form.querySelector('[data-upload-submit]');
    if (submit instanceof HTMLButtonElement) submit.disabled = files.length === 0;
    if (forcedToken && files.length !== 1) {
        window.vaultAlert({ type: 'warning', message: 'Select exactly one file to resume this upload.' });
        selectedResumeToken = null;
        return;
    }
    if (forcedToken) {
        const candidate = [...transfers.values()].find((task) => task.token === forcedToken);
        if (!candidate || candidate.name !== files[0]?.name || Number(candidate.size) !== files[0]?.size) {
            window.vaultAlert({ type: 'error', message: 'The selected file does not match the upload being resumed.' });
            selectedResumeToken = null;
            if (submit instanceof HTMLButtonElement) submit.disabled = true;
            return;
        }
    }

    const invalidFile = files.map((file) => validateFile(file, form)).find(Boolean);
    if (invalidFile) {
        window.vaultAlert({ type: 'error', message: invalidFile });
        selectedResumeToken = null;
        if (submit instanceof HTMLButtonElement) submit.disabled = true;
        return;
    }

    form.dataset.selectedFiles = 'ready';
};

if (transferWidget) {
    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-upload-input]');
        if (input instanceof HTMLInputElement) handleSelection(input);
    });
    document.addEventListener('cancel', (event) => {
        if (event.target.matches?.('[data-upload-input]')) {
            resumeToken = null;
            selectedResumeToken = null;
        }
    });

    document.addEventListener('dragover', (event) => {
        const dropzone = event.target.closest('[data-upload-dropzone]');
        if (!dropzone) return;
        event.preventDefault();
        dropzone.classList.add('is-dragging');
    });

    document.addEventListener('dragleave', (event) => {
        const dropzone = event.target.closest('[data-upload-dropzone]');
        if (dropzone && !dropzone.contains(event.relatedTarget)) dropzone.classList.remove('is-dragging');
    });

    document.addEventListener('drop', (event) => {
        const dropzone = event.target.closest('[data-upload-dropzone]');
        if (!dropzone) return;
        event.preventDefault();
        dropzone.classList.remove('is-dragging');
        const input = dropzone.querySelector('[data-upload-input]');
        if (input instanceof HTMLInputElement && event.dataTransfer) {
            input.files = event.dataTransfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!isUploadForm(form)) return;
        event.preventDefault();
        const input = form.querySelector('[data-upload-input]');
        const files = input instanceof HTMLInputElement ? [...(input.files ?? [])] : [];
        if (!files.length) {
            window.vaultAlert({ type: 'warning', message: 'Choose one or more files before uploading.' });
            return;
        }
        if (form.dataset.selectedFiles !== 'ready') {
            window.vaultAlert({ type: 'error', message: 'Review the selected files before uploading.' });
            return;
        }

        openUploadTracker();
        const forcedToken = selectedResumeToken;
        selectedResumeToken = null;
        if (input instanceof HTMLInputElement) input.value = '';
        const submit = form.querySelector('[data-upload-submit]');
        if (submit instanceof HTMLButtonElement) {
            submit.disabled = true;
            submit.textContent = 'STARTING TRANSFERS…';
        }
        refreshSnapshot()
            .then(() => Promise.all(files.map((file, index) => startFile(file, form, index === 0 ? forcedToken : null))))
            .then(() => refreshSnapshot())
            .catch((error) => {
                window.vaultAlert({
                    type: 'error',
                    message: error instanceof Error ? error.message : 'Unable to load current uploads.',
                });
            })
            .finally(() => {
                form.dataset.selectedFiles = '';
                updateSelectionLabel(form, 0);
                if (submit instanceof HTMLButtonElement) {
                    submit.disabled = true;
                    submit.textContent = 'UPLOAD SELECTED FILES';
                }
            });
    });

    window.addEventListener('vault:upload-transfer-status', (message) => {
        const event = message.detail;
        setTotals(event);
        const task = transfers.get(Number(event.upload_id));
        if (event.status === 'removed') {
            transfers.delete(Number(event.upload_id));
            removeUploadTrackerItem(transferKey(event.upload_id));
            return;
        }
        if (!task) {
            refreshSnapshot().catch((error) => {
                console.error('Unable to refresh upload tracker after a status event.', error);
            });
            return;
        }
        updateTask(task, {
            status: event.status,
            progress: ['pending', 'processing', 'done'].includes(event.status) ? 100 : task.progress,
            error: event.status === 'failed' ? task.error : null,
        });
        if (event.status === 'failed') {
            fetchStatus(task).catch((error) => {
                console.error('Unable to load upload failure details.', error);
            });
        }
        if (event.status === 'done' || event.status === 'failed' || event.status === 'cancelled') {
            forgetResumeToken(task);
        }
    });

    window.addEventListener('vault:realtime-connected', () => {
        refreshSnapshot().catch((error) => {
            console.error('Unable to refresh uploads after realtime reconnection.', error);
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (![...transfers.values()].some((task) => ['uploading', 'pending'].includes(task.status) && task.file)) return;
        event.preventDefault();
        event.returnValue = '';
    });

    refreshSnapshot().catch((error) => {
        console.error('Unable to load upload tracker state.', error);
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : 'Unable to load current uploads.',
        });
    });
}
