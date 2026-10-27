import {
    openUploadTracker,
    removeUploadTrackerItem,
    setUploadTrackerTotals,
    updateUploadTrackerItem,
} from './upload-tracker';

const transferWidget = document.querySelector('[data-upload-tracker]');
const resumeStorageKey = 'vault.upload-transfer-resume.v1';
const transfers = new Map();
const recordPreparations = new WeakMap();
const deferredRecordSelections = new WeakMap();
const imageExtensions = new Set(['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tif', 'tiff', 'heic', 'heif', 'avif']);
const videoExtensions = new Set(['mp4', 'mov', 'avi', 'mkv', 'webm', 'wmv', 'flv', 'm4v', '3gp', 'mpeg', 'mpg']);
let resumeToken = null;
let selectedResumeToken = null;
let snapshotRequest = null;

const isUploadForm = (form) => form instanceof HTMLFormElement && form.matches('[data-manual-upload]');
const transferKey = (id) => `transfer:${id}`;
const resumeKey = (file) => JSON.stringify([file.name, file.size, file.lastModified]);
const formatBytes = (bytes) => bytes < 1024 * 1024
    ? `${Math.max(1, Math.round(bytes / 1024))} KB`
    : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const recordForm = (element) => element.closest('[data-record-upload-form]');

const ensureRecordDraftToken = (form) => {
    if (form.dataset.recordDraftToken) return form.dataset.recordDraftToken;

    const storageKey = form.dataset.recordDraftStorageKey;
    if (!storageKey) throw new Error('This record form is missing its upload draft key.');

    let token;
    try {
        token = sessionStorage.getItem(storageKey);
        if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(token ?? '')) {
            token = crypto.randomUUID();
            sessionStorage.setItem(storageKey, token);
        }
    } catch (error) {
        console.error('Unable to persist the record upload draft token.', error);
        throw new Error('Browser storage is unavailable, so record uploads cannot safely resume after navigation.');
    }

    form.dataset.recordDraftToken = token;
    let hidden = form.querySelector('[name="record_upload_draft_token"]');
    if (!(hidden instanceof HTMLInputElement)) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'record_upload_draft_token';
        form.append(hidden);
    }
    hidden.value = token;
    return token;
};

const recordTokenFieldName = (purpose, attachment) => {
    if (purpose === 'person-main-image') return 'main_image_upload_token';
    if (purpose === 'person-gallery') return 'gallery_upload_tokens[]';
    return `categories[${attachment.categoryIndex}][upload_transfer_tokens][]`;
};

const findCaseCategory = (form, attachment) => [...form.querySelectorAll('[data-case-category]')].find((category) => (
    (attachment.targetId && Number(category.dataset.caseCategoryId) === Number(attachment.targetId))
    || (attachment.targetKey && category.dataset.uploadTargetKey === attachment.targetKey)
));

const addCaseCategoryTargetKey = (form, category) => {
    const categoryId = Number(category.dataset.caseCategoryId);
    if (categoryId > 0) return null;

    let targetKey = category.dataset.uploadTargetKey;
    if (!targetKey) {
        targetKey = crypto.randomUUID();
        category.dataset.uploadTargetKey = targetKey;
    }

    const index = category.dataset.caseIndex;
    if (index === undefined) throw new Error('The evidence category is missing its form index.');
    let hidden = category.querySelector('[data-case-upload-target-key]');
    if (!(hidden instanceof HTMLInputElement)) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.dataset.caseUploadTargetKey = '';
        category.append(hidden);
    }
    hidden.name = `categories[${index}][upload_target_key]`;
    hidden.value = targetKey;
    return targetKey;
};

