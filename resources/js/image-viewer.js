let dialog = null;
let image = null;
let stage = null;
let title = null;
let download = null;
let zoomLabel = null;
let zoomIn = null;
let zoomOut = null;
let grayscale = null;
let dimensionsLabel = null;
let extensionLabel = null;
let slowZoomButton = null;
const minZoom = 1;
const maxZoom = 50;
const zoomStep = 0.5;
let zoom = minZoom;
let panX = 0;
let panY = 0;
let rotation = 0;
let dragPointer = null;
let lastTrigger = null;
const boundDialogs = new WeakSet();
let downloadBusyTimer = null;
let slowZoomFrame = null;
const slowZoomCycleDuration = 10_000;
const slowZoomInDuration = 8_000;
const slowZoomMaximum = 1.35;

const refreshViewer = () => {
    const nextDialog = document.querySelector('[data-vault-image-viewer]');
    if (!(nextDialog instanceof HTMLDialogElement)) {
        dialog = null;
        image = null;
        stage = null;
        title = null;
        download = null;
        zoomLabel = null;
        zoomIn = null;
        zoomOut = null;
        grayscale = null;
        dimensionsLabel = null;
        extensionLabel = null;
        slowZoomButton = null;
        return;
    }

    dialog = nextDialog;
    image = dialog.querySelector('[data-vault-image]');
    stage = dialog.querySelector('[data-vault-image-stage]');
    title = dialog.querySelector('[data-vault-image-title]');
    download = dialog.querySelector('[data-vault-image-download]');
    zoomLabel = dialog.querySelector('[data-vault-image-zoom-level]');
    zoomIn = dialog.querySelector('[data-vault-image-zoom-in]');
    zoomOut = dialog.querySelector('[data-vault-image-zoom-out]');
    grayscale = dialog.querySelector('[data-vault-image-grayscale]');
    dimensionsLabel = dialog.querySelector('[data-vault-image-dimensions]');
    extensionLabel = dialog.querySelector('[data-vault-image-extension]');
    slowZoomButton = dialog.querySelector('[data-vault-image-slow-zoom]');
    if (!boundDialogs.has(dialog)) {
        boundDialogs.add(dialog);
        const boundDialog = dialog;
        boundDialog.addEventListener('close', () => {
            if (dialog === boundDialog) close();
        });
    }
};

const setDownloadBusy = (anchor, busy) => {
    if (!(anchor instanceof HTMLAnchorElement)) return;
    anchor.setAttribute('aria-busy', String(busy));
    const icon = anchor.querySelector('[data-vault-image-download-icon]');
    const spinner = anchor.querySelector('[data-vault-image-download-spinner]');
    if (icon instanceof HTMLElement) icon.hidden = busy;
    if (spinner instanceof HTMLElement) spinner.hidden = !busy;
};

const imageBounds = () => {
    if (!image || !stage) return null;

    const style = getComputedStyle(stage);
    return {
        width: stage.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight),
        height: stage.clientHeight - parseFloat(style.paddingTop) - parseFloat(style.paddingBottom),
    };
};

const setSlowZoomButtonState = (active) => {
    if (!(slowZoomButton instanceof HTMLButtonElement)) return;
    slowZoomButton.setAttribute('aria-pressed', String(active));
    slowZoomButton.setAttribute('aria-label', active ? 'Stop slow zoom' : 'Start slow zoom');
    slowZoomButton.title = active ? 'Stop slow zoom' : 'Start slow zoom';
    const playIcon = slowZoomButton.querySelector('[data-vault-image-motion-play]');
    const pauseIcon = slowZoomButton.querySelector('[data-vault-image-motion-pause]');
    if (playIcon instanceof SVGElement) playIcon.toggleAttribute('hidden', active);
    if (pauseIcon instanceof SVGElement) pauseIcon.toggleAttribute('hidden', !active);
};

