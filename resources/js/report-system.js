const previewObjectUrls = new WeakMap();

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-report-files]')) return;

    const previewArea = input.closest('form')?.querySelector('[data-report-previews]');
    if (!(previewArea instanceof HTMLElement)) return;

    for (const url of previewObjectUrls.get(input) ?? []) URL.revokeObjectURL(url);
    const urls = [];
    previewArea.replaceChildren();

    for (const file of Array.from(input.files ?? [])) {
        const card = document.createElement('span');
        card.className = 'report-upload-preview';
        const url = URL.createObjectURL(file);
        urls.push(url);

        if (file.type.startsWith('image/')) {
            const image = document.createElement('img');
            image.src = url;
            image.alt = `Preview of ${file.name}`;
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
});

document.addEventListener('livewire:navigating', () => {
    document.querySelectorAll('[data-report-files]').forEach((input) => {
        for (const url of previewObjectUrls.get(input) ?? []) URL.revokeObjectURL(url);
    });
});
