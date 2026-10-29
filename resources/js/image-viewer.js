const dialog = document.querySelector('[data-vault-image-viewer]');
const image = dialog?.querySelector('[data-vault-image]');
const stage = dialog?.querySelector('[data-vault-image-stage]');
const title = dialog?.querySelector('[data-vault-image-title]');
const download = dialog?.querySelector('[data-vault-image-download]');
const zoomLabel = dialog?.querySelector('[data-vault-image-zoom-level]');
const zoomIn = dialog?.querySelector('[data-vault-image-zoom-in]');
const zoomOut = dialog?.querySelector('[data-vault-image-zoom-out]');
const grayscale = dialog?.querySelector('[data-vault-image-grayscale]');
const minZoom = 1;
const maxZoom = 15;
const zoomStep = 0.5;
let zoom = minZoom;
let panX = 0;
let panY = 0;
let dragPointer = null;
let lastTrigger = null;

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
    if (!(dialog instanceof HTMLDialogElement) || !(image instanceof HTMLImageElement) || !src) return;

    lastTrigger = returnFocus instanceof HTMLElement ? returnFocus : null;
    image.src = src;
    image.alt = name;
    if (title) title.textContent = name;
    if (download instanceof HTMLAnchorElement) {
        download.href = downloadUrl;
        download.download = name;
    }
    reset();
    if (!dialog.open) dialog.showModal();
    requestAnimationFrame(updateTransform);
}

const close = () => {
    image?.removeAttribute('src');
    download?.removeAttribute('href');
    reset();
    lastTrigger?.focus();
    lastTrigger = null;
};

dialog?.querySelector('[data-vault-image-close]')?.addEventListener('click', () => dialog.close());
dialog?.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
});
dialog?.addEventListener('close', close);
image?.addEventListener('load', () => requestAnimationFrame(updateTransform));
window.addEventListener('resize', updateTransform);

zoomIn?.addEventListener('click', () => setZoom(zoom + zoomStep));
zoomOut?.addEventListener('click', () => setZoom(zoom - zoomStep));
grayscale?.addEventListener('change', () => {
    if (image && grayscale instanceof HTMLInputElement) {
        image.style.filter = grayscale.checked ? 'grayscale(1)' : '';
    }
});

download?.addEventListener('click', async (event) => {
    if (!(grayscale instanceof HTMLInputElement) || !grayscale.checked || !image?.src) return;
    event.preventDefault();

    try {
        await image.decode();
        const canvas = document.createElement('canvas');
        canvas.width = image.naturalWidth;
        canvas.height = image.naturalHeight;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Could not prepare the grayscale image for download.');
        context.filter = 'grayscale(1)';
        context.drawImage(image, 0, 0);
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
    }
});

stage?.addEventListener('wheel', (event) => {
    if (!dialog?.open || !image?.naturalWidth) return;
    event.preventDefault();
    const rect = stage.getBoundingClientRect();
    const factor = event.deltaY < 0 ? 1 + zoomStep : 1 / (1 + zoomStep);
    setZoom(zoom * factor, { x: event.clientX - rect.left, y: event.clientY - rect.top });
}, { passive: false });

stage?.addEventListener('pointerdown', (event) => {
    if (zoom <= minZoom || event.target !== image || event.button !== 0) return;
    dragPointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
    stage.setPointerCapture(event.pointerId);
    stage.classList.add('is-dragging');
    event.preventDefault();
});
stage?.addEventListener('pointermove', (event) => {
    if (!dragPointer || event.pointerId !== dragPointer.id) return;
    panX += event.clientX - dragPointer.x;
    panY += event.clientY - dragPointer.y;
    dragPointer.x = event.clientX;
    dragPointer.y = event.clientY;
    updateTransform();
});
stage?.addEventListener('pointerup', (event) => {
    if (!dragPointer || event.pointerId !== dragPointer.id) return;
    dragPointer = null;
    stage.classList.remove('is-dragging');
    if (stage.hasPointerCapture(event.pointerId)) stage.releasePointerCapture(event.pointerId);
});
stage?.addEventListener('pointercancel', () => {
    dragPointer = null;
    stage.classList.remove('is-dragging');
});
