import { openImageViewer } from './image-viewer.js';
import { openVaultVideoViewer } from './video-viewer.js';

const previewObjectUrls = new WeakMap();
const activeObjectUrls = new Set();
const reportPreviewAllowedTags = new Set([
    'A', 'B', 'BLOCKQUOTE', 'BR', 'DIV', 'EM', 'H1', 'H2', 'H3', 'I', 'LI',
    'OL', 'P', 'S', 'STRONG', 'U', 'UL',
]);
const reportPreviewRemovedTags = new Set(['IFRAME', 'MATH', 'NOSCRIPT', 'OBJECT', 'SCRIPT', 'STYLE', 'SVG', 'TEMPLATE']);
const reportDraftDatabaseName = 'vault-report-drafts';
const reportDraftDatabaseVersion = 1;
const reportDraftAttachmentStore = 'attachments';
const reportDraftAttachmentIndex = 'draftKey';
const reportDraftMaximumFiles = 12;
const reportDraftMaximumFileSize = 50 * 1024 * 1024;
const reportDraftAllowedMimeTypes = new Set([
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif',
    'video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska',
]);
let galleryTrigger = null;
let reportPreviewTrigger = null;
let editorSelectionRange = null;
let subscribedReportId = null;
let lazyReportThumbnailObserver = null;
let reportDraftDatabasePromise = null;
let activeReportDraftKey = null;
const pendingReportAttachments = new Map();
const reportDraftRestoreInProgress = new WeakSet();
const preservedCommentDrafts = new WeakMap();

function reportDraftKey(root) {
    return root instanceof HTMLElement ? root.dataset.reportDraftKey ?? null : null;
}

function reportEditorComponent(root) {
    const componentRoot = root.closest('[wire\\:id]');
    const componentId = componentRoot?.getAttribute('wire:id');

    return componentId ? window.Livewire?.find(componentId) ?? null : null;
}

function openReportDraftDatabase() {
    if (!('indexedDB' in window)) {
        return Promise.reject(new Error('This browser does not support local attachment storage.'));
    }

    reportDraftDatabasePromise ??= new Promise((resolve, reject) => {
        const request = indexedDB.open(reportDraftDatabaseName, reportDraftDatabaseVersion);
        request.onupgradeneeded = () => {
            const database = request.result;
            if (!database.objectStoreNames.contains(reportDraftAttachmentStore)) {
                const store = database.createObjectStore(reportDraftAttachmentStore, { keyPath: 'id' });
                store.createIndex(reportDraftAttachmentIndex, 'draftKey', { unique: false });
            }
        };
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error('Unable to open local attachment storage.'));
    });

    return reportDraftDatabasePromise;
}

async function storeReportDraftAttachment(draftKey, attachment) {
    const database = await openReportDraftDatabase();

    await new Promise((resolve, reject) => {
        const transaction = database.transaction(reportDraftAttachmentStore, 'readwrite');
        transaction.objectStore(reportDraftAttachmentStore).put({
            id: `${draftKey}:${attachment.id}`,
            draftKey,
            attachmentId: attachment.id,
            name: attachment.file.name,
            type: attachment.file.type,
            size: attachment.file.size,
            lastModified: attachment.file.lastModified,
            blob: attachment.file,
        });
        transaction.oncomplete = resolve;
        transaction.onerror = () => reject(transaction.error ?? new Error('Unable to save a local attachment.'));
        transaction.onabort = () => reject(transaction.error ?? new Error('Local attachment storage was interrupted.'));
    });
}

async function readReportDraftAttachments(draftKey, attachmentIds) {
    const database = await openReportDraftDatabase();
    const records = await new Promise((resolve, reject) => {
        const transaction = database.transaction(reportDraftAttachmentStore, 'readonly');
        const request = transaction.objectStore(reportDraftAttachmentStore)
            .index(reportDraftAttachmentIndex)
            .getAll(draftKey);
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error ?? new Error('Unable to restore local attachments.'));
    });
    const recordsById = new Map(records.map((record) => [record.attachmentId, record]));

    return attachmentIds.flatMap((id) => {
        const record = recordsById.get(id);
        if (!record?.blob) return [];

        return [{
            id,
            file: new File([record.blob], record.name, {
                type: record.type,
                lastModified: record.lastModified,
            }),
        }];
    });
}

async function removeReportDraftAttachments(draftKey, attachmentIds = null) {
    const database = await openReportDraftDatabase();

    await new Promise((resolve, reject) => {
        const transaction = database.transaction(reportDraftAttachmentStore, 'readwrite');
        const store = transaction.objectStore(reportDraftAttachmentStore);
        const index = store.index(reportDraftAttachmentIndex);
        const request = index.openCursor(IDBKeyRange.only(draftKey));
        request.onsuccess = () => {
            const cursor = request.result;
            if (!cursor) return;
            if (attachmentIds === null || attachmentIds.includes(cursor.value.attachmentId)) {
                cursor.delete();
            }
            cursor.continue();
        };
        transaction.oncomplete = resolve;
        transaction.onerror = () => reject(transaction.error ?? new Error('Unable to remove local attachments.'));
        transaction.onabort = () => reject(transaction.error ?? new Error('Local attachment cleanup was interrupted.'));
    });
}

