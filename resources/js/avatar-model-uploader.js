const setStatus = (form, message, type = '') => {
    const status = form.querySelector('[data-glb-status]');
    if (!status) return;

    status.textContent = message;
    status.dataset.statusType = type;
    status.hidden = message === '';
};

const updateProgress = (form, progress) => {
    const progressElement = form.querySelector('[data-glb-progress]');
    const bar = progressElement?.querySelector('progress');
    const label = progressElement?.querySelector('[data-glb-progress-label]');
    if (!(bar instanceof HTMLProgressElement) || !(label instanceof HTMLElement)) return;

    bar.value = progress;
    label.textContent = `${progress}%`;
    progressElement.hidden = false;
};

const configurePreview = (preview, modelUrl) => {
    if (!(preview instanceof HTMLElement)) return;
    let viewer = preview.querySelector('[data-avatar-model-viewer]');
    if (!(viewer instanceof HTMLElement)) {
        viewer = document.createElement('model-viewer');
        viewer.dataset.avatarModelViewer = '';
        viewer.setAttribute('alt', `${preview.dataset.avatarPreview} avatar model preview`);
        viewer.setAttribute('auto-rotate', '');
        viewer.setAttribute('auto-rotate-delay', '0');
        viewer.setAttribute('rotation-per-second', '16deg');
        viewer.setAttribute('interaction-prompt', 'none');
        viewer.setAttribute('disable-tap', '');
        viewer.setAttribute('shadow-intensity', '0.5');
        viewer.setAttribute('exposure', '0.9');
        preview.querySelector('.avatar-model-empty')?.remove();
        preview.append(viewer);
    }

    viewer.setAttribute('src', modelUrl);
    const state = preview.querySelector('[data-avatar-model-state]');
    if (state) {
        state.classList.add('is-configured');
        state.innerHTML = '<i></i> MODEL CONFIGURED';
    }
}

const parseResponse = (xhr) => {
    try {
        return JSON.parse(xhr.responseText);
    } catch {
        return {};
    }
};

const submitModel = (form) => {
    if (form.dataset.submitting === 'true') return;

    const input = form.querySelector('[data-glb-input]');
    const file = input instanceof HTMLInputElement ? input.files?.[0] : null;
    if (!file) {
        setStatus(form, 'Select a GLB file before uploading.', 'error');
        return;
    }

    const submit = form.querySelector('[data-glb-submit]');
    const select = form.querySelector('[data-glb-select]');
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    setStatus(form, 'Preparing avatar model upload…');
    updateProgress(form, 0);
    if (submit instanceof HTMLButtonElement) {
        submit.disabled = true;
        submit.textContent = form.dataset.submittingLabel ?? 'UPLOADING MODEL…';
    }
    if (select instanceof HTMLButtonElement) select.disabled = true;

    const xhr = new XMLHttpRequest();
    xhr.open(form.method || 'POST', form.action);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (csrf) xhr.setRequestHeader('X-CSRF-TOKEN', csrf);
    xhr.upload.addEventListener('progress', (event) => {
        if (!event.lengthComputable) {
            setStatus(form, `Uploading ${file.name}…`);
            return;
        }

        const progress = Math.min(99, Math.round((event.loaded / event.total) * 100));
        updateProgress(form, progress);
        setStatus(form, `Uploading ${file.name}…`);
    });
    xhr.addEventListener('load', () => {
        const payload = parseResponse(xhr);
        if (xhr.status >= 200 && xhr.status < 300) {
            if (typeof payload.model_url !== 'string' || typeof payload.message !== 'string') {
                setStatus(form, 'The server saved the model but did not return preview details.', 'error');
                return;
            }

            updateProgress(form, 100);
            configurePreview(form.closest('.avatar-model-card')?.querySelector('[data-avatar-preview]'), payload.model_url);
            setStatus(form, payload.message, 'success');
            form.querySelector('[data-glb-file-name]').textContent = file.name;
            input.value = '';
            if (submit instanceof HTMLButtonElement) {
                submit.textContent = 'REPLACE MODEL';
            }
            return;
        }

        const validationMessage = payload.errors?.model?.[0];
        setStatus(
            form,
            typeof validationMessage === 'string'
                ? validationMessage
                : payload.message ?? 'The avatar model could not be uploaded. Please try again.',
            'error',
        );
    });
    xhr.addEventListener('error', () => setStatus(form, 'A network error interrupted the avatar model upload.', 'error'));
    xhr.addEventListener('abort', () => setStatus(form, 'The avatar model upload was cancelled.', 'error'));
    xhr.addEventListener('loadend', () => {
        form.dataset.submitting = 'false';
        form.removeAttribute('aria-busy');
        if (submit instanceof HTMLButtonElement) submit.disabled = false;
        if (select instanceof HTMLButtonElement) select.disabled = false;
    });
    xhr.send(new FormData(form));
};

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const button = event.target.closest('[data-glb-select]');
    const form = button?.closest('[data-glb-form]');
    const input = form?.querySelector('[data-glb-input]');
    if (input instanceof HTMLInputElement) input.click();
});

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-glb-input]')) return;

    const form = input.closest('[data-glb-form]');
    const label = form?.querySelector('[data-glb-file-name]');
    if (label) label.textContent = input.files?.[0]?.name ?? 'No file selected';
    if (form) setStatus(form, '');
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-glb-form]')) return;

    event.preventDefault();
    submitModel(form);
});
