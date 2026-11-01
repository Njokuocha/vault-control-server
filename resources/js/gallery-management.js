function setFormBusy(form, busy) {
    form.setAttribute('aria-busy', String(busy));
    form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = busy;
    });
    form.querySelectorAll('[data-action-label]').forEach((label) => {
        if (busy) {
            label.dataset.originalLabel = label.textContent ?? '';
            label.textContent = 'SAVING…';
        } else if (label.dataset.originalLabel) {
            label.textContent = label.dataset.originalLabel;
            delete label.dataset.originalLabel;
        }
    });
    form.querySelectorAll('.gallery-action-spinner').forEach((spinner) => {
        spinner.hidden = !busy;
    });
    const error = form.querySelector('[data-action-error]');
    if (error instanceof HTMLElement) {
        error.hidden = true;
        error.textContent = '';
    }
}

function showFormError(form, message) {
    const error = form.querySelector('[data-action-error]');
    if (error instanceof HTMLElement) {
        error.textContent = message;
        error.hidden = false;
    }
}

function announceGalleryAccess(type, message) {
    if (typeof window.vaultAlert === 'function') {
        window.vaultAlert({ type, message });
    } else {
        console.error('Gallery access notification is unavailable.', message);
    }
}

function accessError(dialog, message) {
    const error = dialog.querySelector('[data-gallery-access-error]');
    if (error instanceof HTMLElement) {
        error.textContent = message;
        error.hidden = false;
    }
}

function accessHeaders() {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        'X-Requested-With': 'XMLHttpRequest',
    };
}

function updateAccessDialogState(dialog, locked) {
    const panel = dialog.querySelector('[data-gallery-access-staff-panel]');
    const note = dialog.querySelector('[data-gallery-access-unlocked-note]');
    const label = dialog.querySelector('[data-gallery-lock-label]');
    if (panel instanceof HTMLElement) panel.hidden = !locked;
    if (note instanceof HTMLElement) note.hidden = locked;
    if (label instanceof HTMLElement) label.textContent = locked ? 'Locked' : 'Unlocked';
}

function renderStaffCard(staff, dialog) {
    const card = document.createElement('article');
    card.className = 'gallery-access-staff-card';
    card.dataset.staffId = String(staff.id);

    const identity = document.createElement('div');
    identity.className = 'gallery-access-staff-identity';
    if (typeof staff.photo_url === 'string') {
        const photo = document.createElement('img');
        photo.src = staff.photo_url;
        photo.alt = '';
        photo.loading = 'lazy';
        photo.onerror = () => photo.remove();
        identity.append(photo);
    }
    const initials = document.createElement('span');
    initials.className = 'gallery-access-staff-initials';
    initials.textContent = (staff.name ?? '?').split(/\s+/).slice(0, 2).map((part) => part[0] ?? '').join('').toUpperCase();
    identity.append(initials);

    const info = document.createElement('span');
    info.className = 'gallery-access-staff-info';
    const name = document.createElement('strong');
    name.textContent = staff.name ?? 'Staff member';
    const username = document.createElement('small');
    username.textContent = staff.username ? `@${staff.username}` : '';
    info.append(name, username);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = staff.granted ? 'button-secondary' : 'button-primary';
    button.dataset.galleryAccessGrant = '';
    button.dataset.granted = String(Boolean(staff.granted));
    button.textContent = staff.granted ? 'REVOKE ACCESS' : 'GRANT ACCESS';
    button.addEventListener('click', () => void changeStaffGrant(dialog, card, button));
    card.append(identity, info, button);
    return card;
}

async function loadGalleryAccessStaff(dialog, query = '') {
    const list = dialog.querySelector('[data-gallery-access-staff-list]');
    if (!(list instanceof HTMLElement)) return;
    dialog._staffSearchController?.abort();
    const controller = new AbortController();
    dialog._staffSearchController = controller;
    list.replaceChildren();
    const loading = document.createElement('p');
    loading.className = 'gallery-access-loading';
    loading.textContent = 'Loading staff…';
    list.append(loading);

    try {
        const url = new URL(dialog.dataset.staffUrl, window.location.origin);
        if (query) url.searchParams.set('q', query);
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            signal: controller.signal,
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message ?? 'Staff could not be loaded.');
        if (dialog._staffSearchController !== controller) return;
        list.replaceChildren();
        if (!Array.isArray(payload.staff) || payload.staff.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'gallery-access-loading';
            empty.textContent = query ? 'No matching active staff were found.' : 'No active staff are available.';
            list.append(empty);
            return;
        }
        payload.staff.forEach((staff) => list.append(renderStaffCard(staff, dialog)));
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        console.error('Gallery access staff search failed.', error);
        list.replaceChildren();
        accessError(dialog, error instanceof Error ? error.message : 'Staff could not be loaded.');
    }
}