function reportDraftStatus(root, message, isError = false) {
    const status = root.querySelector('[data-report-draft-status]');
    if (!(status instanceof HTMLElement)) return;
    status.textContent = message;
    status.classList.toggle('is-error', isError);
}

function currentReportDraftAttachments(draftKey) {
    return pendingReportAttachments.get(draftKey) ?? [];
}

function reportDraftSnapshot(root) {
    const draftKey = reportDraftKey(root);
    if (!draftKey) return null;

    const editor = root.querySelector('[data-report-editor]');
    const component = reportEditorComponent(root);
    const attachments = currentReportDraftAttachments(draftKey);

    return {
        version: 1,
        body: editor instanceof HTMLElement ? editor.innerHTML : '',
        caseIds: component?.get('caseIds') ?? [],
        personIds: component?.get('personIds') ?? [],
        removeMediaIds: component?.get('removeMediaIds') ?? [],
        attachmentIds: attachments.map((attachment) => attachment.id),
        updatedAt: Date.now(),
    };
}

function saveReportDraft(root) {
    const draftKey = reportDraftKey(root);
    if (root.dataset.reportDraftRestoring === 'true') return;
    const snapshot = reportDraftSnapshot(root);
    if (!draftKey || !snapshot) return;

    try {
        localStorage.setItem(draftKey, JSON.stringify(snapshot));
        reportDraftStatus(root, 'Draft saved in this browser.');
    } catch (error) {
        console.error('Unable to save report draft in local storage.', error);
        reportDraftStatus(root, 'Draft could not be saved in this browser. Check available storage.', true);
    }
}

function sanitizeReportDraftHtml(html) {
    const parsed = new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html');
    const fragment = document.createDocumentFragment();
    parsed.body.childNodes.forEach((node) => {
        const safeNode = safeReportPreviewNode(node);
        if (safeNode) fragment.append(safeNode);
    });
    const container = document.createElement('div');
    container.append(fragment);

    return container.innerHTML;
}

function setReportUploadInputFiles(input, attachments, dispatchChange = false) {
    if (dispatchChange && attachments.length === 0) {
        const root = input.closest('[data-report-draft-key]');
        const component = root instanceof HTMLElement ? reportEditorComponent(root) : null;
        input.value = '';
        component?.set('uploads', [], true);

        return;
    }

    const dataTransfer = new DataTransfer();
    attachments.forEach(({ file }) => dataTransfer.items.add(file));
    input.files = dataTransfer.files;

    if (!dispatchChange) return;

    reportDraftRestoreInProgress.add(input);
    input.dispatchEvent(new Event('change', { bubbles: true }));
    window.setTimeout(() => {
        reportDraftRestoreInProgress.delete(input);
        input.value = '';
    }, 0);
}

function renderReportUploadPreviews(input, attachments) {
    const previewArea = input.closest('form')?.querySelector('[data-report-previews]');
    if (!(previewArea instanceof HTMLElement)) return;

    revokePreviewUrls(input);
    previewArea.replaceChildren();
    const urls = [];

    attachments.forEach(({ id, file }, index) => {
        const card = document.createElement('article');
        card.className = 'report-upload-preview';
        card.dataset.reportAttachmentId = id;
        const url = URL.createObjectURL(file);
        urls.push(url);
        activeObjectUrls.add(url);

        if (file.type.startsWith('image/')) {
            const image = document.createElement('img');
            image.src = url;
            image.alt = `Selected report attachment ${index + 1}`;
            image.loading = 'lazy';
            image.decoding = 'async';
            card.append(image);
        } else if (file.type.startsWith('video/')) {
            const video = document.createElement('video');
            video.dataset.reportVideoThumbnail = '';
            video.dataset.thumbnailUrl = url;
            video.muted = true;
            video.preload = 'none';
            video.playsInline = true;
            video.hidden = true;
            video.setAttribute('aria-hidden', 'true');
            card.append(video);
        } else {
            const icon = document.createElement('span');
            icon.className = 'ri ri-file-unknow-line';
            icon.setAttribute('aria-hidden', 'true');
            card.append(icon);
        }

        const footer = document.createElement('div');
        footer.className = 'report-upload-preview__footer';
        const name = document.createElement('span');
        name.textContent = file.name;
        name.title = file.name;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.dataset.reportUploadRemove = id;
        remove.setAttribute('aria-label', `Remove selected attachment ${index + 1}`);
        remove.title = 'Remove attachment';
        remove.innerHTML = '<i class="ri ri-close-line" aria-hidden="true"></i>';
        footer.append(name, remove);
        card.append(footer);
        previewArea.append(card);
    });

    previewObjectUrls.set(input, urls);
    initializeReportVideoThumbnails(previewArea);
}

