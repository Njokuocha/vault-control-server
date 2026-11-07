const formSelector = '[data-record-form-submit]';

const clearErrors = (form) => {
    form.querySelectorAll('.record-form-server-error').forEach((error) => error.remove());
    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => field.removeAttribute('aria-invalid'));
};

const errorTarget = (form, fieldName) => {
    const rootName = fieldName.split('.')[0];
    const fields = [...form.elements];
    const field = fields.find((candidate) => (
        candidate.name === rootName
        || candidate.name === `${rootName}[]`
        || candidate.name.startsWith(`${rootName}[`)
    ));

    if (field) {
        field.setAttribute('aria-invalid', 'true');
        return field.closest('.record-field')
            ?? field.closest('[data-main-image-uploader]')
            ?? field.closest('[data-person-gallery-upload-list]')
            ?? field.closest('[data-case-category]')
            ?? field.parentElement;
    }

    if (rootName.includes('main_image')) return form.querySelector('[data-main-image-uploader]');
    if (rootName.includes('gallery')) return form.querySelector('[data-person-gallery-upload-list]');
    return form.querySelector('.record-fields-grid') ?? form;
};

const showValidationErrors = (form, errors) => {
    clearErrors(form);
    Object.entries(errors).forEach(([fieldName, messages]) => {
        const target = errorTarget(form, fieldName);
        if (!target) return;

        const error = document.createElement('p');
        error.className = 'field-error record-form-server-error';
        error.setAttribute('role', 'alert');
        error.textContent = Array.isArray(messages) ? messages.join(' ') : String(messages);
        target.append(error);
    });
};

const responsePayload = async (response) => {
    try {
        return await response.json();
    } catch {
        return {};
    }
};

const submitForm = async (form) => {
    if (form.dataset.submitting === 'true') return;
    clearErrors(form);
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');

    const submitButton = form.querySelector('button[type="submit"]');
    const wasDisabled = submitButton instanceof HTMLButtonElement ? submitButton.disabled : false;
    const originalButtonText = submitButton instanceof HTMLButtonElement ? submitButton.textContent : null;
    if (submitButton instanceof HTMLButtonElement) {
        submitButton.disabled = true;
        submitButton.textContent = form.dataset.submittingLabel ?? 'SAVING…';
    }

    try {
        await window.vaultRecordUploadsReady?.(form);

        const response = await fetch(form.action, {
            method: form.method.toUpperCase(),
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            credentials: 'same-origin',
            body: new FormData(form),
        });

        if (response.status === 419) {
            window.vaultAlert({
                type: 'error',
                message: 'Your session expired. Reload the page, then submit the form again.',
            });
            return;
        }

        const payload = await responsePayload(response);
        if (response.status === 422) {
            const errors = payload.errors ?? {};
            showValidationErrors(form, errors);
            window.vaultAlert({
                type: 'warning',
                message: payload.message ?? 'Please correct the highlighted fields and try again.',
            });
            return;
        }

        if (!response.ok) {
            window.vaultAlert({
                type: 'error',
                message: response.status >= 500
                    ? 'The record could not be saved because of a server error. Your uploads remain in the tracker.'
                    : payload.message ?? 'The record could not be saved. Please try again.',
            });
            return;
        }

        if (typeof payload.redirect_url !== 'string') {
            throw new Error('The server saved the record but did not return its destination.');
        }

        window.vaultRecordUploadsSubmitted?.(form, payload);
        form.dataset.submitting = 'false';
        form.dataset.dirty = 'false';
        if (form.dataset.recordDraftStorageKey) {
            try {
                sessionStorage.removeItem(form.dataset.recordDraftStorageKey);
            } catch (error) {
                console.error('Unable to clear the saved record-upload draft token.', error);
            }
        }
        if (typeof window.Livewire?.navigate === 'function') {
            try {
                sessionStorage.setItem('vault.record-form-success', payload.message ?? 'Record saved.');
            } catch (error) {
                console.error('Unable to persist the record-save notification.', error);
                window.vaultAlert({ type: 'success', message: payload.message ?? 'Record saved.' });
            }
            window.Livewire.navigate(payload.redirect_url);
        } else {
            window.vaultAlert({ type: 'success', message: payload.message ?? 'Record saved.' });
            window.location.assign(payload.redirect_url);
        }
    } catch (error) {
        console.error('Unable to submit the record form.', error);
        window.vaultAlert({
            type: 'error',
            message: error instanceof Error ? error.message : 'The form could not be submitted. Please try again.',
        });
    } finally {
        form.dataset.submitting = 'false';
        form.removeAttribute('aria-busy');
        if (submitButton instanceof HTMLButtonElement) {
            submitButton.disabled = wasDisabled;
            if (originalButtonText !== null) submitButton.textContent = originalButtonText;
        }
    }
};

document.addEventListener('submit', (event) => {
    const form = event.target.closest(formSelector);
    if (!(form instanceof HTMLFormElement)) return;
    event.preventDefault();
    void submitForm(form);
});

document.addEventListener('input', (event) => {
    const form = event.target.closest(formSelector);
    if (form instanceof HTMLFormElement) form.dataset.dirty = 'true';
});

document.addEventListener('change', (event) => {
    const form = event.target.closest(formSelector);
    if (form instanceof HTMLFormElement) form.dataset.dirty = 'true';
});

document.addEventListener('livewire:navigated', () => {
    let message;
    try {
        message = sessionStorage.getItem('vault.record-form-success');
        sessionStorage.removeItem('vault.record-form-success');
    } catch (error) {
        console.error('Unable to read the record-save notification.', error);
    }
    if (message) window.vaultAlert({ type: 'success', message });
});

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;
    const link = event.target.closest('a[wire\\:navigate]');
    const form = document.querySelector(formSelector);
    if (!(link instanceof HTMLAnchorElement) || !(form instanceof HTMLFormElement)) return;
    if (form.dataset.submitting === 'true') return;

    const hasPendingAttachments = form.querySelector(
        '[name="main_image_upload_token"], [name="gallery_upload_tokens[]"], [name*="[upload_transfer_tokens]"]',
    );
    if (form.dataset.dirty !== 'true' && !hasPendingAttachments) return;

    event.preventDefault();
    event.stopImmediatePropagation();
    const url = link.href;
    if (form.dataset.navigationConfirming === 'true') return;
    form.dataset.navigationConfirming = 'true';

    window.vaultConfirm({
        title: 'Leave this form?',
        message: 'Unsaved form changes will be lost. Any background uploads will continue in the tracker but will not be attached unless you save this record.',
        confirmLabel: 'LEAVE FORM',
    }).then((confirmed) => {
        if (confirmed) {
            if (typeof window.Livewire?.navigate === 'function') window.Livewire.navigate(url);
            else window.location.assign(url);
        }
    }).catch((error) => {
        console.error('Unable to confirm leaving the record form.', error);
        window.vaultAlert({ type: 'error', message: 'Unable to confirm navigation. Please try again.' });
    }).finally(() => {
        form.dataset.navigationConfirming = 'false';
    });
}, true);
