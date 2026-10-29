function editorFor(element) {
    return element.closest('[data-admin-mail-composer]')?.querySelector('[data-admin-mail-editor]');
}

function syncEditor(editor) {
    const form = editor.closest('[data-admin-mail-composer]');
    const state = form?.querySelector('[data-admin-mail-editor-state]');
    if (!state) return;

    state.value = editor.innerHTML;
    state.dispatchEvent(new Event('input', { bubbles: true }));
}

function initializeEditors() {
    document.querySelectorAll('[data-admin-mail-editor]').forEach((editor) => {
        if (editor.dataset.initialized === 'true') return;

        const state = editor.closest('[data-admin-mail-composer]')?.querySelector('[data-admin-mail-editor-state]');
        if (state?.value) editor.innerHTML = state.value;
        editor.dataset.initialized = 'true';
    });
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-admin-mail-command]');
    if (button) {
        const editor = editorFor(button);
        if (!editor) return;

        editor.focus();
        const command = button.dataset.adminMailCommand;
        if (command === 'formatBlock') {
            document.execCommand(command, false, button.dataset.adminMailValue);
        } else {
            document.execCommand(command, false);
        }
        syncEditor(editor);
        return;
    }

    const resetButton = event.target.closest('[data-admin-mail-reset]');
    if (resetButton) {
        const form = resetButton.closest('[data-admin-mail-composer]');
        const editor = form?.querySelector('[data-admin-mail-editor]');
        const state = form?.querySelector('[data-admin-mail-editor-state]');
        if (editor && state) {
            editor.innerHTML = '';
            state.value = '';
            state.dispatchEvent(new Event('input', { bubbles: true }));
        }
    }
});

document.addEventListener('mousedown', (event) => {
    if (event.target.closest('[data-admin-mail-command]')) {
        event.preventDefault();
    }
});

document.addEventListener('input', (event) => {
    if (event.target.matches('[data-admin-mail-editor]')) {
        syncEditor(event.target);
    }
});

document.addEventListener('admin-mail-editor-reset', () => {
    const editor = document.querySelector('[data-admin-mail-editor]');
    const state = document.querySelector('[data-admin-mail-editor-state]');
    if (editor && state) {
        editor.innerHTML = '';
        state.value = '';
        state.dispatchEvent(new Event('input', { bubbles: true }));
    }
});

function updateOtpCountdown() {
    const modal = document.querySelector('[data-otp-expiry]');
    if (!modal) return;

    const expiresAt = Number.parseInt(modal.dataset.otpExpiry ?? '0', 10);
    const remaining = Math.max(0, expiresAt - Math.floor(Date.now() / 1000));
    const countdown = modal.querySelector('[data-otp-countdown]');
    const input = modal.querySelector('[name="otpCode"]');
    const button = modal.querySelector('[type="submit"]');

    if (!modal.dataset.focused) {
        input?.focus();
        modal.dataset.focused = 'true';
    }

    if (remaining === 0) {
        if (countdown) countdown.textContent = 'EXPIRED';
        if (input) input.disabled = true;
        if (button) button.disabled = true;
        modal.querySelector('[data-otp-expired]')?.removeAttribute('hidden');
        return;
    }

    if (countdown) {
        const minutes = Math.floor(remaining / 60).toString().padStart(2, '0');
        const seconds = (remaining % 60).toString().padStart(2, '0');
        countdown.textContent = `${minutes}:${seconds}`;
    }
}

document.addEventListener('livewire:navigated', initializeEditors);
document.addEventListener('DOMContentLoaded', initializeEditors);
document.addEventListener('livewire:init', initializeEditors);
window.setInterval(updateOtpCountdown, 1000);
initializeEditors();