function addReportDraftFiles(input, files, dispatchChange = false) {
    const root = input.closest('[data-report-draft-key]');
    const draftKey = reportDraftKey(root);
    if (!draftKey) return;

    const attachments = currentReportDraftAttachments(draftKey);
    const added = [];
    for (const file of files) {
        if (!reportDraftAllowedMimeTypes.has(file.type)) {
            reportDraftStatus(root, `${file.name} is not a supported image or video type.`, true);
            continue;
        }
        if (file.size > reportDraftMaximumFileSize) {
            reportDraftStatus(root, `${file.name} exceeds the 50 MB per-file limit.`, true);
            continue;
        }
        const duplicate = [...attachments, ...added].some(({ file: existing }) => (
            existing.name === file.name
            && existing.size === file.size
            && existing.type === file.type
            && existing.lastModified === file.lastModified
        ));
        if (duplicate) continue;
        if (attachments.length + added.length >= reportDraftMaximumFiles) {
            reportDraftStatus(root, 'A report can include up to 12 new attachments.', true);
            break;
        }
        added.push({ id: crypto.randomUUID(), file });
    }

    const updatedAttachments = [...attachments, ...added];
    pendingReportAttachments.set(draftKey, updatedAttachments);
    setReportUploadInputFiles(input, updatedAttachments, dispatchChange);
    renderReportUploadPreviews(input, updatedAttachments);
    void (async () => {
        try {
            await Promise.all(added.map((attachment) => storeReportDraftAttachment(draftKey, attachment)));
            saveReportDraft(root);
        } catch (error) {
            console.error('Unable to persist report attachments in IndexedDB.', error);
            reportDraftStatus(root, 'Attachments are selected but could not be saved for later. Keep this page open.', true);
        }
    })();

    if (dispatchChange) reportDraftStatus(root, 'Attachments added to this report.');
}

async function restoreReportDraft(root) {
    const draftKey = reportDraftKey(root);
    if (!draftKey || root.dataset.reportDraftRestored === 'true') return;
    root.dataset.reportDraftRestored = 'true';
    root.dataset.reportDraftRestoring = 'true';
    activeReportDraftKey = draftKey;

    let editorRoot = root;
    let draft = null;
    try {
        const saved = localStorage.getItem(draftKey);
        draft = saved ? JSON.parse(saved) : null;
        if (!draft || draft.version !== 1) draft = null;
    } catch (error) {
        console.error('Unable to read the saved report draft.', error);
        reportDraftStatus(root, 'The saved draft could not be read from this browser.', true);
    }

    if (draft) {
        const editor = editorRoot.querySelector('[data-report-editor]');
        const safeBody = typeof draft.body === 'string' ? sanitizeReportDraftHtml(draft.body) : '';
        if (editor instanceof HTMLElement) editor.innerHTML = safeBody;
        const component = reportEditorComponent(editorRoot);
        if (component) {
            component.set('body', safeBody, false);
            component.set('caseIds', Array.isArray(draft.caseIds) ? draft.caseIds : [], false);
            component.set('personIds', Array.isArray(draft.personIds) ? draft.personIds : [], false);
            component.set('removeMediaIds', Array.isArray(draft.removeMediaIds) ? draft.removeMediaIds : [], false);
            try {
                await component.$wire?.$refresh?.();
            } catch (error) {
                console.error('Unable to refresh the report editor after draft restoration.', error);
                reportDraftStatus(editorRoot, 'Draft restored, but linked records may need a refresh.', true);
            }
        }
    }

    editorRoot = Array.from(document.querySelectorAll('[data-report-draft-key]'))
        .find((candidate) => reportDraftKey(candidate) === draftKey) ?? editorRoot;
    const input = editorRoot.querySelector('[data-report-files]');
    if (!(input instanceof HTMLInputElement)) {
        delete editorRoot.dataset.reportDraftRestoring;
        return;
    }

    try {
        const ids = Array.isArray(draft?.attachmentIds) ? draft.attachmentIds : [];
        const attachments = ids.length > 0 ? await readReportDraftAttachments(draftKey, ids) : [];
        pendingReportAttachments.set(draftKey, attachments);
        if (!input.isConnected || reportDraftKey(input.closest('[data-report-draft-key]')) !== draftKey) {
            delete editorRoot.dataset.reportDraftRestoring;
            return;
        }
        setReportUploadInputFiles(input, attachments, attachments.length > 0);
        renderReportUploadPreviews(input, attachments);
        if (draft) reportDraftStatus(editorRoot, 'Draft restored from this browser.');
        delete editorRoot.dataset.reportDraftRestoring;
        saveReportDraft(editorRoot);
    } catch (error) {
        console.error('Unable to restore report attachments from IndexedDB.', error);
        delete editorRoot.dataset.reportDraftRestoring;
        reportDraftStatus(root, 'Some saved attachments could not be restored. Select them again to continue.', true);
    }
}

function initializeReportDraft(root = document) {
    const editorRoot = root instanceof HTMLElement && root.matches('[data-report-draft-key]')
        ? root
        : root.querySelector?.('[data-report-draft-key]');
    if (editorRoot instanceof HTMLElement) void restoreReportDraft(editorRoot);
}

export function syncReportRealtimeSubscription() {
    const echo = window.Echo;
    if (!echo) return;

    const selectedId = document.querySelector('[data-report-selected-id]')?.dataset.reportSelectedId;
    const nextReportId = selectedId && /^\d+$/.test(selectedId) ? selectedId : null;
    if (nextReportId === subscribedReportId) return;

    if (subscribedReportId !== null) {
        echo.leave(`reports.${subscribedReportId}`);
        subscribedReportId = null;
    }

    if (nextReportId === null) return;

    subscribedReportId = nextReportId;
    echo.private(`reports.${nextReportId}`)
        .listen('.agency.report.updated', (event) => {
            if (
                String(event.report_id) !== subscribedReportId
                || !['commented', 'replied'].includes(event.action)
            ) return;

            window.Livewire?.dispatch('agency-report-comments-updated');
        });
}