const addRecordTokenToForm = (form, task) => {
    const attachment = task.attachment;
    if (!attachment) return;
    if (attachment.recordId) {
        if (Number(form.dataset.recordId) !== Number(attachment.recordId)) return;
    } else if (attachment.draftToken && form.dataset.recordDraftToken !== attachment.draftToken) {
        return;
    }

    if (attachment.purpose === 'person-main-image') {
        form.querySelectorAll('[name="main_image_upload_token"]').forEach((input) => input.remove());
    }
    const category = attachment.purpose === 'case-evidence'
        ? findCaseCategory(form, attachment)
        : form;
    if (!category) return;
    const index = category.dataset.caseIndex;
    if (attachment.purpose === 'case-evidence' && index === undefined) return;
    const fieldName = recordTokenFieldName(attachment.purpose, { ...attachment, categoryIndex: index });
    const tokenContainer = attachment.purpose === 'case-evidence'
        ? category.querySelector('[data-case-upload-transfer-tokens]')
        : form;
    if (!tokenContainer) return;
    const exists = [...tokenContainer.querySelectorAll(`[name="${fieldName}"]`)]
        .some((input) => input.value === task.token);
    if (exists) return;

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = fieldName;
    hidden.value = task.token;
    hidden.dataset.recordUploadToken = '';
    tokenContainer.append(hidden);
};

const removeRecordTokenFromForm = (task) => {
    if (!task.attachment) return;
    const form = [...document.querySelectorAll('[data-record-upload-form]')].find((candidate) => (
        (task.attachment.recordId && Number(candidate.dataset.recordId) === Number(task.attachment.recordId))
        || (!task.attachment.recordId && candidate.dataset.recordDraftToken === task.attachment.draftToken)
    ));
    if (!form) return;
    form.querySelectorAll('[data-record-upload-token]').forEach((input) => {
        if (input.value === task.token) input.remove();
    });
};

const attachSnapshotTransferToForm = (task) => {
    if (!task.attachment_purpose || task.attached_at) return;
    const form = [...document.querySelectorAll('[data-record-upload-form]')].find((candidate) => (
        (task.draft_token && candidate.dataset.recordDraftToken === task.draft_token)
        || (task.attachment_record_id && Number(candidate.dataset.recordId) === Number(task.attachment_record_id))
    ));
    if (!form) return;
    task.attachment = {
        purpose: task.attachment_purpose,
        draftToken: task.draft_token,
        recordId: task.attachment_record_id,
        targetId: task.attachment_target_id,
        targetKey: task.attachment_target_key,
    };
    if (task.attachment.purpose === 'case-evidence') {
        const category = findCaseCategory(form, task.attachment);
        if (!category) return;
        task.attachment.categoryIndex = category.dataset.caseIndex;
    }
    addRecordTokenToForm(form, task);
};

const initializeRecordForm = (form) => {
    try {
        if (form.dataset.recordDraftStorageKey) ensureRecordDraftToken(form);
    } catch (error) {
        console.error('Unable to initialize the record upload form.', error);
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : 'Record uploads could not be initialized.',
        });
    }
};

const isAllowedByAccept = (file, input) => {
    if (!(input instanceof HTMLInputElement)) return false;
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
    const acceptedExtensions = input.accept
        .split(',')
        .map((item) => item.trim().toLowerCase())
        .filter((item) => item.startsWith('.'))
        .map((item) => item.slice(1));
    return acceptedExtensions.includes(extension);
};

const createRecordFilePreview = (file, onRemove, purpose) => {
    const wrapper = document.createElement('div');
    const galleryPreview = purpose === 'person-gallery';
    wrapper.className = galleryPreview
        ? 'new-upload-image case-evidence-thumb--staged'
        : 'case-evidence-thumb case-evidence-thumb--staged';
    wrapper.dataset.stagedRecordFile = '';

    const rawExtension = file.name.split('.').pop()?.toLowerCase() ?? '';
    const extension = rawExtension.toUpperCase() || 'FILE';
    const objectUrl = URL.createObjectURL(file);
    wrapper.dataset.previewUrl = objectUrl;
    if (file.type.startsWith('image/') || imageExtensions.has(rawExtension)) {
        const preview = document.createElement('img');
        preview.src = objectUrl;
        preview.alt = file.name;
        wrapper.append(preview);
    } else if (file.type.startsWith('video/') || videoExtensions.has(rawExtension)) {
        const preview = document.createElement('video');
        preview.src = objectUrl;
        preview.muted = true;
        preview.playsInline = true;
        preview.preload = 'metadata';
        preview.setAttribute('aria-label', `Video preview for ${file.name}`);
        preview.addEventListener('loadeddata', () => {
            if (preview.duration > 0) preview.currentTime = Math.min(0.1, preview.duration / 2);
        }, { once: true });
        wrapper.append(preview);
    } else {
        const badge = document.createElement('span');
        badge.className = 'case-evidence-document';
        badge.textContent = `[${extension}]`;
        wrapper.append(badge);
    }

    if (galleryPreview) {
        const name = document.createElement('span');
        name.textContent = file.name;
        wrapper.append(name);
    }

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.setAttribute('aria-label', `Remove ${file.name}`);
    remove.addEventListener('click', () => {
        if (onRemove() === false) return;
        URL.revokeObjectURL(objectUrl);
        wrapper.remove();
    });
    wrapper.append(remove);
    wrapper.title = `${file.name} (${formatBytes(file.size)})`;
    return wrapper;
};

