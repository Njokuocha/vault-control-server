import { openImageViewer } from './image-viewer.js';

const previewObjectUrls = new WeakMap();
const activeObjectUrls = new Set();
let galleryTrigger = null;
let mediaTrigger = null;
let subscribedReportId = null;
const preservedCommentDrafts = new WeakMap();

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
    const previewArea = input.closest('form')?.querySelector('[data-report-previews]');
    if (!(previewArea instanceof HTMLElement)) return;

    revokePreviewUrls(input);
    previewArea.replaceChildren();
    const urls = [];

    for (const file of Array.from(input.files ?? [])) {
        const card = document.createElement('span');
        card.className = 'report-upload-preview';
        const url = URL.createObjectURL(file);
        urls.push(url);
        activeObjectUrls.add(url);

        if (file.type.startsWith('image/')) {
            const image = document.createElement('img');
            image.src = url;
            image.alt = 'Report attachment preview';
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

        previewArea.append(card);
    }

    previewObjectUrls.set(input, urls);
    initializeReportVideoThumbnails(previewArea);
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

function mediaElement(type, url) {
    if (type === 'image') {
        const image = document.createElement('img');
        image.src = url;
        image.alt = 'Report attachment preview';
        image.decoding = 'async';

        return image;
    }

    const video = document.createElement('video');
    video.src = url;
    video.controls = true;
    video.autoplay = true;
    video.playsInline = true;
    video.preload = 'metadata';
    video.setAttribute('aria-label', 'Report video preview');

    return video;
}

function loadReportVideoThumbnail(video) {
    if (!(video instanceof HTMLVideoElement) || video.dataset.reportThumbnailLoaded === 'true') return;
    const url = video.dataset.thumbnailUrl;
    if (!url) return;

    video.dataset.reportThumbnailLoaded = 'true';
    video.preload = 'metadata';
    video.muted = true;
    video.playsInline = true;
    video.addEventListener('loadedmetadata', () => {
        if (video.duration > 0) video.currentTime = Math.min(0.1, video.duration / 2);
    }, { once: true });
    video.addEventListener('loadeddata', () => {
        video.hidden = false;
        video.pause();
    }, { once: true });
    video.addEventListener('seeked', () => {
        video.hidden = false;
        video.pause();
    }, { once: true });
    video.addEventListener('error', () => {
        video.hidden = true;
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

    root.querySelectorAll?.('[data-report-video-thumbnail]:not([data-report-thumbnail-loaded])')
        .forEach(loadReportVideoThumbnail);
}

function closeReportModals() {
    document.querySelectorAll('[data-report-gallery-modal], [data-report-media-modal]').forEach((modal) => {
        modal.hidden = true;
    });
    document.querySelectorAll('[data-report-gallery-items], [data-report-media-content]').forEach((content) => {
        content.replaceChildren();
    });
    galleryTrigger = null;
    mediaTrigger = null;
}

function closeReportMediaModal(restoreFocus = true) {
    document.querySelectorAll('[data-report-media-modal]').forEach((modal) => {
        modal.hidden = true;
    });
    document.querySelectorAll('[data-report-media-content]').forEach((content) => {
        content.replaceChildren();
    });
    const trigger = mediaTrigger;
    mediaTrigger = null;
    if (restoreFocus) trigger?.focus();
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

function openReportMedia(source, trigger) {
    const modal = reportWorkspaceRoot(trigger).querySelector('[data-report-media-modal]');
    const content = modal?.querySelector('[data-report-media-content]');
    if (!(modal instanceof HTMLElement) || !(content instanceof HTMLElement)) return;

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

    mediaTrigger = trigger;
    content.append(mediaElement(mediaType, mediaUrl));
    modal.hidden = false;
    modal.querySelector('[data-report-modal-close]')?.focus();
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
    if (input instanceof HTMLInputElement && input.matches('[data-report-files]')) {
        renderUploadPreviews(input);
    }
});

document.addEventListener('report-composer-closed', clearAllPreviewUrls);

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

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
    const mediaBackdrop = event.target.closest('[data-report-media-modal]');
    if ((closeButton instanceof HTMLElement && closeButton.closest('[data-report-media-modal]')) || event.target === mediaBackdrop) {
        closeReportMediaModal();
        return;
    }

    if ((closeButton instanceof HTMLElement && closeButton.closest('[data-report-gallery-modal]')) || event.target === galleryBackdrop) {
        closeReportGalleryModal();
        return;
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    if (document.querySelector('[data-vault-image-viewer][open]')) return;

    const mediaModal = document.querySelector('[data-report-media-modal]:not([hidden])');
    if (mediaModal) {
        closeReportMediaModal();
        return;
    }

    if (document.querySelector('[data-report-gallery-modal]:not([hidden])')) closeReportGalleryModal();
});

document.addEventListener('livewire:navigating', () => {
    clearAllPreviewUrls();
    closeReportModals();
    if (subscribedReportId !== null && window.Echo) {
        window.Echo.leave(`reports.${subscribedReportId}`);
        subscribedReportId = null;
    }
});

document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('morph.updated', syncReportRealtimeSubscription);
    window.Livewire?.hook('morph.updated', ({ el }) => initializeReportVideoThumbnails(el));
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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => initializeReportVideoThumbnails(), { once: true });
} else {
    initializeReportVideoThumbnails();
}

window.addEventListener('report-comment-submitted', () => {
    const textarea = document.querySelector('#report-comment-body');
    if (!(textarea instanceof HTMLTextAreaElement)) return;

    preservedCommentDrafts.delete(textarea);
    textarea.value = '';
});