function revokePreviewUrls(input) {
    for (const url of previewObjectUrls.get(input) ?? []) {
        URL.revokeObjectURL(url);
        activeObjectUrls.delete(url);
    }

    previewObjectUrls.delete(input);
}

function clearAllPreviewUrls() {
    for (const url of activeObjectUrls) URL.revokeObjectURL(url);
    activeObjectUrls.clear();
    document.querySelectorAll('[data-report-previews]').forEach((area) => area.replaceChildren());
    document.querySelectorAll('[data-report-files]').forEach((input) => {
        if (input instanceof HTMLInputElement) input.value = '';
    });
}

function renderUploadPreviews(input) {
    const root = input.closest('[data-report-draft-key]');
    const draftKey = reportDraftKey(root);
    if (!draftKey) return;
    renderReportUploadPreviews(input, currentReportDraftAttachments(draftKey));
}

function reportWorkspaceRoot(element) {
    return element.closest('[wire\\:id]') ?? document;
}

function activateReportSection(button) {
    const root = reportWorkspaceRoot(button);
    const section = button.dataset.reportSectionButton;

    root.querySelectorAll('[data-report-section-panel]').forEach((panel) => {
        panel.hidden = panel.dataset.reportSectionPanel !== section;
    });
    root.querySelectorAll('[data-report-section-button][role="tab"]').forEach((tab) => {
        const selected = tab.dataset.reportSectionButton === section;
        tab.classList.toggle('is-active', selected);
        tab.setAttribute('aria-selected', String(selected));
        tab.setAttribute('tabindex', selected ? '0' : '-1');
    });

    const componentId = root.getAttribute('wire:id');
    const component = componentId ? window.Livewire?.find(componentId) : null;
    component?.call('showSection', section);
}

function loadReportVideoThumbnail(video) {
    if (!(video instanceof HTMLVideoElement)) return;
    const url = video.dataset.thumbnailUrl;
    if (!url) return;
    if (
        video.dataset.reportThumbnailLoaded === 'true'
        && video.dataset.reportThumbnailSource === url
    ) return;
    if (
        video.dataset.reportThumbnailLoading === 'true'
        && video.dataset.reportThumbnailSource === url
    ) return;

    video.pause();
    video.removeAttribute('src');
    video.load();
    video.hidden = true;
    video.closest('.report-media-tile, .report-gallery-grid button, .report-existing-media button, .report-upload-preview')
        ?.classList.remove('has-no-video-thumbnail');
    video.dataset.reportThumbnailLoading = 'true';
    video.dataset.reportThumbnailSource = url;
    video.preload = 'metadata';
    video.muted = true;
    video.playsInline = true;
    const isCurrentThumbnail = () => (
        video.dataset.thumbnailUrl === url
        && video.dataset.reportThumbnailSource === url
    );
    video.addEventListener('loadedmetadata', () => {
        if (!isCurrentThumbnail()) return;
        if (video.duration > 0) video.currentTime = Math.min(0.1, video.duration / 2);
    }, { once: true });
    video.addEventListener('loadeddata', () => {
        if (!isCurrentThumbnail()) return;
        video.hidden = false;
        video.pause();
        video.dataset.reportThumbnailLoaded = 'true';
        delete video.dataset.reportThumbnailLoading;
    }, { once: true });
    video.addEventListener('seeked', () => {
        if (!isCurrentThumbnail()) return;
        video.hidden = false;
        video.pause();
        video.dataset.reportThumbnailLoaded = 'true';
        delete video.dataset.reportThumbnailLoading;
    }, { once: true });
    video.addEventListener('error', () => {
        if (!isCurrentThumbnail()) return;
        video.hidden = true;
        delete video.dataset.reportThumbnailLoaded;
        delete video.dataset.reportThumbnailLoading;
        video.closest('.report-media-tile, .report-gallery-grid button, .report-existing-media button, .report-upload-preview')
            ?.classList.add('has-no-video-thumbnail');
    }, { once: true });
    video.src = url;
    video.load();
}

function initializeReportVideoThumbnails(root = document) {
    if (root instanceof HTMLVideoElement && root.hasAttribute('data-report-video-thumbnail')) {
        loadReportVideoThumbnail(root);
    }

    root.querySelectorAll?.('[data-report-video-thumbnail]')
        .forEach(loadReportVideoThumbnail);

    const lazyThumbnails = root instanceof HTMLVideoElement && root.hasAttribute('data-report-lazy-thumbnail')
        ? [root]
        : Array.from(root.querySelectorAll?.('[data-report-lazy-thumbnail]') ?? []);
    if (lazyThumbnails.length === 0) return;
    if (!('IntersectionObserver' in window)) {
        lazyThumbnails.forEach(loadReportVideoThumbnail);
        return;
    }

    lazyReportThumbnailObserver ??= new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (!entry.isIntersecting || !(entry.target instanceof HTMLVideoElement)) return;
            lazyReportThumbnailObserver?.unobserve(entry.target);
            loadReportVideoThumbnail(entry.target);
        });
    }, { rootMargin: '120px' });
    lazyThumbnails.forEach((video) => lazyReportThumbnailObserver?.observe(video));
}