const createMainImagePreview = (input, file) => {
    const uploader = input.closest('[data-main-image-uploader]');
    if (!uploader) throw new Error('The main image preview area is unavailable.');

    const currentPreview = uploader.querySelector('[data-main-image-preview]');
    if (currentPreview?.dataset.previewUrl) URL.revokeObjectURL(currentPreview.dataset.previewUrl);
    const previewUrl = URL.createObjectURL(file);
    const image = document.createElement('img');
    image.src = previewUrl;
    image.alt = 'Selected main image';
    image.dataset.mainImagePreview = '';
    image.dataset.previewUrl = previewUrl;

    const placeholder = uploader.querySelector('[data-main-image-placeholder]');
    if (placeholder) placeholder.replaceWith(image);
    else currentPreview?.replaceWith(image);

    return image;
};

const discardPreparedTransfer = async (task) => {
    task.cancelRequested = true;
    task.xhr?.abort();
    const response = await fetch(task.cancel_url, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
    });
    await parseResponse(response);
    removeRecordTokenFromForm(task);
    forgetResumeToken(task);
    updateTask(task, { status: 'cancelled', progress: 0 });
    await refreshSnapshot();
};

const stageRecordUploads = (input, form, files, attachment) => {
    const category = input.closest('[data-case-category]');
    const isMainImage = attachment.purpose === 'person-main-image';
    const scope = category
        ?? (isMainImage
            ? input.closest('[data-main-image-uploader]')
            : input.closest('[data-person-gallery-upload-list]'));
    const previewList = category?.querySelector('[data-case-image-list]')
        ?? (!isMainImage ? input.closest('[data-person-gallery-upload-list]') : null);
    if (!scope || (!isMainImage && !previewList)) {
        throw new Error('The selected file preview area is unavailable.');
    }

    const selections = deferredRecordSelections.get(form) ?? [];
    deferredRecordSelections.set(form, selections);
    const renderTypeErrors = () => {
        scope.querySelectorAll('[data-staged-upload-error]').forEach((error) => error.remove());
        const invalidFiles = selections.filter((selection) => (
            selection.scope === scope
            && !isAllowedByAccept(selection.file, selection.input)
        ));
        if (invalidFiles.length === 0) return;

        const error = document.createElement('p');
        error.className = 'field-error record-upload-selection-error';
        error.dataset.stagedUploadError = '';
        error.setAttribute('role', 'alert');
        error.textContent = `${invalidFiles.map(({ file }) => file.name).join(', ')}: select only supported file types for this field.`;
        scope.append(error);
    };

    if (isMainImage) {
        for (let index = selections.length - 1; index >= 0; index -= 1) {
            if (selections[index].attachment.purpose === 'person-main-image') selections.splice(index, 1);
        }
    }

    files.forEach((file) => {
        const selection = { file, input, attachment, scope, task: null };
        const preview = isMainImage
            ? (isAllowedByAccept(file, input) ? createMainImagePreview(input, file) : null)
            : createRecordFilePreview(file, () => {
                if (form.dataset.submitting === 'true') return false;
                const index = selections.indexOf(selection);
                if (index >= 0) selections.splice(index, 1);
                renderTypeErrors();
                if (selection.task) {
                    discardPreparedTransfer(selection.task).catch((failure) => {
                        console.error('Unable to discard the staged record upload.', failure);
                        window.vaultAlert({
                            type: 'error',
                            message: failure instanceof Error ? failure.message : `Unable to remove ${file.name}.`,
                        });
                    });
                }
                return true;
            }, attachment.purpose);
        selection.preview = preview;
        selections.push(selection);

        if (!isAllowedByAccept(file, input)) {
            preview?.classList.add('is-invalid');
        }
        if (previewList && preview instanceof HTMLElement) previewList.append(preview);
    });
    renderTypeErrors();
};