const stopSlowZoom = (resetZoom = false) => {
    if (slowZoomFrame !== null) {
        window.cancelAnimationFrame(slowZoomFrame);
        slowZoomFrame = null;
    }
    setSlowZoomButtonState(false);
    if (resetZoom) {
        zoom = minZoom;
        panX = 0;
        panY = 0;
        updateTransform();
    }
};

const updateTransform = () => {
    if (!image || !stage) return;
    const bounds = imageBounds();
    if (!bounds || !image.naturalWidth || !image.naturalHeight || !bounds.width || !bounds.height) return;

    const fitScale = Math.min(
        bounds.width / image.naturalWidth,
        bounds.height / image.naturalHeight,
        1,
    );
    const fittedWidth = image.naturalWidth * fitScale;
    const fittedHeight = image.naturalHeight * fitScale;
    if (image.style.width !== `${fittedWidth}px`) image.style.width = `${fittedWidth}px`;
    if (image.style.height !== `${fittedHeight}px`) image.style.height = `${fittedHeight}px`;
    const radians = (rotation * Math.PI) / 180;
    const rotatedWidth = Math.abs(fittedWidth * zoom * Math.cos(radians))
        + Math.abs(fittedHeight * zoom * Math.sin(radians));
    const rotatedHeight = Math.abs(fittedWidth * zoom * Math.sin(radians))
        + Math.abs(fittedHeight * zoom * Math.cos(radians));
    const maxX = Math.max(0, (rotatedWidth - bounds.width) / 2);
    const maxY = Math.max(0, (rotatedHeight - bounds.height) / 2);
    panX = Math.max(-maxX, Math.min(maxX, panX));
    panY = Math.max(-maxY, Math.min(maxY, panY));
    image.style.transform = `translate(${panX}px, ${panY}px) rotate(${rotation}deg) scale(${zoom})`;
    stage.classList.toggle('is-zoomed', zoom > minZoom);
    if (zoomLabel) zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
    if (zoomIn instanceof HTMLButtonElement) zoomIn.disabled = zoom >= maxZoom;
    if (zoomOut instanceof HTMLButtonElement) zoomOut.disabled = zoom <= minZoom;
    if (dimensionsLabel) {
        dimensionsLabel.textContent = `${image.naturalWidth} × ${image.naturalHeight} px`;
    }
};

const reset = () => {
    stopSlowZoom();
    zoom = minZoom;
    panX = 0;
    panY = 0;
    rotation = 0;
    dragPointer = null;
    stage?.classList.remove('is-dragging');
    if (grayscale instanceof HTMLInputElement) grayscale.checked = false;
    if (image) image.style.filter = '';
    updateTransform();
};

const setZoom = (nextZoom, cursor = null) => {
    if (slowZoomFrame !== null) stopSlowZoom();
    const next = Math.max(minZoom, Math.min(maxZoom, nextZoom));
    if (next === zoom) return;

    if (cursor && stage) {
        const ratio = next / zoom;
        const offsetX = cursor.x - (stage.clientWidth / 2);
        const offsetY = cursor.y - (stage.clientHeight / 2);
        panX = offsetX * (1 - ratio) + panX * ratio;
        panY = offsetY * (1 - ratio) + panY * ratio;
    } else if (next === minZoom) {
        panX = 0;
        panY = 0;
    }

    zoom = next;
    updateTransform();
};