function closeReportModals() {
    document.querySelectorAll('[data-report-gallery-modal]').forEach((modal) => {
        modal.hidden = true;
    });
    document.querySelectorAll('[data-report-gallery-items]').forEach((content) => {
        content.replaceChildren();
    });
    galleryTrigger = null;
}

function closeReportGalleryModal(restoreFocus = true) {
    document.querySelectorAll('[data-report-gallery-modal]').forEach((modal) => {
        modal.hidden = true;
    });
    document.querySelectorAll('[data-report-gallery-items]').forEach((content) => {
        content.replaceChildren();
    });
    const trigger = galleryTrigger;
    galleryTrigger = null;
    if (restoreFocus) trigger?.focus();
}

function reportEditorFromControl(control) {
    return control.closest('[data-report-editor-form]')?.querySelector('[data-report-editor]') ?? null;
}

function saveReportEditorSelection(control) {
    const editor = reportEditorFromControl(control);
    const selection = window.getSelection();
    if (
        editor instanceof HTMLElement
        && selection?.rangeCount
        && editor.contains(selection.anchorNode)
    ) {
        editorSelectionRange = selection.getRangeAt(0).cloneRange();
    }
}

function restoreReportEditorSelection(editor) {
    editor.focus({ preventScroll: true });
    if (!editorSelectionRange) return;

    const selection = window.getSelection();
    if (!selection) return;
    selection.removeAllRanges();
    selection.addRange(editorSelectionRange);
}

function safeReportPreviewNode(node) {
    if (node.nodeType === Node.TEXT_NODE) return document.createTextNode(node.textContent ?? '');
    if (!(node instanceof HTMLElement)) return null;
    if (reportPreviewRemovedTags.has(node.tagName)) return null;

    if (!reportPreviewAllowedTags.has(node.tagName)) {
        const fragment = document.createDocumentFragment();
        node.childNodes.forEach((child) => {
            const safeChild = safeReportPreviewNode(child);
            if (safeChild) fragment.append(safeChild);
        });
        return fragment;
    }

    const safeElement = document.createElement(node.tagName.toLowerCase());
    if (node.tagName === 'A') {
        const href = node.getAttribute('href')?.trim() ?? '';
        const scheme = href.slice(0, href.indexOf(':')).toLowerCase();
        if (
            (scheme === 'http' || scheme === 'https')
            && URL.canParse(href)
            || (scheme === 'mailto' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(href.slice(7)))
        ) {
            safeElement.setAttribute('href', href);
            safeElement.setAttribute('rel', 'noopener noreferrer');
        }
        const title = node.getAttribute('title');
        if (title) safeElement.setAttribute('title', title.slice(0, 255));
    }
    node.childNodes.forEach((child) => {
        const safeChild = safeReportPreviewNode(child);
        if (safeChild) safeElement.append(safeChild);
    });

    return safeElement;
}

