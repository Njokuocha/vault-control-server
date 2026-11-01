let dialog = null;
let image = null;
let stage = null;
let title = null;
let download = null;
let zoomLabel = null;
let zoomIn = null;
let zoomOut = null;
let grayscale = null;
const minZoom = 1;
const maxZoom = 15;
const zoomStep = 0.5;
let zoom = minZoom;
let panX = 0;
let panY = 0;
let dragPointer = null;
let lastTrigger = null;
const boundDialogs = new WeakSet();
let downloadBusyTimer = null;

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

const updateTransform = () => {
    if (!image || !stage) return;
    const bounds = imageBounds();
    if (!bounds || !image.naturalWidth || !image.naturalHeight || !bounds.width || !bounds.height) return;

    const fitScale = Math.min(
        bounds.width / image.naturalWidth,
        bounds.height / image.naturalHeight,
        1,
    );
    image.style.width = `${image.naturalWidth * fitScale}px`;
    image.style.height = `${image.naturalHeight * fitScale}px`;
    const fittedWidth = image.clientWidth;
    const fittedHeight = image.clientHeight;
    const maxX = Math.max(0, (fittedWidth * zoom - bounds.width) / 2);
    const maxY = Math.max(0, (fittedHeight * zoom - bounds.height) / 2);
    panX = Math.max(-maxX, Math.min(maxX, panX));
    panY = Math.max(-maxY, Math.min(maxY, panY));
    image.style.transform = `translate(${panX}px, ${panY}px) scale(${zoom})`;
    stage.classList.toggle('is-zoomed', zoom > minZoom);
    if (zoomLabel) zoomLabel.textContent = `${Math.round(zoom * 100)}%`;
    if (zoomIn instanceof HTMLButtonElement) zoomIn.disabled = zoom >= maxZoom;
    if (zoomOut instanceof HTMLButtonElement) zoomOut.disabled = zoom <= minZoom;
};

const reset = () => {
    zoom = minZoom;
    panX = 0;
    panY = 0;
    dragPointer = null;
    stage?.classList.remove('is-dragging');
    if (grayscale instanceof HTMLInputElement) grayscale.checked = false;
    if (image) image.style.filter = '';
    updateTransform();
};

const setZoom = (nextZoom, cursor = null) => {
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

export function openImageViewer({ src, downloadUrl = src, name = 'Image preview', returnFocus = document.activeElement }) {
    refreshViewer();
    if (!(dialog instanceof HTMLDialogElement) || !(image instanceof HTMLImageElement) || !src) return;

    lastTrigger = returnFocus instanceof HTMLElement ? returnFocus : null;
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
    const rect = stage.getBoundingClientRect();
    const factor = event.deltaY < 0 ? 1 + zoomStep : 1 / (1 + zoomStep);
    setZoom(zoom * factor, { x: event.clientX - rect.left, y: event.clientY - rect.top });
}, { passive: false });

document.addEventListener('pointerdown', (event) => {
    refreshViewer();
    if (zoom <= minZoom || event.target !== image || !(stage instanceof HTMLElement) || event.button !== 0) return;
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
    if (event.target === image) requestAnimationFrame(updateTransform);
}, true);
document.addEventListener('livewire:navigated', refreshViewer);
window.addEventListener('resize', updateTransform);
refreshViewer();