const inferImageExtension = (src, name, extension, mimeType) => {
    const suppliedExtension = extension?.trim().replace(/^\./, '');
    if (suppliedExtension) return suppliedExtension.toUpperCase();

    const nameExtension = name.match(/\.([a-z0-9]{1,10})$/i)?.[1];
    if (nameExtension) return nameExtension.toUpperCase();

    const mimeExtensions = {
        'image/avif': 'AVIF',
        'image/bmp': 'BMP',
        'image/gif': 'GIF',
        'image/heic': 'HEIC',
        'image/heif': 'HEIF',
        'image/jpg': 'JPG',
        'image/jpeg': 'JPG',
        'image/jp2': 'JP2',
        'image/png': 'PNG',
        'image/svg+xml': 'SVG',
        'image/tiff': 'TIF',
        'image/vnd.microsoft.icon': 'ICO',
        'image/webp': 'WEBP',
        'image/x-icon': 'ICO',
        'image/x-ms-bmp': 'BMP',
    };
    if (mimeType && mimeExtensions[mimeType.toLowerCase()]) {
        return mimeExtensions[mimeType.toLowerCase()];
    }

    try {
        const urlExtension = new URL(src, window.location.href).pathname.match(/\.([a-z0-9]{1,10})$/i)?.[1];
        if (urlExtension) return urlExtension.toUpperCase();
    } catch (error) {
        if (!(error instanceof TypeError)) throw error;
    }

    return 'UNKNOWN';
};

const startSlowZoom = () => {
    if (!dialog?.open || !image?.naturalWidth) return;

    zoom = minZoom;
    panX = 0;
    panY = 0;
    updateTransform();
    setSlowZoomButtonState(true);
    const startedAt = performance.now();

    const animate = (now) => {
        if (!dialog?.open || !image?.naturalWidth) {
            stopSlowZoom();
            return;
        }

        const elapsed = (now - startedAt) % slowZoomCycleDuration;
        const progress = elapsed < slowZoomInDuration
            ? elapsed / slowZoomInDuration
            : 1 - (elapsed - slowZoomInDuration) / (slowZoomCycleDuration - slowZoomInDuration);
        const easedProgress = progress * progress * (3 - 2 * progress);
        zoom = minZoom + ((slowZoomMaximum - minZoom) * easedProgress);
        updateTransform();
        slowZoomFrame = window.requestAnimationFrame(animate);
    };

    slowZoomFrame = window.requestAnimationFrame(animate);
};

export function openImageViewer({
    src,
    downloadUrl = src,
    name = 'Image preview',
    extension,
    mimeType,
    returnFocus = document.activeElement,
}) {
    refreshViewer();
    if (!(dialog instanceof HTMLDialogElement) || !(image instanceof HTMLImageElement) || !src) return;

    lastTrigger = returnFocus instanceof HTMLElement ? returnFocus : null;
    if (dimensionsLabel) dimensionsLabel.textContent = 'Loading…';
    if (extensionLabel) extensionLabel.textContent = inferImageExtension(src, name, extension, mimeType);
    image.src = src;
    image.alt = name;
    if (title) title.textContent = name;
    if (download instanceof HTMLAnchorElement) {
        download.href = downloadUrl;
        download.download = name;
        setDownloadBusy(download, false);
    }
    reset();
    if (!dialog.open) dialog.showModal();
    requestAnimationFrame(updateTransform);
}

const close = () => {
    window.clearTimeout(downloadBusyTimer);
    downloadBusyTimer = null;
    image?.removeAttribute('src');
    download?.removeAttribute('href');
    setDownloadBusy(download, false);
    reset();
    lastTrigger?.focus();
    lastTrigger = null;
};