function openReportPreview(trigger) {
    const editor = reportEditorFromControl(trigger);
    const root = reportWorkspaceRoot(trigger);
    const backdrop = root.querySelector('[data-report-preview-backdrop]');
    const content = backdrop?.querySelector('[data-report-preview-content]');
    if (!(editor instanceof HTMLElement) || !(backdrop instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

    content.replaceChildren();
    editor.childNodes.forEach((child) => {
        const safeChild = safeReportPreviewNode(child);
        if (safeChild) content.append(safeChild);
    });
    reportPreviewTrigger = trigger;
    backdrop.hidden = false;
    backdrop.querySelector('[data-report-preview-close]')?.focus();
}

function closeReportPreview(restoreFocus = true) {
    document.querySelectorAll('[data-report-preview-backdrop]').forEach((backdrop) => {
        backdrop.hidden = true;
        backdrop.querySelector('[data-report-preview-content]')?.replaceChildren();
    });
    const trigger = reportPreviewTrigger;
    reportPreviewTrigger = null;
    if (restoreFocus) trigger?.focus();
}

function openReportMedia(source, trigger) {
    const mediaUrl = source.dataset.mediaUrl;
    const mediaType = source.dataset.mediaType;
    if (!mediaUrl || !['image', 'video'].includes(mediaType ?? '')) return;

    const openedFromGallery = trigger.closest('[data-report-gallery-modal]') !== null;
    if (!openedFromGallery) closeReportModals();

    if (mediaType === 'image') {
        openImageViewer({
            src: mediaUrl,
            downloadUrl: mediaUrl,
            name: 'Report image',
            extension: source.dataset.mediaExtension,
            mimeType: source.dataset.mediaMimeType,
            returnFocus: trigger,
        });
        return;
    }

    openVaultVideoViewer({
        source: mediaUrl,
        downloadUrl: mediaUrl,
        mimeType: source.dataset.mediaMimeType,
        name: source.dataset.mediaTitle || `Report video ${source.dataset.mediaIndex ?? ''}`.trim(),
        returnFocus: trigger,
        autoplay: true,
    });
}

function openReportGallery(trigger) {
    const root = reportWorkspaceRoot(trigger);
    const modal = root.querySelector('[data-report-gallery-modal]');
    const gallery = modal?.querySelector('[data-report-gallery-items]');
    const data = root.querySelector('[data-report-gallery-data]');
    if (!(modal instanceof HTMLElement) || !(gallery instanceof HTMLElement) || !(data instanceof HTMLElement)) return;

    closeReportModals();
    galleryTrigger = trigger;

    data.querySelectorAll('[data-report-media-item]').forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.reportMediaOpen = '';
        button.dataset.mediaUrl = item.dataset.mediaUrl ?? '';
        button.dataset.mediaType = item.dataset.mediaType ?? '';
        button.dataset.mediaIndex = item.dataset.mediaIndex ?? '';
        button.dataset.mediaTitle = item.dataset.mediaTitle ?? '';
        button.dataset.mediaExtension = item.dataset.mediaExtension ?? '';
        button.dataset.mediaMimeType = item.dataset.mediaMimeType ?? '';
        button.setAttribute('aria-label', `Preview report attachment ${button.dataset.mediaIndex}`);

        if (button.dataset.mediaType === 'image') {
            const image = document.createElement('img');
            image.src = button.dataset.mediaUrl;
            image.alt = `Report attachment ${button.dataset.mediaIndex}`;
            image.loading = 'lazy';
            image.decoding = 'async';
            button.append(image);
        } else {
            const video = document.createElement('video');
            video.className = 'report-video-thumbnail';
            video.dataset.reportVideoThumbnail = '';
            video.dataset.thumbnailUrl = button.dataset.mediaUrl;
            video.muted = true;
            video.playsInline = true;
            video.preload = 'none';
            video.hidden = true;
            video.setAttribute('aria-hidden', 'true');
            button.append(video);

            const videoMark = document.createElement('span');
            videoMark.className = 'report-video-mark';
            videoMark.setAttribute('aria-hidden', 'true');
            videoMark.innerHTML = '<i class="ri ri-play-fill"></i>';
            button.append(videoMark);
        }

        gallery.append(button);
    });

    modal.hidden = false;
    initializeReportVideoThumbnails(gallery);
    modal.querySelector('[data-report-modal-close]')?.focus();
}

document.addEventListener('change', (event) => {
    const input = event.target;
    if (
        !(input instanceof HTMLInputElement)
        || !input.matches('[data-report-files]')
        || reportDraftRestoreInProgress.has(input)
        || input.files.length === 0
    ) return;

    addReportDraftFiles(input, Array.from(input.files));
    window.setTimeout(() => {
        input.value = '';
    }, 0);
}, true);

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.matches('[data-report-files]')) {
        renderUploadPreviews(input);
        return;
    }

    const fontSize = event.target;
    if (!(fontSize instanceof HTMLSelectElement) || !fontSize.matches('[data-report-font-size]')) return;
    const editor = reportEditorFromControl(fontSize);
    if (!(editor instanceof HTMLElement)) return;
    restoreReportEditorSelection(editor);
    const block = ['p', 'h2', 'h3'].includes(fontSize.value) ? fontSize.value : 'p';
    document.execCommand('formatBlock', false, `<${block}>`);
    editor.dispatchEvent(new Event('input', { bubbles: true }));
});

document.addEventListener('input', (event) => {
    const editor = event.target;
    if (!(editor instanceof HTMLElement) || !editor.matches('[data-report-editor]')) return;
    const root = reportWorkspaceRoot(editor);
    const componentId = root.getAttribute('wire:id');
    const component = componentId ? window.Livewire?.find(componentId) : null;
    component?.set('body', editor.innerHTML, false);
    const draftRoot = editor.closest('[data-report-draft-key]');
    if (draftRoot instanceof HTMLElement) saveReportDraft(draftRoot);
});

document.addEventListener('dragenter', (event) => {
    if (!(event.target instanceof Element)) return;
    const dropzone = event.target.closest('[data-report-dropzone]');
    if (!(dropzone instanceof HTMLElement) || !Array.from(event.dataTransfer?.types ?? []).includes('Files')) return;
    event.preventDefault();
    dropzone.classList.add('is-dragging');
});

document.addEventListener('dragover', (event) => {
    if (!(event.target instanceof Element)) return;
    const dropzone = event.target.closest('[data-report-dropzone]');
    if (!(dropzone instanceof HTMLElement) || !Array.from(event.dataTransfer?.types ?? []).includes('Files')) return;
    event.preventDefault();
    if (event.dataTransfer) event.dataTransfer.dropEffect = 'copy';
    dropzone.classList.add('is-dragging');
});

document.addEventListener('dragleave', (event) => {
    if (!(event.target instanceof Element)) return;
    const dropzone = event.target.closest('[data-report-dropzone]');
    if (!(dropzone instanceof HTMLElement) || dropzone.contains(event.relatedTarget)) return;
    dropzone.classList.remove('is-dragging');
});

