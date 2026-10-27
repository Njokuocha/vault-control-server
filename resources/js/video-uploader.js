import { openUploadTracker, removeUploadTrackerItem, updateUploadTrackerItem } from './upload-tracker';

const widget = document.querySelector('[data-video-upload-widget]');
if (widget) {
    const tasks = new Map();

    const activeStatuses = new Set(['queued', 'uploading', 'processing', 'finalizing']);

    const attachToRecord = (task) => {
        if (!task.associationElement || !task.token || task.attachedToken === task.token) return;
        if (!task.associationElement.isConnected) {
            window.vaultAlert({ type: 'warning', message: `${task.file.name} was uploaded to the archive, but its selected record category was removed.` });
            return;
        }

        const isCaseCategory = task.associationElement.matches('[data-case-category]');
        const target = task.associationElement.querySelector(
            isCaseCategory ? '[data-case-video-upload-tokens]' : '[data-video-upload-tokens]',
        );
        if (!target) return;

        const input = document.createElement('input');
        input.type = 'hidden';
        input.value = task.token;
        input.name = isCaseCategory
            ? `categories[${task.associationElement.dataset.caseIndex}][video_upload_tokens][]`
            : 'video_upload_tokens[]';
        input.dataset.videoUploadToken = '';
        const attachment = document.createElement('span');
        attachment.className = 'video-upload-attachment';
        const filename = document.createElement('span');
        filename.textContent = task.file.name;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.setAttribute('aria-label', `Remove ${task.file.name} from this record`);
        remove.addEventListener('click', () => attachment.remove());
        attachment.append(input, filename, remove);
        target.append(attachment);
        task.attachedToken = task.token;
    };

    const setStatus = (task, status, message = null) => {
        const changed = task.status !== status;
        task.status = status;
        task.error = message;
        if (!changed) return;
        if (status === 'completed') {
            attachToRecord(task);
            window.vaultAlert({ type: 'success', message: `${task.file.name} is now in the private archive.` });
        } else if (status === 'uploading') {
            window.vaultAlert({ type: 'info', message: `Video upload started for ${task.file.name}.` });
        } else if (status === 'failed') {
            window.vaultAlert({ type: 'error', message: message ?? `${task.file.name} could not be uploaded.` });
        } else if (status === 'cancelled') {
            window.vaultAlert({ type: 'warning', message: `${task.file.name} upload cancelled.` });
        }
    };

    const render = () => {
        for (const task of tasks.values()) {
            const status = task.status === 'completed' ? 'done' : task.status;
            const actions = {};
            if (activeStatuses.has(task.status) && task.status !== 'finalizing' && task.cancelUrl) {
                actions.cancel = () => cancelTask(task);
            }
            if (task.status === 'failed' && task.retryUrl) actions.retry = () => retryTask(task);
            if (['completed', 'cancelled', 'failed'].includes(task.status)) {
                actions.remove = () => {
                    tasks.delete(task.id);
                    removeUploadTrackerItem(`video:${task.id}`);
                };
            }
            updateUploadTrackerItem({
                id: `video:${task.id}`,
                kind: 'legacy-video',
                name: task.file.name,
                size: task.file.size,
                status,
                progress: task.progress,
                error: task.error,
                actions,
            });
        }
    };

    const parseResponse = async (response) => {
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error('The upload server returned an unreadable response.');
        }
        if (!response.ok) throw new Error(result.message ?? 'The video upload request failed.');
        return result;
    };

    const xhrChunk = (task, index, blob) => new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        task.xhr = xhr;
        xhr.open('POST', task.chunkUrl.replace('__INDEX__', String(index)));
        xhr.setRequestHeader('X-CSRF-TOKEN', widget.dataset.csrfToken);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) return;
            task.progress = Math.min(99, ((index * task.chunkSize + event.loaded) / task.file.size) * 100);
            render();
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
                reject(new Error(result.message ?? 'The video chunk was rejected.'));
                return;
            }
            resolve(result);
        });
        xhr.addEventListener('error', () => {
            task.xhr = null;
            reject(new Error('A network error interrupted the video upload.'));
        });
        xhr.addEventListener('abort', () => {
            task.xhr = null;
            reject(new DOMException('Upload cancelled.', 'AbortError'));
        });
        const body = new FormData();
        body.append('chunk', blob, `${task.file.name}.part`);
        xhr.send(body);
    });

    const pollStatus = async (task) => {
        while (activeStatuses.has(task.status)) {
            await new Promise((resolve) => window.setTimeout(resolve, 1000));
            if (task.cancelRequested) return;
            const response = await fetch(task.statusUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const result = await parseResponse(response);
            task.progress = result.progress;
            setStatus(task, result.status, result.error);
            render();
        }
    };

    const sendChunks = async (task) => {
        setStatus(task, 'uploading');
        task.progress = 0;
        render();
        const chunkCount = Math.ceil(task.file.size / task.chunkSize);
        for (let index = 0; index < chunkCount; index++) {
            if (task.cancelRequested) return;
            const start = index * task.chunkSize;
            const result = await xhrChunk(task, index, task.file.slice(start, start + task.chunkSize));
            task.progress = Math.min(99, ((index + 1) * task.chunkSize / task.file.size) * 100);
            render();
            if (result.status === 'processing') break;
        }
        if (!task.cancelRequested) {
            setStatus(task, 'processing');
            task.progress = 100;
            render();
            await pollStatus(task);
        }
    };

    const startTask = async (file, input) => {
        const task = {
            id: crypto.randomUUID(),
            file,
            status: 'queued',
            progress: 0,
            xhr: null,
            cancelRequested: false,
            associationElement: input.closest('[data-case-category], [data-person-video-upload]'),
        };
        tasks.set(task.id, task);
        render();
        openUploadTracker();
        try {
            const response = await fetch(widget.dataset.initializeUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': widget.dataset.csrfToken,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ name: file.name, size: file.size }),
            });
            const initialized = await parseResponse(response);
            Object.assign(task, {
                token: initialized.token,
                chunkSize: initialized.chunk_size,
                chunkCount: initialized.chunk_count,
                statusUrl: initialized.status_url,
                chunkUrl: initialized.chunk_url,
                cancelUrl: initialized.cancel_url,
                retryUrl: initialized.retry_url,
            });
            await sendChunks(task);
        } catch (error) {
            if (error instanceof DOMException && error.name === 'AbortError' && task.cancelRequested) return;
            setStatus(task, 'failed', error instanceof Error ? error.message : 'The video upload failed.');
            render();
        }
    };

    const cancelTask = async (task) => {
        if (!task.cancelUrl || !activeStatuses.has(task.status)) return;
        const confirmed = await window.vaultConfirm({
            title: 'Cancel video upload',
            message: `Cancel ${task.file.name} and discard its partial upload?`,
            confirmLabel: 'CANCEL UPLOAD',
            destructive: true,
        });
        if (!confirmed) return;
        task.cancelRequested = true;
        task.xhr?.abort();
        try {
            const response = await fetch(task.cancelUrl, {
                method: 'DELETE',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': widget.dataset.csrfToken },
                credentials: 'same-origin',
            });
            await parseResponse(response);
            setStatus(task, 'cancelled');
        } catch (error) {
            task.cancelRequested = false;
            if (error instanceof Error && task.statusUrl) {
                try {
                    const statusResponse = await fetch(task.statusUrl, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    });
                    const statusResult = await parseResponse(statusResponse);
                    task.progress = statusResult.progress;
                    setStatus(task, statusResult.status, statusResult.error);
                } catch (statusError) {
                    setStatus(task, 'failed', statusError instanceof Error ? statusError.message : 'Unable to cancel this upload.');
                }
            } else {
                setStatus(task, 'failed', error instanceof Error ? error.message : 'Unable to cancel this upload.');
            }
        }
        render();
    };

    const retryTask = async (task) => {
        if (!task.retryUrl) return;
        try {
            const response = await fetch(task.retryUrl, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': widget.dataset.csrfToken },
                credentials: 'same-origin',
            });
            const result = await parseResponse(response);
            task.cancelRequested = false;
            task.chunkSize = result.chunk_size ?? task.chunkSize;
            setStatus(task, 'uploading');
            await sendChunks(task);
        } catch (error) {
            setStatus(task, 'failed', error instanceof Error ? error.message : 'Unable to retry this upload.');
        }
        render();
    };

    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-video-upload-input]');
        if (!input) return;
        const limits = input.closest('[data-video-background-upload], [data-video-limit-config]')?.dataset ?? {};
        const globalLimitMb = Number(limits.maxUploadMb ?? 0);
        const videoLimitMb = Number(limits.maxVideoUploadMb ?? 0);
        const effectiveLimitMb = videoLimitMb > 0 ? Math.min(globalLimitMb, videoLimitMb) : globalLimitMb;
        [...input.files].forEach((file) => {
            if (effectiveLimitMb > 0 && file.size > effectiveLimitMb * 1024 * 1024) {
                window.vaultAlert({ type: 'error', message: `${file.name} exceeds the ${effectiveLimitMb} MB upload limit.` });
                return;
            }
            startTask(file, input);
        });
        input.value = '';
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        const pendingRecordVideo = [...tasks.values()].some((task) => (
            activeStatuses.has(task.status)
            && task.associationElement?.closest('form') === form
        ));
        if (!pendingRecordVideo) return;
        event.preventDefault();
        window.vaultAlert({ type: 'warning', message: 'Wait for the selected videos to finish uploading before saving this record.' });
        openUploadTracker();
    });

    window.addEventListener('beforeunload', (event) => {
        if (![...tasks.values()].some((task) => activeStatuses.has(task.status))) return;
        event.preventDefault();
        event.returnValue = '';
    });

    window.addEventListener('vault:video-upload-status', (message) => {
        const event = message.detail;
        const task = [...tasks.values()].find((candidate) => candidate.token === event.token);
        if (!task) return;
        task.progress = event.progress;
        setStatus(task, event.status);
        render();
    });
}