const prepareDeferredRecordUploads = async (form) => {
    const selections = deferredRecordSelections.get(form) ?? [];
    const errorsByScope = new Map();
    for (const selection of selections) {
        const input = selection.input;
        const message = !isAllowedByAccept(selection.file, input)
            ? `${selection.file.name} is not an allowed file type for this record.`
            : validateFile(selection.file, form);
        if (message) {
            errorsByScope.set(selection.scope, [
                ...(errorsByScope.get(selection.scope) ?? []),
                message,
            ]);
            selection.preview?.classList.add('is-invalid');
        } else {
            selection.preview?.classList.remove('is-invalid');
        }
    }

    form.querySelectorAll('[data-staged-upload-error]').forEach((error) => error.remove());
    errorsByScope.forEach((messages, scope) => {
        const errorElement = document.createElement('p');
        errorElement.className = 'field-error record-upload-selection-error';
        errorElement.dataset.stagedUploadError = '';
        errorElement.setAttribute('role', 'alert');
        errorElement.textContent = messages.join(' ');
        scope.append(errorElement);
    });

    if (errorsByScope.size > 0) {
        throw new Error('Review the highlighted files before saving the record.');
    }
};

const startDeferredRecordUpload = async (form, selection, payload) => {
    const recordId = Number(selection.attachment.recordId || payload.record_id);
    const isCaseEvidence = selection.attachment.purpose === 'case-evidence';
    const targetId = isCaseEvidence
        ? Number(selection.attachment.targetId || payload.upload_targets?.[selection.attachment.targetKey])
        : null;
    if (!Number.isInteger(recordId) || recordId < 1
        || (isCaseEvidence && (!Number.isInteger(targetId) || targetId < 1))) {
        throw new Error(`The record was saved, but ${selection.file.name} could not be linked to its attachment.`);
    }

    const attachment = {
        ...selection.attachment,
        draftToken: null,
        recordId,
        targetId,
        targetKey: null,
        commitImmediately: true,
    };
    const task = await prepareTransfer(selection.file, form, null, attachment);
    selection.task = task;
    if (selection.errorTrackerId) {
        removeUploadTrackerItem(selection.errorTrackerId);
        selection.errorTrackerId = null;
    }
    void runTransfer(task, selection.file);
};

const startDeferredRecordUploads = (form, payload) => {
    const selections = deferredRecordSelections.get(form) ?? [];
    selections.forEach((selection) => {
        void startDeferredRecordUpload(form, selection, payload).catch((error) => {
            const message = error instanceof Error ? error.message : `Unable to start ${selection.file.name}.`;
            const id = selection.errorTrackerId ?? `deferred-record-upload:${crypto.randomUUID()}`;
            selection.errorTrackerId = id;
            const actions = {
                retry: async () => {
                    removeUploadTrackerItem(id);
                    selection.errorTrackerId = null;
                    await startDeferredRecordUpload(form, selection, payload);
                },
                remove: () => {
                    removeUploadTrackerItem(id);
                    const current = deferredRecordSelections.get(form) ?? [];
                    const index = current.indexOf(selection);
                    if (index >= 0) current.splice(index, 1);
                    URL.revokeObjectURL(selection.preview?.dataset.previewUrl ?? '');
                    selection.preview?.remove();
                },
            };
            updateUploadTrackerItem({
                id,
                kind: 'server-transfer',
                name: selection.file.name,
                size: selection.file.size,
                status: 'failed',
                progress: 0,
                error: message,
                actions,
            });
        });
    });
};