document.addEventListener('drop', (event) => {
    if (!(event.target instanceof Element)) return;
    const dropzone = event.target.closest('[data-report-dropzone]');
    if (!(dropzone instanceof HTMLElement) || !Array.from(event.dataTransfer?.types ?? []).includes('Files')) return;
    event.preventDefault();
    dropzone.classList.remove('is-dragging');
    const input = dropzone.querySelector('[data-report-files]');
    if (input instanceof HTMLInputElement && event.dataTransfer) {
        addReportDraftFiles(input, Array.from(event.dataTransfer.files), true);
    }
});

document.addEventListener('mousedown', (event) => {
    if (!(event.target instanceof Element)) return;
    const control = event.target.closest('[data-report-format-command], [data-report-preview-open]');
    if (!(control instanceof HTMLElement)) return;
    saveReportEditorSelection(control);
    if (control.matches('[data-report-format-command]')) event.preventDefault();
});

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;
    if (event.target.closest('[data-report-font-size]')) return;

    const formatButton = event.target.closest('[data-report-format-command]');
    if (formatButton instanceof HTMLButtonElement) {
        const editor = reportEditorFromControl(formatButton);
        if (!(editor instanceof HTMLElement)) return;
        restoreReportEditorSelection(editor);
        const command = formatButton.dataset.reportFormatCommand;
        const value = formatButton.dataset.reportFormatValue ?? null;
        if (command === 'bold') document.execCommand('styleWithCSS', false, 'false');
        document.execCommand(command, false, value ? `<${value}>` : null);
        editor.dispatchEvent(new Event('input', { bubbles: true }));
        return;
    }

    const uploadRemoveButton = event.target.closest('[data-report-upload-remove]');
    if (uploadRemoveButton instanceof HTMLButtonElement) {
        event.preventDefault();
        event.stopPropagation();
        const root = uploadRemoveButton.closest('[data-report-draft-key]');
        const input = root?.querySelector('[data-report-files]');
        const draftKey = reportDraftKey(root);
        const attachmentId = uploadRemoveButton.dataset.reportUploadRemove;
        if (!(root instanceof HTMLElement) || !(input instanceof HTMLInputElement) || !draftKey || !attachmentId) return;

        const attachments = currentReportDraftAttachments(draftKey);
        const updated = attachments.filter((attachment) => attachment.id !== attachmentId);
        pendingReportAttachments.set(draftKey, updated);
        setReportUploadInputFiles(input, updated, true);
        renderReportUploadPreviews(input, updated);
        saveReportDraft(root);
        void removeReportDraftAttachments(draftKey, [attachmentId]).catch((error) => {
            console.error('Unable to remove a report attachment from local storage.', error);
            reportDraftStatus(root, 'The attachment could not be removed from browser storage. Try again.', true);
        });
        return;
    }

    const discardButton = event.target.closest('[data-report-discard]');
    if (discardButton instanceof HTMLButtonElement) {
        event.preventDefault();
        const root = discardButton.closest('[data-report-draft-key]');
        const draftKey = reportDraftKey(root);
        const component = root instanceof HTMLElement ? reportEditorComponent(root) : null;
        if (!(root instanceof HTMLElement) || !draftKey || !component) return;

        discardButton.disabled = true;
        discardButton.textContent = 'DISCARDING…';
        try {
            await removeReportDraftAttachments(draftKey);
            localStorage.removeItem(draftKey);
            pendingReportAttachments.delete(draftKey);
            revokePreviewUrls(root.querySelector('[data-report-files]'));
            await component.call('discardReport');
        } catch (error) {
            console.error('Unable to discard the saved report draft.', error);
            reportDraftStatus(root, 'The draft could not be cleared from this browser. Please try again.', true);
            discardButton.disabled = false;
            discardButton.textContent = 'DISCARD';
        }
        return;
    }

    const previewTrigger = event.target.closest('[data-report-preview-open]');
    if (previewTrigger instanceof HTMLButtonElement) {
        event.preventDefault();
        openReportPreview(previewTrigger);
        return;
    }

    const previewCloseButton = event.target.closest('[data-report-preview-close]');
    const previewBackdrop = event.target.closest('[data-report-preview-backdrop]');
    if (
        previewCloseButton instanceof HTMLElement
        || (previewBackdrop instanceof HTMLElement && event.target === previewBackdrop)
    ) {
        closeReportPreview();
        return;
    }

    const stagedMediaButton = event.target.closest('[data-report-media-toggle]');
    if (stagedMediaButton instanceof HTMLButtonElement) {
        stagedMediaButton.classList.toggle('is-marked-remove');
    }

    const composerCloseButton = event.target.closest('[data-report-composer-close]');
    if (composerCloseButton instanceof HTMLElement) clearAllPreviewUrls();

    const replyButton = event.target.closest('[data-report-reply-open]');
    if (replyButton instanceof HTMLElement) {
        event.preventDefault();
        const comment = replyButton.closest('.report-comment');
        const form = comment?.querySelector('[data-report-reply-form]');
        if (!(form instanceof HTMLFormElement)) return;

        reportWorkspaceRoot(replyButton).querySelectorAll('[data-report-reply-form]').forEach((replyForm) => {
            replyForm.hidden = replyForm !== form;
        });
        const componentId = reportWorkspaceRoot(replyButton).getAttribute('wire:id');
        const component = componentId ? window.Livewire?.find(componentId) : null;
        component?.set('replyingToId', Number(replyButton.dataset.replyId), false);
        component?.set('replyBody', '', false);
        form.querySelector('textarea')?.focus();

        return;
    }

    const cancelReplyButton = event.target.closest('[data-report-reply-cancel]');
    if (cancelReplyButton instanceof HTMLElement) {
        event.preventDefault();
        const form = cancelReplyButton.closest('[data-report-reply-form]');
        if (form instanceof HTMLFormElement) form.hidden = true;

        const componentId = reportWorkspaceRoot(cancelReplyButton).getAttribute('wire:id');
        const component = componentId ? window.Livewire?.find(componentId) : null;
        component?.set('replyingToId', null, false);
        component?.set('replyBody', '', false);

        return;
    }

    const sectionButton = event.target.closest('[data-report-section-button]');
    if (sectionButton instanceof HTMLElement) {
        event.preventDefault();
        activateReportSection(sectionButton);

        return;
    }

    const mediaButton = event.target.closest('[data-report-media-open]');
    if (mediaButton instanceof HTMLElement) {
        event.preventDefault();
        if (mediaButton.dataset.reportGalleryOpen === 'true') {
            openReportGallery(mediaButton);
        } else {
            openReportMedia(mediaButton, mediaButton);
        }

        return;
    }

    const closeButton = event.target.closest('[data-report-modal-close]');
    const galleryBackdrop = event.target.closest('[data-report-gallery-modal]');
    if ((closeButton instanceof HTMLElement && closeButton.closest('[data-report-gallery-modal]')) || event.target === galleryBackdrop) {
        closeReportGalleryModal();
        return;
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (document.querySelector('[data-vault-image-viewer][open], [data-video-viewer-dialog][open]')) return;

    if (document.querySelector('[data-report-preview-backdrop]:not([hidden])')) {
        closeReportPreview();
        return;
    }
    if (document.querySelector('[data-report-gallery-modal]:not([hidden])')) closeReportGalleryModal();
});