async function changeStaffGrant(dialog, card, button) {
    const granted = button.dataset.granted === 'true';
    const staffId = card.dataset.staffId;
    const template = granted ? dialog.dataset.revokeUrlTemplate : dialog.dataset.grantUrlTemplate;
    if (!template || !staffId) {
        accessError(dialog, 'The staff access endpoint is unavailable. Reload the page and try again.');
        return;
    }

    button.disabled = true;
    button.textContent = granted ? 'REVOKING…' : 'GRANTING…';
    try {
        const response = await fetch(template.replace('STAFF_ID', encodeURIComponent(staffId)), {
            method: granted ? 'DELETE' : 'POST',
            credentials: 'same-origin',
            headers: accessHeaders(),
            body: JSON.stringify({}),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message ?? 'Staff access could not be updated.');
        button.dataset.granted = String(payload.granted);
        button.className = payload.granted ? 'button-secondary' : 'button-primary';
        button.textContent = payload.granted ? 'REVOKE ACCESS' : 'GRANT ACCESS';
        announceGalleryAccess('success', payload.message);
    } catch (error) {
        console.error('Gallery staff access update failed.', error);
        button.textContent = granted ? 'REVOKE ACCESS' : 'GRANT ACCESS';
        announceGalleryAccess('error', error instanceof Error ? error.message : 'Staff access could not be updated.');
        accessError(dialog, error instanceof Error ? error.message : 'Staff access could not be updated.');
    } finally {
        button.disabled = false;
    }
}

async function updateGalleryLock(dialog, locked) {
    const toggle = dialog.querySelector('[data-gallery-lock-toggle]');
    if (!(toggle instanceof HTMLInputElement) || !dialog.dataset.lockUrl) return;
    toggle.disabled = true;
    try {
        const response = await fetch(dialog.dataset.lockUrl, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: accessHeaders(),
            body: JSON.stringify({ locked }),
        });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message ?? 'Category access could not be updated.');
        toggle.checked = payload.locked;
        updateAccessDialogState(dialog, payload.locked);
        if (payload.locked) {
            void loadGalleryAccessStaff(dialog);
        } else {
            const list = dialog.querySelector('[data-gallery-access-staff-list]');
            if (list instanceof HTMLElement) list.replaceChildren();
        }
        announceGalleryAccess('success', payload.message);
    } catch (error) {
        console.error('Gallery category lock update failed.', error);
        toggle.checked = !locked;
        announceGalleryAccess('error', error instanceof Error ? error.message : 'Category access could not be updated.');
        accessError(dialog, error instanceof Error ? error.message : 'Category access could not be updated.');
    } finally {
        toggle.disabled = false;
    }
}

async function submitGalleryAction(form) {
    if (form.dataset.confirm) {
        let confirmed;
        try {
            if (typeof window.vaultConfirm !== 'function') {
                throw new Error('The confirmation dialog is unavailable. Reload the page and try again.');
            }
            confirmed = await window.vaultConfirm({
                title: form.dataset.confirmTitle ?? 'Confirm category change',
                message: form.dataset.confirm,
                confirmLabel: form.dataset.confirmLabel ?? 'CONFIRM',
                destructive: form.dataset.confirmDestructive === 'true',
            });
        } catch (error) {
            console.error('Gallery management confirmation failed.', error);
            showFormError(form, error instanceof Error ? error.message : 'The change could not be confirmed.');
            return;
        }
        if (!confirmed) return;
    }

    setFormBusy(form, true);
    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const payload = await response.json();

        if (!response.ok) {
            const firstError = Object.values(payload.errors ?? {}).flat()[0];
            setFormBusy(form, false);
            showFormError(form, firstError ?? payload.message ?? 'The change could not be saved.');
            return;
        }

        if (typeof payload.redirect_url !== 'string') {
            throw new Error('The server did not return a gallery destination.');
        }
        if (typeof window.Livewire?.navigate === 'function') {
            window.Livewire.navigate(payload.redirect_url);
        } else {
            window.location.assign(payload.redirect_url);
        }
    } catch (error) {
        console.error('Gallery management submission failed.', error);
        setFormBusy(form, false);
        showFormError(form, error instanceof Error ? error.message : 'The change could not be saved.');
    }
}

