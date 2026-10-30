const updateFreezeCountdowns = () => {
    document.querySelectorAll('[data-freeze-until]').forEach((element) => {
        const remaining = Number(element.dataset.freezeUntil) - Date.now();
        const output = element.querySelector('[data-freeze-countdown]');
        if (!output) return;

        if (remaining <= 0) {
            output.textContent = 'Expiring…';
            if (element.dataset.expiryRefreshRequested === 'true') return;

            const root = element.closest('[wire\\:id]');
            const componentId = root?.getAttribute('wire:id');
            const method = element.dataset.freezeRefreshMethod;
            const component = componentId ? window.Livewire?.find(componentId) : null;
            if (component && method) {
                element.dataset.expiryRefreshRequested = 'true';
                component.call(method);
            }
            return;
        }

        const totalSeconds = Math.ceil(remaining / 1000);
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        output.textContent = `${days ? `${days}d ` : ''}${String(hours).padStart(2, '0')}h ${String(minutes).padStart(2, '0')}m ${String(seconds).padStart(2, '0')}s`;
    });
};

const photoUploadStatus = (input) => {
    const picker = input.closest('[data-staff-photo-picker]');
    const status = picker?.querySelector('[data-staff-photo-upload-status]');
    const message = status?.querySelector('[data-staff-photo-upload-message]');
    const spinner = status?.querySelector('[data-staff-photo-upload-spinner]');
    return status && message ? { status, message, spinner } : null;
};

const setPhotoUploadStatus = (input, state, text) => {
    const parts = photoUploadStatus(input);
    if (!parts) return;

    parts.status.hidden = false;
    parts.status.classList.toggle('is-ready', state === 'ready');
    parts.status.classList.toggle('is-error', state === 'error');
    parts.message.textContent = text;
    if (parts.spinner) parts.spinner.hidden = state !== 'uploading';
};

const setStaffFormSubmitting = (form, submitting) => {
    const button = form.querySelector('[data-staff-submit-button]');
    const label = button?.querySelector('[data-staff-submit-label]');
    const loading = button?.querySelector('[data-staff-submit-loading]');
    if (!button || !label || !loading) return;

    button.disabled = submitting;
    label.hidden = submitting;
    loading.hidden = !submitting;
};

const normalizeFreezeHours = (value, finalize = false) => {
    const filtered = value.replace(/[^\d.]/g, '');
    const decimalIndex = filtered.indexOf('.');
    let whole = decimalIndex === -1 ? filtered : filtered.slice(0, decimalIndex);
    let fraction = decimalIndex === -1 ? '' : filtered.slice(decimalIndex + 1).replace(/\./g, '').slice(0, 2);

    whole = whole.replace(/^0+(?=\d)/, '');
    if (decimalIndex !== -1 && whole === '') whole = '0';
    if (fraction.length > 1 || finalize) fraction = fraction.replace(/0+$/, '');

    return decimalIndex === -1
        ? whole
        : fraction === '' && finalize
            ? whole
            : `${whole}.${fraction}`;
};

document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-freeze-hours-input]')) return;

    const cursor = input.selectionStart;
    const normalized = normalizeFreezeHours(input.value);
    if (normalized !== input.value) {
        const normalizedCursor = normalizeFreezeHours(input.value.slice(0, cursor ?? input.value.length)).length;
        input.value = normalized;
        input.setSelectionRange(normalizedCursor, normalizedCursor);
    }
}, true);

document.addEventListener('blur', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-freeze-hours-input]')) return;

    input.value = normalizeFreezeHours(input.value, true);
}, true);

document.addEventListener('change', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement) || !input.matches('[data-staff-photo-input]')) return;

    const file = input.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    setPhotoUploadStatus(input, 'ready', 'Selected · image will upload when you save the account.');

    const picker = input.closest('[data-staff-photo-picker]');
    const frame = picker?.querySelector('[data-staff-photo-preview-frame]');
    if (!frame) return;

    const previousPreview = frame.querySelector('[data-staff-photo-preview]');
    if (previousPreview?.dataset.previewUrl) {
        URL.revokeObjectURL(previousPreview.dataset.previewUrl);
    }

    const image = document.createElement('img');
    image.className = 'agency-staff-photo-preview';
    image.alt = 'Selected staff profile picture';
    image.dataset.staffPhotoPreview = '';
    image.dataset.previewUrl = URL.createObjectURL(file);
    image.src = image.dataset.previewUrl;
    frame.replaceChildren(image);
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-staff-account-form]')) return;

    event.preventDefault();
    if (form.dataset.submitting === 'true') return;

    const root = form.closest('[wire\\:id]');
    const componentId = root?.getAttribute('wire:id');
    const component = componentId ? window.Livewire?.find(componentId) : null;
    if (!component) {
        window.vaultAlert?.({ type: 'error', message: 'Unable to save the staff account. Refresh the page and try again.' });
        return;
    }

    form.dataset.submitting = 'true';
    setStaffFormSubmitting(form, true);
    const input = form.querySelector('[data-staff-photo-input]');
    const file = input?.files?.[0];

    const saveAccount = () => component.call('save')
        .catch((error) => {
            console.error('Staff account save failed.', error);
            window.vaultAlert?.({ type: 'error', message: 'Unable to save the staff account. Check the form and try again.' });
        })
        .finally(() => {
            form.dataset.submitting = 'false';
            setStaffFormSubmitting(form, false);
            if (input?.files?.length) {
                setPhotoUploadStatus(input, 'ready', 'Selected · image will upload when you save the account.');
            }
        });

    if (!file) {
        saveAccount();
        return;
    }

    setPhotoUploadStatus(input, 'uploading', 'Saving account and uploading image…');
    component.upload(
        'profilePhoto',
        file,
        saveAccount,
        () => {
            form.dataset.submitting = 'false';
            setStaffFormSubmitting(form, false);
            setPhotoUploadStatus(input, 'error', 'Image upload failed. Please try again.');
        },
    );
});

window.addEventListener('staff-account-saved', () => {
    document.querySelectorAll('[data-staff-photo-input]').forEach((input) => {
        if (!(input instanceof HTMLInputElement)) return;
        input.value = '';
        const picker = input.closest('[data-staff-photo-picker]');
        const frame = picker?.querySelector('[data-staff-photo-preview-frame]');
        if (!frame) return;

        const preview = frame.querySelector('[data-staff-photo-preview]');
        if (preview?.dataset.previewUrl) URL.revokeObjectURL(preview.dataset.previewUrl);
        const placeholder = document.createElement('span');
        placeholder.className = 'agency-staff-photo-preview-placeholder';
        placeholder.setAttribute('aria-label', 'No profile image selected');
        placeholder.innerHTML = '<i class="ri ri-user-3-line" aria-hidden="true"></i>';
        frame.replaceChildren(placeholder);

        const status = picker.querySelector('[data-staff-photo-upload-status]');
        if (status) status.hidden = true;
    });
});

window.setInterval(updateFreezeCountdowns, 1000);
document.addEventListener('livewire:navigated', updateFreezeCountdowns);
document.addEventListener('DOMContentLoaded', updateFreezeCountdowns);
