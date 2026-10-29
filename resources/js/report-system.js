import { openImageViewer } from './image-viewer.js';

const previewObjectUrls = new WeakMap();
const activeObjectUrls = new Set();
let lastModalTrigger = null;

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
            image.alt = `Preview of ${file.name}`;
            image.loading = 'lazy';
            image.decoding = 'async';
            card.append(image);
        } else if (file.type.startsWith('video/')) {
            const video = document.createElement('video');
            video.src = url;
            video.muted = true;
            video.preload = 'metadata';
            video.setAttribute('aria-label', `Video preview of ${file.name}`);
            card.append(video);
        } else {
            const icon = document.createElement('span');
            icon.className = 'ri ri-file-unknow-line';
            icon.setAttribute('aria-hidden', 'true');
            card.append(icon);
        }

        const label = document.createElement('small');
        label.textContent = file.name;
        card.append(label);
        previewArea.append(card);
    }

    previewObjectUrls.set(input, urls);
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
    component?.set('activeSection', section, false);
}

function mediaElement(type, url, name) {
    if (type === 'image') {
        const image = document.createElement('img');
        image.src = url;
        image.alt = name;
        image.decoding = 'async';

        return image;
    }

    const video = document.createElement('video');
    video.src = url;
    video.controls = true;
    video.autoplay = true;
    video.playsInline = true;
    video.preload = 'metadata';
    video.setAttribute('aria-label', name);

    return video;
}

function closeReportModals() {
    document.querySelectorAll('[data-report-gallery-modal], [data-report-media-modal]').forEach((modal) => {
        modal.hidden = true;
    });
    document.querySelectorAll('[data-report-gallery-items], [data-report-media-content]').forEach((content) => {
        content.replaceChildren();
    });
    lastModalTrigger = null;
}

function openReportMedia(source, trigger) {
    const modal = reportWorkspaceRoot(trigger).querySelector('[data-report-media-modal]');
    const content = modal?.querySelector('[data-report-media-content]');
    const name = modal?.querySelector('[data-report-media-name]');
    if (!(modal instanceof HTMLElement) || !(content instanceof HTMLElement) || !(name instanceof HTMLElement)) return;

    const mediaName = source.dataset.mediaName ?? 'Report attachment';
    const mediaUrl = source.dataset.mediaUrl;
    const mediaType = source.dataset.mediaType;
    if (!mediaUrl || !['image', 'video'].includes(mediaType ?? '')) return;

    const returnTarget = trigger.closest('[data-report-gallery-modal]') ? lastModalTrigger : trigger;
    closeReportModals();
    if (mediaType === 'image') {
        openImageViewer({
            src: mediaUrl,
            downloadUrl: mediaUrl,
            name: mediaName,
            returnFocus: returnTarget,
        });
        return;
    }

    lastModalTrigger = returnTarget instanceof HTMLElement ? returnTarget : trigger;
    name.textContent = mediaName;
    content.append(mediaElement(mediaType, mediaUrl, mediaName));
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
    lastModalTrigger = trigger;

    data.querySelectorAll('[data-report-media-item]').forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.reportMediaOpen = '';
        button.dataset.mediaUrl = item.dataset.mediaUrl ?? '';
        button.dataset.mediaType = item.dataset.mediaType ?? '';
        button.dataset.mediaName = item.dataset.mediaName ?? 'Report attachment';
        button.setAttribute('aria-label', `Preview ${button.dataset.mediaName}`);

        if (button.dataset.mediaType === 'image') {
            const image = document.createElement('img');
            image.src = button.dataset.mediaUrl;
            image.alt = button.dataset.mediaName;
            image.loading = 'lazy';
            image.decoding = 'async';
            button.append(image);
        } else {
            const videoMark = document.createElement('span');
            videoMark.className = 'report-video-mark';
            videoMark.innerHTML = '<i class="ri ri-play-fill" aria-hidden="true"></i> VIDEO';
            button.append(videoMark);
        }

        const label = document.createElement('small');
        label.textContent = button.dataset.mediaName;
        button.append(label);
        gallery.append(button);
    });

    modal.hidden = false;
    modal.querySelector('[data-report-modal-close]')?.focus();
}

document.addEventListener('change', (event) => {
    const input = event.target;
    if (input instanceof HTMLInputElement && input.matches('[data-report-files]')) {
        renderUploadPreviews(input);
    }
});

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

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
    if (closeButton instanceof HTMLElement || event.target === galleryBackdrop || event.target === mediaBackdrop) {
        const trigger = lastModalTrigger;
        closeReportModals();
        trigger?.focus();
        return;
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && document.querySelector('[data-report-gallery-modal]:not([hidden]), [data-report-media-modal]:not([hidden])')) {
        const trigger = lastModalTrigger;
        closeReportModals();
        trigger?.focus();
    }
});

document.addEventListener('livewire:navigating', () => {
    clearAllPreviewUrls();
    closeReportModals();
});

const composerObserver = new MutationObserver(() => {
    if (!document.querySelector('[data-report-composer]')) clearAllPreviewUrls();
});

composerObserver.observe(document.body, { childList: true, subtree: true });
