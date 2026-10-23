import './bootstrap';

const clock = document.querySelector('[data-clock]');
const date = document.querySelector('[data-date]');
const search = document.querySelector('[data-search]');
const sidebar = document.querySelector('#sidebar');
const menuToggle = document.querySelector('[data-menu-toggle]');
const menuClose = document.querySelector('[data-menu-close]');
const toastRegion = document.querySelector('[data-toast]');

const showToast = (message) => {
    if (!toastRegion) return;

    const toast = document.createElement('div');
    toast.className = 'toast';
    toast.textContent = message;
    toastRegion.append(toast);
    window.setTimeout(() => toast.remove(), 3200);
};

const updateTime = () => {
    const now = new Date();

    if (clock) {
        clock.textContent = new Intl.DateTimeFormat(undefined, {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false,
        }).format(now);
    }

    if (date) {
        date.textContent = new Intl.DateTimeFormat(undefined, {
            year: 'numeric',
            month: 'long',
            day: '2-digit',
        }).format(now);
    }
};

const setMenuOpen = (isOpen) => {
    sidebar?.classList.toggle('is-open', isOpen);
    menuClose?.classList.toggle('is-visible', isOpen);
    menuToggle?.setAttribute('aria-expanded', String(isOpen));
};

updateTime();
window.setInterval(updateTime, 1000);

menuToggle?.addEventListener('click', () => {
    setMenuOpen(menuToggle.getAttribute('aria-expanded') !== 'true');
});
menuClose?.addEventListener('click', () => setMenuOpen(false));

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        setMenuOpen(false);
    }

    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        const target = event.target;
        const isTyping = target instanceof HTMLElement
            && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

        if (!isTyping) {
            event.preventDefault();
            search?.focus();
        }
    }
});

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.querySelector('#access-code');
        if (!(input instanceof HTMLInputElement)) return;

        const showPassword = input.type === 'password';
        input.type = showPassword ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(showPassword));
        button.setAttribute('aria-label', showPassword ? 'Hide access code' : 'Show access code');
    });
});

const loginForm = document.querySelector('[data-login-form]');
loginForm?.addEventListener('submit', () => {
    const submit = document.querySelector('[data-login-submit]');
    const label = document.querySelector('[data-login-label]');
    const progress = document.querySelector('[data-login-progress]');

    if (!(submit instanceof HTMLButtonElement) || !label || !progress) return;

    submit.disabled = true;
    submit.classList.add('is-loading');
    label.textContent = 'VERIFYING';
    progress.textContent = 'Verifying credentials…';

    window.setTimeout(() => {
        progress.textContent = 'Establishing secure session…';
    }, 900);
});

document.querySelector('[data-generate-key]')?.addEventListener('click', () => {
    const input = document.querySelector('#upload-key');

    if (!(input instanceof HTMLInputElement)) return;

    if (!window.crypto?.getRandomValues) {
        showToast('Secure key generation is unavailable in this browser.');
        return;
    }

    const bytes = window.crypto.getRandomValues(new Uint8Array(48));
    const encoded = window.btoa(String.fromCharCode(...bytes))
        .replaceAll('+', '-')
        .replaceAll('/', '_')
        .replace(/=+$/, '');

    input.value = encoded;
    input.focus();
    showToast('A secure key was generated. Save it to rotate the active key.');
});

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        const targetId = button.getAttribute('data-copy-target');
        const target = targetId ? document.getElementById(targetId) : null;

        if (!target) {
            showToast('Could not find the text to copy.');
            return;
        }

        try {
            await navigator.clipboard.writeText(target.textContent?.trim() ?? '');
            showToast('Copied to clipboard.');
        } catch {
            showToast('Clipboard access was denied. Select and copy the text manually.');
        }
    });
});