document.addEventListener('livewire:navigating', () => {
    const draftRoot = document.querySelector('[data-report-draft-key]');
    if (draftRoot instanceof HTMLElement) saveReportDraft(draftRoot);
    clearAllPreviewUrls();
    closeReportModals();
    closeReportPreview(false);
    lazyReportThumbnailObserver?.disconnect();
    lazyReportThumbnailObserver = null;
    if (subscribedReportId !== null && window.Echo) {
        window.Echo.leave(`reports.${subscribedReportId}`);
        subscribedReportId = null;
    }
});

document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('morph.updated', syncReportRealtimeSubscription);
    window.Livewire?.hook('morph.updated', ({ el }) => initializeReportVideoThumbnails(el));
    window.Livewire?.hook('morph.added', ({ el }) => initializeReportVideoThumbnails(el));
    window.Livewire?.hook('morph.updated', ({ el }) => {
        const draftRoot = el instanceof HTMLElement
            ? (el.matches('[data-report-draft-key]') ? el : el.closest('[data-report-draft-key]'))
            : null;
        if (draftRoot instanceof HTMLElement) saveReportDraft(draftRoot);
    });
    window.Livewire?.hook('morph.updating', ({ el, toEl }) => {
        if (
            el instanceof HTMLTextAreaElement
            && el.id === 'report-comment-body'
            && toEl instanceof HTMLTextAreaElement
            && el.value !== toEl.value
        ) {
            preservedCommentDrafts.set(el, {
                value: el.value,
                selectionStart: el.selectionStart,
                selectionEnd: el.selectionEnd,
            });
        }
    });
    window.Livewire?.hook('morph.updated', ({ el }) => {
        if (!(el instanceof HTMLTextAreaElement) || el.id !== 'report-comment-body') return;

        const draft = preservedCommentDrafts.get(el);
        if (!draft) return;

        el.value = draft.value;
        el.setSelectionRange(draft.selectionStart, draft.selectionEnd);
        preservedCommentDrafts.delete(el);
    });
    syncReportRealtimeSubscription();
});

document.addEventListener('livewire:navigated', syncReportRealtimeSubscription);
document.addEventListener('livewire:navigated', () => initializeReportVideoThumbnails());
document.addEventListener('livewire:navigated', () => initializeReportDraft());

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initializeReportVideoThumbnails();
        initializeReportDraft();
    }, { once: true });
} else {
    initializeReportVideoThumbnails();
    initializeReportDraft();
}

window.addEventListener('report-editor-saved', () => {
    const draftKey = activeReportDraftKey;
    if (!draftKey) return;

    void removeReportDraftAttachments(draftKey)
        .then(() => {
            localStorage.removeItem(draftKey);
            pendingReportAttachments.delete(draftKey);
            activeReportDraftKey = null;
        })
        .catch((error) => {
            console.error('Unable to clear the published report draft from browser storage.', error);
        });
});

window.addEventListener('report-comment-submitted', () => {
    const textarea = document.querySelector('#report-comment-body');
    if (!(textarea instanceof HTMLTextAreaElement)) return;

    preservedCommentDrafts.delete(textarea);
    textarea.value = '';
});