document.addEventListener('click', async (event) => {
    if (!(event.target instanceof Element)) return;
    refreshViewer();
    if (!(dialog instanceof HTMLDialogElement) || !dialog.contains(event.target) && event.target !== dialog) return;

    if (event.target.closest('[data-vault-image-close]') || event.target === dialog) {
        dialog.close();
        return;
    }

    if (event.target.closest('[data-vault-image-zoom-in]')) {
        setZoom(zoom + zoomStep);
        return;
    }
    if (event.target.closest('[data-vault-image-zoom-out]')) {
        setZoom(zoom - zoomStep);
        return;
    }
    if (event.target.closest('[data-vault-image-slow-zoom]')) {
        if (slowZoomFrame !== null) {
            stopSlowZoom(true);
        } else {
            startSlowZoom();
        }
        return;
    }
    if (event.target.closest('[data-vault-image-rotate]')) {
        rotation = (rotation + 90) % 360;
        updateTransform();
        return;
    }

    const downloadLink = event.target.closest('[data-vault-image-download]');
    if (!(downloadLink instanceof HTMLAnchorElement)) return;
    if (!(grayscale instanceof HTMLInputElement) || !grayscale.checked || !image?.src) {
        setDownloadBusy(downloadLink, true);
        window.clearTimeout(downloadBusyTimer);
        downloadBusyTimer = window.setTimeout(() => setDownloadBusy(downloadLink, false), 1800);
        return;
    }

    event.preventDefault();
    const sourceImage = image;
    window.clearTimeout(downloadBusyTimer);
    setDownloadBusy(downloadLink, true);

    try {
        await sourceImage.decode();
        const canvas = document.createElement('canvas');
        canvas.width = sourceImage.naturalWidth;
        canvas.height = sourceImage.naturalHeight;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Could not prepare the grayscale image for download.');
        context.filter = 'grayscale(1)';
        context.drawImage(sourceImage, 0, 0);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('Could not create the grayscale image download.');

        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        const baseName = (title?.textContent || 'image')
            .replace(/\.[^.]+$/, '')
            .replace(/[<>:"/\\|?*\u0000-\u001f]/g, '_')
            .trim() || 'image';
        anchor.href = url;
        anchor.download = `${baseName}-grayscale.png`;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
    } catch (error) {
        window.vaultAlert?.({
            type: 'error',
            message: error instanceof Error ? error.message : 'Could not prepare the grayscale image download.',
        });
    } finally {
        setDownloadBusy(downloadLink, false);
    }
});

document.addEventListener('change', (event) => {
    refreshViewer();
    if (event.target === grayscale && image && grayscale instanceof HTMLInputElement) {
        image.style.filter = grayscale.checked ? 'grayscale(1)' : '';
    }
});

document.addEventListener('wheel', (event) => {
    refreshViewer();
    if (!stage || !dialog?.open || !image?.naturalWidth || !stage.contains(event.target)) return;
    event.preventDefault();
    if (slowZoomFrame !== null) stopSlowZoom();
    const rect = stage.getBoundingClientRect();
    const factor = event.deltaY < 0 ? 1 + zoomStep : 1 / (1 + zoomStep);
    setZoom(zoom * factor, { x: event.clientX - rect.left, y: event.clientY - rect.top });
}, { passive: false });

document.addEventListener('pointerdown', (event) => {
    refreshViewer();
    if (zoom <= minZoom || event.target !== image || !(stage instanceof HTMLElement) || event.button !== 0) return;
    if (slowZoomFrame !== null) stopSlowZoom();
    dragPointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
    stage.setPointerCapture(event.pointerId);
    stage.classList.add('is-dragging');
    event.preventDefault();
});
document.addEventListener('pointermove', (event) => {
    refreshViewer();
    if (!dragPointer || event.pointerId !== dragPointer.id) return;
    panX += event.clientX - dragPointer.x;
    panY += event.clientY - dragPointer.y;
    dragPointer.x = event.clientX;
    dragPointer.y = event.clientY;
    updateTransform();
});
document.addEventListener('pointerup', (event) => {
    refreshViewer();
    if (!dragPointer || event.pointerId !== dragPointer.id || !(stage instanceof HTMLElement)) return;
    dragPointer = null;
    stage.classList.remove('is-dragging');
    if (stage.hasPointerCapture(event.pointerId)) stage.releasePointerCapture(event.pointerId);
});
document.addEventListener('pointercancel', () => {
    refreshViewer();
    dragPointer = null;
    stage?.classList.remove('is-dragging');
});
document.addEventListener('load', (event) => {
    refreshViewer();
    if (event.target === image) {
        requestAnimationFrame(updateTransform);
        if (slowZoomButton?.getAttribute('aria-pressed') === 'true' && slowZoomFrame === null) {
            startSlowZoom();
        }
    }
}, true);
document.addEventListener('livewire:navigated', refreshViewer);
window.addEventListener('resize', updateTransform);
refreshViewer();