window.addEventListener('vault:case-category-removed', ({ detail }) => {
    const category = detail?.category;
    const form = category?.closest('[data-record-upload-form]');
    if (!(form instanceof HTMLFormElement)) return;
    const selections = deferredRecordSelections.get(form) ?? [];
    for (let index = selections.length - 1; index >= 0; index -= 1) {
        const selection = selections[index];
        if (selection.attachment.categoryIndex !== category.dataset.caseIndex) continue;
        selections.splice(index, 1);
        URL.revokeObjectURL(selection.preview?.dataset.previewUrl ?? '');
        if (selection.task) {
            discardPreparedTransfer(selection.task).catch((error) => {
                console.error('Unable to discard evidence uploads for a removed category.', error);
                window.vaultAlert({
                    type: 'error',
                    message: error instanceof Error ? error.message : 'Unable to cancel uploads in the removed category.',
                });
            });
        }
    }
});

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

const refreshVisibleCaseDossier = (task) => {
    const attachment = task.attachment;
    if (task.status !== 'done' || attachment?.purpose !== 'case-evidence') return;

    const dossier = document.querySelector('[data-case-dossier-id]');
    const recordId = Number(attachment.recordId);
    if (!dossier || !Number.isInteger(recordId) || Number(dossier.dataset.caseDossierId) !== recordId) return;

    window.Livewire?.dispatch('refresh-case-dossier', { caseId: recordId });
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
                attachment: upload.attachment_purpose
                    ? {
                        purpose: upload.attachment_purpose,
                        draftToken: upload.draft_token,
                        recordId: upload.attachment_record_id,
                        targetId: upload.attachment_target_id,
                        targetKey: upload.attachment_target_key,
                    }
                    : existing?.attachment ?? null,
            });
            transfers.set(id, task);
            attachSnapshotTransferToForm(task);
            publishTransfer(task);
            refreshVisibleCaseDossier(task);
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

const initializeTransfer = async (file, attachment = null) => {
    const response = await fetch(transferWidget.dataset.transferInitializeUrl, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            name: file.name,
            size: file.size,
            ...(attachment ? {
                attachment: {
                    purpose: attachment.purpose,
                    draft_token: attachment.draftToken,
                    record_id: attachment.recordId,
                    target_id: attachment.targetId,
                    target_key: attachment.targetKey,
                    commit_immediately: attachment.commitImmediately,
                },
            } : {}),
        }),
    });
    const initialized = await parseResponse(response);
    const task = {
        ...initialized,
        name: file.name,
        size: file.size,
        file,
        progress: 0,
        receivedChunks: new Set(initialized.received_chunks ?? []),
        attachment,
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

const getResumeCandidate = (file, forcedToken = null, attachment = null) => {
    const candidates = [...transfers.values()].filter((task) => (
        task.name === file.name
        && Number(task.size) === file.size
        && (attachment
            ? ['uploading', 'pending', 'processing', 'done', 'failed', 'cancelled'].includes(task.status)
            : ['uploading', 'failed', 'cancelled'].includes(task.status))
        && (forcedToken || (attachment
            ? task.attachment?.purpose === attachment.purpose
                && task.attachment?.draftToken === attachment.draftToken
                && task.attachment?.recordId === attachment.recordId
                && task.attachment?.targetId === attachment.targetId
                && task.attachment?.targetKey === attachment.targetKey
            : !task.attachment))
    ));
    if (forcedToken) return candidates.find((task) => task.token === forcedToken) ?? null;

    const storedToken = readResumeTokens()[resumeKey(file)];
    if (storedToken) {
        const remembered = candidates.find((task) => task.token === storedToken);
        if (remembered) return remembered;
    }
    return candidates.length === 1 ? candidates[0] : null;
};

const prepareTransfer = async (file, form, forcedToken = null, attachment = null) => {
    const validationError = validateFile(file, form);
    if (validationError) throw new Error(validationError);

    let task = getResumeCandidate(file, forcedToken, attachment);
    if (task?.file && ['uploading', 'pending', 'processing'].includes(task.status)) {
        if (task.attachment && attachment
            && task.attachment.purpose === attachment.purpose
            && task.attachment.draftToken === attachment.draftToken) return task;
        throw new Error(`${file.name} is already being transferred.`);
    }
    if (task?.attachment && attachment
        && (task.attachment.purpose !== attachment.purpose
            || task.attachment.draftToken !== attachment.draftToken
            || task.attachment.recordId !== attachment.recordId
            || task.attachment.targetId !== attachment.targetId
            || task.attachment.targetKey !== attachment.targetKey)) {
        throw new Error('The selected file does not match the upload being resumed.');
    }
    if (!task) task = await initializeTransfer(file, attachment);
    task.file = file;
    task.attachment = attachment ?? task.attachment ?? null;
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

    return task;
};

const runTransfer = async (task, file) => {
    if (task.sending) return;
    task.sending = true;
    try {
        if (task.status === 'uploading') await sendChunks(task);
        else await fetchStatus(task);
    } catch (error) {
        if (!task.cancelRequested) {
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
        }
    } finally {
        task.sending = false;
    }
};

const startFile = async (file, form, forcedToken = null) => {
    try {
        const task = await prepareTransfer(file, form, forcedToken);
        if (task) await runTransfer(task, file);
    } catch (error) {
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : `Unable to upload ${file.name}.`,
        });
    }
};