document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element)) return;

    const editTrigger = event.target.closest('[data-open-gallery-edit]');
    if (editTrigger instanceof HTMLElement) {
        const dialog = document.querySelector('[data-gallery-edit-dialog]');
        if (dialog instanceof HTMLDialogElement) dialog.showModal();
        return;
    }

    const accessTrigger = event.target.closest('[data-gallery-access-open]');
    if (accessTrigger instanceof HTMLElement) {
        const dialog = document.querySelector('[data-gallery-access-dialog]');
        if (!(dialog instanceof HTMLDialogElement)) return;
        dialog.dataset.staffUrl = accessTrigger.dataset.staffUrl ?? '';
        dialog.dataset.lockUrl = accessTrigger.dataset.lockUrl ?? '';
        dialog.dataset.grantUrlTemplate = accessTrigger.dataset.grantUrlTemplate ?? '';
        dialog.dataset.revokeUrlTemplate = accessTrigger.dataset.revokeUrlTemplate ?? '';
        const categoryName = dialog.querySelector('[data-gallery-access-category-name]');
        if (categoryName instanceof HTMLElement) categoryName.textContent = accessTrigger.dataset.categoryName ?? '';
        const searchInput = dialog.querySelector('[data-gallery-access-search]');
        if (searchInput instanceof HTMLInputElement) searchInput.value = '';
        dialog._staffSearchController?.abort();
        const toggle = dialog.querySelector('[data-gallery-lock-toggle]');
        const locked = accessTrigger.dataset.categoryLocked === 'true';
        if (toggle instanceof HTMLInputElement) toggle.checked = locked;
        const error = dialog.querySelector('[data-gallery-access-error]');
        if (error instanceof HTMLElement) {
            error.hidden = true;
            error.textContent = '';
        }
        updateAccessDialogState(dialog, locked);
        dialog.showModal();
        if (locked) void loadGalleryAccessStaff(dialog);
        return;
    }

    const categoryTrigger = event.target.closest('[data-open-category-picker]');
    if (categoryTrigger instanceof HTMLButtonElement) {
        const selector = categoryTrigger.dataset.categoryDialog;
        const dialog = selector ? document.querySelector(selector) : null;
        const form = dialog?.querySelector('[data-category-picker-form]');
        if (!(dialog instanceof HTMLDialogElement) || !(form instanceof HTMLFormElement)) return;

        form.action = categoryTrigger.dataset.categoryAction ?? '#';
        const selected = new Set(JSON.parse(categoryTrigger.dataset.categoryIds ?? '[]').map(String));
        form.querySelectorAll('input[name="category_ids[]"]').forEach((input) => {
            if (input instanceof HTMLInputElement) input.checked = selected.has(input.value);
        });
        dialog.showModal();
        return;
    }

    const closeButton = event.target.closest('[data-gallery-dialog-close]');
    const dialog = event.target.closest('dialog[data-gallery-management-dialog]');
    if (closeButton instanceof HTMLElement && dialog instanceof HTMLDialogElement) {
        dialog.close();
    }
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-gallery-action-form], [data-case-category-form]')) return;

    event.preventDefault();
    void submitGalleryAction(form);
});

document.addEventListener('change', (event) => {
    const target = event.target;
    if (target instanceof HTMLInputElement && target.matches('[data-gallery-lock-toggle]')) {
        const dialog = target.closest('[data-gallery-access-dialog]');
        if (dialog instanceof HTMLDialogElement) void updateGalleryLock(dialog, target.checked);
    }
});

document.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement) || !target.matches('[data-gallery-access-search]')) return;
    const dialog = target.closest('[data-gallery-access-dialog]');
    if (!(dialog instanceof HTMLDialogElement)) return;
    window.clearTimeout(dialog._searchDebounce);
    dialog._searchDebounce = window.setTimeout(() => {
        void loadGalleryAccessStaff(dialog, target.value.trim());
    }, 220);
});

document.addEventListener('click', (event) => {
    const target = event.target;
    if (target instanceof HTMLDialogElement && target.matches('[data-gallery-category-dialog], [data-case-category-dialog]')) {
        const rect = target.getBoundingClientRect();
        const outside = event.clientX < rect.left
            || event.clientX > rect.right
            || event.clientY < rect.top
            || event.clientY > rect.bottom;
        if (outside) target.close();
    }
    if (target instanceof HTMLDialogElement && target.matches('[data-gallery-edit-dialog], [data-gallery-access-dialog]')) {
        const rect = target.getBoundingClientRect();
        const outside = event.clientX < rect.left
            || event.clientX > rect.right
            || event.clientY < rect.top
            || event.clientY > rect.bottom;
        if (outside) target.close();
    }
});