const beginRecordUploads = (input) => {
    const form = recordForm(input);
    if (!(form instanceof HTMLFormElement)) return;

    const isMainImage = input.matches('[data-record-main-image-input]');
    const caseCategory = input.closest('[data-case-category]');
    const purpose = isMainImage
        ? 'person-main-image'
        : caseCategory
            ? 'case-evidence'
            : 'person-gallery';
    const selectedFiles = [...(input.files ?? [])];
    if (selectedFiles.length === 0) return;

    const forcedToken = resumeToken;
    resumeToken = null;
    if (forcedToken && selectedFiles.length !== 1) {
        window.vaultAlert({ type: 'warning', message: 'Select exactly one file to resume this upload.' });
        input.value = '';
        return;
    }

    let draftToken = null;
    let targetId = null;
    let targetKey = null;
    let categoryIndex = null;
    try {
        if (form.dataset.recordDraftStorageKey) draftToken = ensureRecordDraftToken(form);
        if (purpose === 'case-evidence') {
            if (!(caseCategory instanceof HTMLElement)) throw new Error('The upload is not inside an evidence category.');
            categoryIndex = caseCategory.dataset.caseIndex ?? null;
            if (categoryIndex === null) throw new Error('The evidence category is missing its form index.');
            targetId = Number(caseCategory.dataset.caseCategoryId) || null;
            targetKey = addCaseCategoryTargetKey(form, caseCategory);
            if (!form.dataset.recordId && !draftToken) throw new Error('The new case upload draft is unavailable.');
        } else if (!form.dataset.recordId && !draftToken) {
            throw new Error('The record upload draft is unavailable.');
        }
    } catch (error) {
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : 'The record upload could not be initialized.',
        });
        input.value = '';
        return;
    }

    const attachment = {
        purpose,
        draftToken,
        recordId: Number(form.dataset.recordId) || null,
        targetId,
        targetKey,
        categoryIndex,
    };
    if (form.dataset.deferRecordUploads === 'true') {
        try {
            stageRecordUploads(input, form, selectedFiles, attachment);
        } catch (error) {
            window.vaultAlert({
                type: 'error',
                message: error instanceof Error ? error.message : 'Unable to stage the selected files.',
            });
        }
        queueMicrotask(() => {
            input.value = '';
        });
        return;
    }

    const preparations = recordPreparations.get(form) ?? new Set();
    recordPreparations.set(form, preparations);
    const errorTarget = input.closest('[data-person-gallery-upload-list]')
        ?? input.closest('[data-main-image-uploader]')
        ?? input.closest('[data-case-category]');
    errorTarget
        ?.querySelectorAll('.record-upload-selection-error')
        .forEach((error) => error.remove());

    const showPreparationFailure = (file, message, canRetry) => {
        const id = `record-preparation:${crypto.randomUUID()}`;
        const actions = {
            remove: () => removeUploadTrackerItem(id),
        };
        if (canRetry) {
            actions.retry = async () => {
                removeUploadTrackerItem(id);
                await startRecordTransfer(file, form, attachment);
            };
        }
        updateUploadTrackerItem({
            id,
            kind: 'server-transfer',
            name: file.name,
            size: file.size,
            status: 'failed',
            progress: 0,
            error: message,
            actions,
        });
        const error = document.createElement('p');
        error.className = 'field-error record-upload-selection-error';
        error.setAttribute('role', 'alert');
        error.textContent = message;
        errorTarget?.append(error);
    };

    const startRecordTransfer = async (file, targetForm, targetAttachment, forced = null) => {
        try {
            const task = await prepareTransfer(file, targetForm, forced, targetAttachment);
            if (!task) return;
            addRecordTokenToForm(targetForm, task);
            void runTransfer(task, file);
        } catch (error) {
            showPreparationFailure(
                file,
                error instanceof Error ? error.message : `Unable to start ${file.name}.`,
                !forced,
            );
        }
    };

    const files = selectedFiles;
    if (files.length === 0) {
        input.value = '';
        return;
    }

    files.forEach((file) => {
        const preparation = startRecordTransfer(file, form, attachment, forcedToken ? forcedToken : null);

        preparations.add(preparation);
        preparation.then(
            () => preparations.delete(preparation),
            () => preparations.delete(preparation),
        );
        void preparation.catch(() => {});
    });

    queueMicrotask(() => {
        input.value = '';
    });
};

window.vaultRecordUploadsReady = async (form) => {
    if (form.dataset.deferRecordUploads === 'true') {
        await prepareDeferredRecordUploads(form);
        return;
    }
    let preparations = recordPreparations.get(form);
    while (preparations?.size) await Promise.all([...preparations]);
};

window.vaultRecordUploadsSubmitted = (form, payload) => {
    if (form.dataset.deferRecordUploads === 'true') startDeferredRecordUploads(form, payload);
};

const selectFileForResume = (task) => {
    openUploadTracker();
    const recordInput = task.attachment_purpose === 'person-main-image'
        ? document.querySelector('[data-record-main-image-input], [data-main-image-input]')
        : task.attachment_purpose === 'person-gallery'
            ? document.querySelector('[data-person-document-input]')
            : task.attachment_purpose === 'case-evidence'
                ? [...document.querySelectorAll('[data-case-document-input]')].find((input) => {
                    const category = input.closest('[data-case-category]');
                    return category && (
                        (task.attachment_target_id && Number(category.dataset.caseCategoryId) === Number(task.attachment_target_id))
                        || (task.attachment_target_key && category.dataset.uploadTargetKey === task.attachment_target_key)
                    );
                })
                : null;
    const recordFormElement = recordInput?.closest('[data-record-upload-form]');
    if (recordInput instanceof HTMLInputElement
        && recordFormElement
        && (!task.draft_token || recordFormElement.dataset.recordDraftToken === task.draft_token)
        && (!task.attachment_record_id || Number(recordFormElement.dataset.recordId) === Number(task.attachment_record_id))) {
        resumeToken = task.token;
        recordInput.click();
        return;
    }

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
    removeRecordTokenFromForm(task);
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
    if (task.attachment) {
        const form = document.querySelector('[data-record-upload-form]');
        if (form?.dataset.recordDraftToken === task.attachment.draftToken) addRecordTokenToForm(form, task);
    }
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
    removeRecordTokenFromForm(task);
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
    document.addEventListener('change', (event) => {
        const input = event.target.closest('[data-record-main-image-input], [data-person-document-input], [data-case-document-input]');
        if (input instanceof HTMLInputElement) beginRecordUploads(input);
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
        refreshVisibleCaseDossier(task);
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
    window.addEventListener('livewire:navigated', () => {
        document.querySelectorAll('[data-record-upload-form]').forEach(initializeRecordForm);
        refreshSnapshot().catch((error) => {
            console.error('Unable to refresh uploads after SPA navigation.', error);
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (![...transfers.values()].some((task) => ['uploading', 'pending'].includes(task.status) && task.file)) return;
        event.preventDefault();
        event.returnValue = '';
    });

    document.querySelectorAll('[data-record-upload-form]').forEach(initializeRecordForm);
    refreshSnapshot().catch((error) => {
        console.error('Unable to load upload tracker state.', error);
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : 'Unable to load current uploads.',
        });
    });
}
