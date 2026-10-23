import './bootstrap';
import { evaluateTriggerExpression } from './shell-math.mjs';

const clock = document.querySelector('[data-clock]');
const date = document.querySelector('[data-date]');
const search = document.querySelector('[data-search]');
const sidebar = document.querySelector('#sidebar');
const menuToggle = document.querySelector('[data-menu-toggle]');
const menuClose = document.querySelector('[data-menu-close]');
const toastRegion = document.querySelector('[data-toast]');
const appAcronym = document.querySelector('meta[name="application-acronym"]')?.content ?? 'VCS';

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

const archiveRecords = [...document.querySelectorAll('[data-archive-record]')];
const archiveMonths = [...document.querySelectorAll('[data-archive-month]')];
const archiveCount = document.querySelector('[data-archive-count]');
const archiveFilterEmpty = document.querySelector('[data-filter-empty]');
let activeArchiveFilter = 'all';

const filterArchive = () => {
    const query = search?.value.trim().toLocaleLowerCase() ?? '';
    let visibleCount = 0;

    archiveRecords.forEach((record) => {
        const matchesFilter = activeArchiveFilter === 'all'
            || record.dataset.archiveCategory === activeArchiveFilter;
        const matchesSearch = (record.dataset.archiveSearch ?? '').includes(query);
        const visible = matchesFilter && matchesSearch;
        record.hidden = !visible;
        if (visible) visibleCount += 1;
    });

    archiveMonths.forEach((month) => {
        month.hidden = !month.querySelector('[data-archive-record]:not([hidden])');
    });
    if (archiveCount) archiveCount.textContent = String(visibleCount).padStart(2, '0');
    if (archiveFilterEmpty) archiveFilterEmpty.hidden = visibleCount > 0;
};

search?.addEventListener('input', filterArchive);
document.querySelectorAll('[data-archive-filter]').forEach((button) => {
    button.addEventListener('click', () => {
        activeArchiveFilter = button.dataset.archiveFilter ?? 'all';
        document.querySelectorAll('[data-archive-filter]').forEach((filterButton) => {
            const selected = filterButton === button;
            filterButton.classList.toggle('is-selected', selected);
            filterButton.setAttribute('aria-pressed', String(selected));
        });
        filterArchive();
    });
});

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

const shellLayer = document.querySelector('[data-shell-layer]');
const shellTemplate = document.querySelector('#shell-window-template');
const shellWindows = new Set();
let nextWindowNumber = 1;
let nextZIndex = 30;
let pendingDelete = null;

const shellEndpoints = shellLayer ? {
    directory: shellLayer.dataset.directoryUrl,
    preview: shellLayer.dataset.previewUrl,
    stream: shellLayer.dataset.streamUrl,
    download: shellLayer.dataset.downloadUrl,
    delete: shellLayer.dataset.deleteUrl,
    mkdir: shellLayer.dataset.mkdirUrl,
    copy: shellLayer.dataset.copyUrl,
    move: shellLayer.dataset.moveUrl,
} : null;

const previewBackdrop = document.querySelector('[data-file-preview]');
const previewTitle = document.querySelector('[data-preview-title]');
const previewMeta = document.querySelector('[data-preview-meta]');
const previewContent = document.querySelector('[data-preview-content]');
const previewDownload = document.querySelector('[data-preview-download]');

const pathUrl = (endpoint, path) => {
    const url = new URL(endpoint, window.location.origin);
    url.searchParams.set('path', path);
    return url;
};

const responseError = async (response) => {
    let details;
    try {
        details = await response.json();
    } catch {
        return `Request failed (${response.status}).`;
    }

    const validationMessages = details.errors ? Object.values(details.errors).flat() : [];
    return validationMessages[0] ?? details.message ?? `Request failed (${response.status}).`;
};

const appendOutput = (windowElement, text, kind = '') => {
    const output = windowElement.querySelector('[data-shell-output]');
    const line = document.createElement('div');
    line.className = `shell-output-line${kind ? ` ${kind}` : ''}`;
    line.textContent = text;
    output.append(line);
    output.scrollTop = output.scrollHeight;
};

const appendCommandEcho = (windowElement, command) => {
    const output = windowElement.querySelector('[data-shell-output]');
    const line = document.createElement('div');
    line.className = 'shell-command-echo';
    const prompt = document.createElement('span');
    prompt.textContent = `${windowElement.dataset.cwd}>`;
    const text = document.createElement('span');
    text.textContent = command;
    line.append(prompt, text);
    output.append(line);
    output.scrollTop = output.scrollHeight;
};

const formatBytes = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

const parseCommand = (line) => {
    const tokens = line.match(/"[^"]*"|'[^']*'|\S+/g) ?? [];
    return tokens.map((token) => {
        if ((token.startsWith('"') && token.endsWith('"')) || (token.startsWith("'") && token.endsWith("'"))) {
            return token.slice(1, -1);
        }
        return token;
    });
};

const resolveVirtualPath = (currentPath, requestedPath) => {
    const normalizedPath = requestedPath.replaceAll('\\', '/');
    let segments = normalizedPath.startsWith('/')
        ? []
        : currentPath.replace(/^\/root\/?/, '').split('/').filter(Boolean);

    const requestedSegments = normalizedPath.split('/');
    if (requestedSegments[0] === '') requestedSegments.shift();
    if (requestedSegments[0]?.toLowerCase() === 'root') requestedSegments.shift();

    for (const segment of requestedSegments) {
        if (!segment || segment === '.') continue;
        if (segment === '..') {
            if (segments.length === 0) throw new Error('The virtual root cannot be traversed.');
            segments.pop();
            continue;
        }
        if (/[\x00-\x1F<>:"|?*]/.test(segment)) throw new Error('The path contains unsupported characters.');
        segments.push(segment);
    }

    return `/root${segments.length ? `/${segments.join('/')}` : ''}`;
};

const appendDirectoryListing = (windowElement, listing) => {
    const output = windowElement.querySelector('[data-shell-output]');
    const table = document.createElement('div');
    table.className = 'shell-directory-listing';

    const header = document.createElement('div');
    header.className = 'shell-directory-row shell-directory-header';
    ['TYPE', 'NAME', 'SIZE'].forEach((value) => {
        const cell = document.createElement('span');
        cell.textContent = value;
        header.append(cell);
    });
    table.append(header);

    if (listing.directories.length === 0 && listing.files.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'shell-output-line shell-output-muted';
        empty.textContent = 'No entries found.';
        table.append(empty);
    }

    listing.directories.forEach((directory) => {
        const row = document.createElement('div');
        row.className = 'shell-directory-row';
        const type = document.createElement('span');
        type.className = 'shell-entry-type shell-entry-directory';
        type.textContent = 'DIR';
        const name = document.createElement('button');
        name.type = 'button';
        name.className = 'shell-entry-name';
        name.textContent = directory.name;
        name.addEventListener('click', () => {
            windowElement.querySelector('[data-shell-input]').value = `cd "${directory.name}"`;
            windowElement.querySelector('[data-shell-form]').requestSubmit();
        });
        const size = document.createElement('span');
        size.className = 'shell-entry-size';
        size.textContent = '—';
        row.append(type, name, size);
        table.append(row);
    });

    listing.files.forEach((file) => {
        const row = document.createElement('div');
        row.className = 'shell-directory-row';
        const type = document.createElement('span');
        type.className = 'shell-entry-type';
        type.textContent = 'FILE';
        const name = document.createElement('button');
        name.type = 'button';
        name.className = 'shell-entry-name';
        name.textContent = file.name;
        name.addEventListener('click', () => {
            windowElement.querySelector('[data-shell-input]').value = `launch "${file.name}"`;
            windowElement.querySelector('[data-shell-form]').requestSubmit();
        });
        const size = document.createElement('span');
        size.className = 'shell-entry-size';
        size.textContent = formatBytes(file.size);
        row.append(type, name, size);
        table.append(row);
    });

    output.append(table);
    output.scrollTop = output.scrollHeight;
};

const fetchDirectory = async (windowElement, path = windowElement.dataset.cwd, changeDirectory = true) => {
    const response = await fetch(pathUrl(shellEndpoints.directory, path), {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error(await responseError(response));

    const listing = await response.json();
    if (changeDirectory) {
        windowElement.dataset.cwd = listing.path;
        windowElement.querySelector('[data-shell-prompt]').textContent = `${appAcronym} vcs:${listing.path}>`;
    }
    appendDirectoryListing(windowElement, listing);
    return listing;
};

const closePreview = () => {
    if (!previewBackdrop) return;
    previewBackdrop.hidden = true;
    previewContent.replaceChildren();
};

const makePreviewElement = (file) => {
    const streamUrl = pathUrl(shellEndpoints.stream, file.path).toString();

    if (file.preview !== null) {
        const pre = document.createElement('pre');
        pre.className = 'preview-text';
        pre.textContent = file.preview;
        if (file.truncated) {
            const notice = document.createElement('div');
            notice.className = 'preview-truncated';
            notice.textContent = 'Preview limited to the first 1 MB. Download the file to view the remainder.';
            return [notice, pre];
        }
        return [pre];
    }

    if (file.mime.startsWith('image/')) {
        const image = document.createElement('img');
        image.className = 'preview-image';
        image.src = streamUrl;
        image.alt = file.name;
        return [image];
    }

    if (file.mime === 'application/pdf') {
        const frame = document.createElement('iframe');
        frame.className = 'preview-frame';
        frame.title = file.name;
        frame.src = streamUrl;
        frame.referrerPolicy = 'no-referrer';
        return [frame];
    }

    if (file.mime.startsWith('audio/')) {
        const audio = document.createElement('audio');
        audio.className = 'preview-media';
        audio.controls = true;
        audio.src = streamUrl;
        return [audio];
    }

    if (file.mime.startsWith('video/')) {
        const video = document.createElement('video');
        video.className = 'preview-video';
        video.controls = true;
        video.src = streamUrl;
        return [video];
    }

    const unavailable = document.createElement('div');
    unavailable.className = 'preview-unavailable';
    unavailable.textContent = `Preview is unavailable for ${file.mime}. Use Download to access this file.`;
    return [unavailable];
};

const launchFile = async (filePath) => {
    const response = await fetch(pathUrl(shellEndpoints.preview, filePath), {
        headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error(await responseError(response));

    const file = await response.json();
    previewTitle.textContent = file.name;
    previewMeta.textContent = `${file.mime.toUpperCase()} · ${formatBytes(file.size)} · ${file.path}`;
    previewContent.replaceChildren(...makePreviewElement(file));
    previewDownload.href = pathUrl(shellEndpoints.download, file.path).toString();
    previewDownload.download = file.name;
    previewBackdrop.hidden = false;
    document.querySelector('[data-preview-close]').focus();
};

document.querySelectorAll('[data-archive-preview]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await launchFile(button.dataset.archivePreview);
        } catch (error) {
            showToast(error instanceof Error ? error.message : 'Unable to open this file.');
        }
    });
});

const executeCommand = async (windowElement, rawCommand) => {
    const tokens = parseCommand(rawCommand);
    if (tokens.length === 0) return;

    const [command, ...args] = tokens;
    const normalizedCommand = command.toLowerCase();

    if (normalizedCommand === 'exit') {
        if (args.length > 0) throw new Error('Usage: exit');
        closeWindow(windowElement);
        return;
    }

    if (normalizedCommand === '?help') {
        [
            'Available Vault Shell commands:',
            'cd [directory] — change the current directory.',
            'dir [directory] — list files and directories.',
            'mkdir [directory] — create a directory.',
            'copy [source] [destination] — copy a file or directory.',
            'move [source] [destination] — move or rename a file or directory.',
            'launch [file] — preview a file; launch vs opens another console.',
            'del [file] — delete a file after yes/no confirmation.',
            'trigger [text|calculation] — display text or calculate arithmetic; quote text to keep it literal.',
            'exit — close this console window.',
            '?help — show this command reference.',
            'All paths stay inside the private /root directory. Existing destinations are never overwritten.',
        ].forEach((line) => appendOutput(windowElement, line, 'shell-output-muted'));
        return;
    }

    if (pendingDelete?.window === windowElement) {
        const answer = tokens.join(' ').trim().toLowerCase();
        if (answer === 'yes' || answer === 'y') {
            const response = await fetch(shellEndpoints.delete, {
                method: 'DELETE',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify({ path: pendingDelete.path, confirmation: 'yes' }),
            });
            if (!response.ok) throw new Error(await responseError(response));
            const result = await response.json();
            pendingDelete = null;
            appendOutput(windowElement, `Deleted "${result.deleted}".`, 'shell-output-success');
            await fetchDirectory(windowElement);
            return;
        }
        if (answer === 'no' || answer === 'n') {
            pendingDelete = null;
            appendOutput(windowElement, 'Deletion cancelled.', 'shell-output-muted');
            return;
        }
        appendOutput(windowElement, 'Please confirm with yes or no.', 'shell-output-warning');
        return;
    }

    if (normalizedCommand === 'trigger') {
        const message = rawCommand.replace(/^\s*trigger(?:\s+)?/i, '').trim();
        const quotedText = message.match(/^"([\s\S]*)"$|^'([\s\S]*)'$/);
        if (quotedText) {
            appendOutput(windowElement, quotedText[1] ?? quotedText[2]);
            return;
        }

        const result = evaluateTriggerExpression(message);
        appendOutput(windowElement, result ?? message);
        return;
    }

    if (normalizedCommand === 'cd') {
        if (args.length > 1) throw new Error('Usage: cd [directory]');
        if (args.length === 0) {
            appendOutput(windowElement, windowElement.dataset.cwd, 'shell-output-muted');
            return;
        }

        const destination = resolveVirtualPath(windowElement.dataset.cwd, args[0]);
        await fetchDirectory(windowElement, destination);
        return;
    }

    if (normalizedCommand === 'dir') {
        if (args.length > 1) throw new Error('Usage: dir [directory]');
        const directory = args.length === 0
            ? windowElement.dataset.cwd
            : resolveVirtualPath(windowElement.dataset.cwd, args[0]);
        await fetchDirectory(windowElement, directory, false);
        return;
    }

    if (normalizedCommand === 'launch') {
        if (args.length !== 1) throw new Error('Usage: launch [file]');
        if (args[0].toLowerCase() === 'vs') {
            appendOutput(windowElement, 'Opening a new Vault Shell instance…', 'shell-output-success');
            createShellWindow();
            return;
        }
        await launchFile(resolveVirtualPath(windowElement.dataset.cwd, args[0]));
        appendOutput(windowElement, `Opened "${args[0]}".`, 'shell-output-success');
        return;
    }

    if (normalizedCommand === 'mkdir') {
        if (args.length !== 1) throw new Error('Usage: mkdir [directory]');
        const path = resolveVirtualPath(windowElement.dataset.cwd, args[0]);
        const response = await fetch(shellEndpoints.mkdir, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ path }),
        });
        if (!response.ok) throw new Error(await responseError(response));
        const result = await response.json();
        appendOutput(windowElement, `Created directory "${result.created}".`, 'shell-output-success');
        await fetchDirectory(windowElement);
        return;
    }

    if (normalizedCommand === 'copy' || normalizedCommand === 'move') {
        if (args.length !== 2) throw new Error(`Usage: ${normalizedCommand} [source] [destination]`);
        const source = resolveVirtualPath(windowElement.dataset.cwd, args[0]);
        const destination = resolveVirtualPath(windowElement.dataset.cwd, args[1]);
        const endpoint = normalizedCommand === 'copy' ? shellEndpoints.copy : shellEndpoints.move;
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ source, destination }),
        });
        if (!response.ok) throw new Error(await responseError(response));
        const result = await response.json();
        const target = result[normalizedCommand === 'copy' ? 'copied' : 'moved'];
        appendOutput(windowElement, `${normalizedCommand === 'copy' ? 'Copied' : 'Moved'} to "${target}".`, 'shell-output-success');
        await fetchDirectory(windowElement);
        return;
    }

    if (normalizedCommand === 'del') {
        if (args.length !== 1) throw new Error('Usage: del [file]');
        const filePath = resolveVirtualPath(windowElement.dataset.cwd, args[0]);
        const response = await fetch(pathUrl(shellEndpoints.preview, filePath), {
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error(await responseError(response));
        const file = await response.json();
        pendingDelete = { window: windowElement, path: file.path };
        appendOutput(windowElement, `Delete "${file.name}"? This cannot be undone. Type yes or no.`, 'shell-output-warning');
        return;
    }

    throw new Error('Command not available. Enter ?help to see available commands.');
};

const clampWindow = (windowElement, left, top) => {
    const rect = windowElement.getBoundingClientRect();
    const width = Math.min(rect.width, Math.max(180, window.innerWidth - 16));
    const height = Math.min(rect.height, Math.max(180, window.innerHeight - 16));
    const maxLeft = Math.max(8, window.innerWidth - width - 8);
    const maxTop = Math.max(8, window.innerHeight - height - 8);
    windowElement.style.right = 'auto';
    windowElement.style.bottom = 'auto';
    windowElement.style.width = `${width}px`;
    windowElement.style.height = `${height}px`;
    windowElement.style.left = `${Math.min(Math.max(left, 8), maxLeft)}px`;
    windowElement.style.top = `${Math.min(Math.max(top, 8), maxTop)}px`;
};

const focusWindow = (windowElement) => {
    nextZIndex += 1;
    windowElement.style.zIndex = String(nextZIndex);
};

const closeWindow = (windowElement) => {
    shellWindows.delete(windowElement);
    if (pendingDelete?.window === windowElement) pendingDelete = null;
    windowElement.classList.add('is-closing');
    window.setTimeout(() => windowElement.remove(), 180);
};

const createShellWindow = () => {
    if (!shellLayer || !(shellTemplate instanceof HTMLTemplateElement)) return null;

    const windowElement = shellTemplate.content.firstElementChild.cloneNode(true);
    const instance = nextWindowNumber++;
    windowElement.dataset.cwd = '/root';
    windowElement.querySelector('.shell-window-id').textContent = `VS / INSTANCE ${String(instance).padStart(2, '0')}`;
    windowElement.querySelector('[data-shell-prompt]').textContent = `${appAcronym} vcs:/root>`;
    windowElement.querySelector('[data-shell-input]').id = `shell-command-${instance}`;
    windowElement.querySelector('[data-shell-prompt]').htmlFor = `shell-command-${instance}`;
    windowElement.style.zIndex = String(++nextZIndex);
    shellLayer.append(windowElement);
    shellWindows.add(windowElement);

    const closeButton = windowElement.querySelector('[data-shell-close]');
    closeButton.addEventListener('click', () => closeWindow(windowElement));
    windowElement.querySelector('[data-shell-fullscreen]').addEventListener('click', () => {
        windowElement.classList.toggle('is-fullscreen');
        if (!windowElement.classList.contains('is-fullscreen')) {
            const rect = windowElement.getBoundingClientRect();
            clampWindow(windowElement, rect.left, rect.top);
        }
    });
    windowElement.addEventListener('pointerdown', () => focusWindow(windowElement));

    const form = windowElement.querySelector('[data-shell-form]');
    const input = windowElement.querySelector('[data-shell-input]');
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const rawCommand = input.value.trim();
        if (!rawCommand) return;
        input.value = '';
        appendCommandEcho(windowElement, rawCommand);

        try {
            await executeCommand(windowElement, rawCommand);
        } catch (error) {
            appendOutput(windowElement, error instanceof Error ? error.message : 'Command failed.', 'shell-output-error');
        }
        input.focus();
    });

    const dragHandle = windowElement.querySelector('[data-shell-drag]');
    dragHandle.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button') || windowElement.classList.contains('is-fullscreen')) return;
        event.preventDefault();
        focusWindow(windowElement);
        const rect = windowElement.getBoundingClientRect();
        const startX = event.clientX;
        const startY = event.clientY;
        const startLeft = rect.left;
        const startTop = rect.top;
        dragHandle.setPointerCapture(event.pointerId);

        const move = (moveEvent) => {
            clampWindow(windowElement, startLeft + moveEvent.clientX - startX, startTop + moveEvent.clientY - startY);
        };
        const finish = () => {
            dragHandle.removeEventListener('pointermove', move);
            dragHandle.removeEventListener('pointerup', finish);
            dragHandle.removeEventListener('pointercancel', finish);
        };
        dragHandle.addEventListener('pointermove', move);
        dragHandle.addEventListener('pointerup', finish);
        dragHandle.addEventListener('pointercancel', finish);
    });

    fetchDirectory(windowElement).catch((error) => {
        appendOutput(windowElement, error instanceof Error ? error.message : 'Unable to read the private directory.', 'shell-output-error');
    });
    window.requestAnimationFrame(() => {
        windowElement.classList.add('is-open');
        input.focus();
    });

    return windowElement;
};

document.querySelectorAll('[data-shell-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const existing = [...shellWindows].at(-1);
        if (existing) {
            existing.classList.remove('is-hidden');
            focusWindow(existing);
            existing.querySelector('[data-shell-input]').focus();
            return;
        }
        createShellWindow();
    });
});

document.addEventListener('keydown', (event) => {
    if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'x') {
        event.preventDefault();
        const visibleWindows = [...shellWindows].filter((windowElement) => !windowElement.classList.contains('is-hidden'));
        if (visibleWindows.length > 0) {
            visibleWindows.forEach((windowElement) => windowElement.classList.add('is-hidden'));
            return;
        }

        const existing = [...shellWindows].at(-1);
        if (existing) {
            existing.classList.remove('is-hidden');
            focusWindow(existing);
            existing.querySelector('[data-shell-input]').focus();
        } else {
            createShellWindow();
        }
    }
});

document.querySelector('[data-preview-close]')?.addEventListener('click', closePreview);
previewBackdrop?.addEventListener('click', (event) => {
    if (event.target === previewBackdrop) closePreview();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && previewBackdrop && !previewBackdrop.hidden) closePreview();
});

window.addEventListener('resize', () => {
    shellWindows.forEach((windowElement) => {
        if (windowElement.classList.contains('is-fullscreen')) return;
        const rect = windowElement.getBoundingClientRect();
        clampWindow(windowElement, rect.left, rect.top);
    });
});

const uploadInput = document.querySelector('[data-upload-input]');
const uploadSelection = document.querySelector('[data-upload-selection]');
const uploadSubmit = document.querySelector('[data-upload-submit]');
const uploadDropzone = document.querySelector('[data-upload-dropzone]');

const displaySelectedFiles = () => {
    if (!(uploadInput instanceof HTMLInputElement) || !uploadSelection) return;

    const files = [...(uploadInput.files ?? [])];
    uploadSelection.textContent = files.length
        ? `${files.length} file${files.length === 1 ? '' : 's'} selected: ${files.map((file) => file.name).join(', ')}`
        : 'No files selected.';
    if (uploadSubmit instanceof HTMLButtonElement) uploadSubmit.disabled = files.length === 0 || files.length > 20;
};

uploadInput?.addEventListener('change', displaySelectedFiles);
uploadDropzone?.addEventListener('dragover', (event) => {
    event.preventDefault();
    uploadDropzone.classList.add('is-dragging');
});
uploadDropzone?.addEventListener('dragleave', (event) => {
    if (!uploadDropzone.contains(event.relatedTarget)) uploadDropzone.classList.remove('is-dragging');
});
uploadDropzone?.addEventListener('drop', (event) => {
    event.preventDefault();
    uploadDropzone.classList.remove('is-dragging');
    if (!(event.dataTransfer instanceof DataTransfer) || !(uploadInput instanceof HTMLInputElement)) return;

    uploadInput.files = event.dataTransfer.files;
    uploadInput.dispatchEvent(new Event('change', { bubbles: true }));
});
document.querySelector('[data-manual-upload]')?.addEventListener('submit', () => {
    if (uploadSubmit instanceof HTMLButtonElement) {
        uploadSubmit.disabled = true;
        uploadSubmit.textContent = 'UPLOADING…';
    }
});

const apiUploadSwitch = document.querySelector('#api-upload-enabled');
const apiUploadStatus = document.querySelector('[data-switch-status]');
apiUploadSwitch?.addEventListener('change', () => {
    if (apiUploadStatus) apiUploadStatus.textContent = apiUploadSwitch.checked ? 'ENABLED' : 'DISABLED';
});

const calculator = document.querySelector('[data-calculator]');
if (calculator) {
    const expressionDisplay = calculator.querySelector('[data-calc-expression]');
    const resultDisplay = calculator.querySelector('[data-calc-result]');
    const errorDisplay = calculator.querySelector('[data-calc-error]');
    const statusDisplay = calculator.querySelector('[data-calc-status]');
    const modeInput = calculator.querySelector('[data-calc-mode]');
    const historyList = document.querySelector('[data-history-list]');
    const historyCount = document.querySelector('[data-history-count]');
    const historyExpand = document.querySelector('[data-history-expand]');
    const historyClear = document.querySelector('[data-history-clear]');
    const historyStorageKey = 'vcs.calculation-history.v1';
    const visibleHistoryCount = 12;
    let expression = '';
    let lastAnswer = 0;
    let historyExpanded = false;
    let history = [];
    let historyAvailable = true;
    let justEvaluated = false;

    try {
        const storedHistory = localStorage.getItem(historyStorageKey);
        if (storedHistory !== null) {
            const parsedHistory = JSON.parse(storedHistory);
            if (!Array.isArray(parsedHistory) || parsedHistory.some((entry) => (
                typeof entry?.expression !== 'string'
                || typeof entry?.result !== 'string'
                || typeof entry?.timestamp !== 'string'
            ))) {
                throw new Error('Stored calculation history has an unsupported format.');
            }
            history = parsedHistory.slice(0, 100);
        }
    } catch (error) {
        historyAvailable = false;
        showToast(error instanceof Error ? error.message : 'Could not read local calculation history.');
    }

    const formatResult = (value) => {
        if (!Number.isFinite(value)) throw new Error('The result is outside the supported numeric range.');
        if (Object.is(value, -0)) return '0';
        return Number(value.toPrecision(12)).toString();
    };

    const renderHistory = () => {
        if (!historyList) return;
        historyList.replaceChildren();
        historyCount.textContent = `${String(history.length).padStart(2, '0')} / 100`;
        historyClear.hidden = history.length === 0;

        if (history.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'history-empty';
            empty.textContent = historyAvailable
                ? 'Your calculations will appear here.'
                : 'History is unavailable. Clear this browser’s local storage to reset it.';
            historyList.append(empty);
            historyExpand.hidden = true;
            return;
        }

        const visibleHistory = historyExpanded ? history : history.slice(0, visibleHistoryCount);
        visibleHistory.forEach((entry) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'history-entry';
            item.title = 'Restore calculation';
            const equation = document.createElement('span');
            equation.className = 'history-equation';
            equation.textContent = entry.expression;
            const answer = document.createElement('strong');
            answer.className = 'history-answer';
            answer.textContent = entry.result;
            const timestamp = document.createElement('time');
            timestamp.className = 'history-timestamp';
            const parsedDate = new Date(entry.timestamp);
            timestamp.textContent = Number.isNaN(parsedDate.getTime())
                ? ''
                : new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(parsedDate);
            item.append(equation, answer, timestamp);
            item.addEventListener('click', () => {
                expression = entry.expression;
                expressionDisplay.textContent = expression;
                resultDisplay.textContent = entry.result;
                errorDisplay.hidden = true;
                justEvaluated = true;
            });
            historyList.append(item);
        });

        historyExpand.hidden = history.length <= visibleHistoryCount;
        historyExpand.innerHTML = historyExpanded
            ? 'SHOW LESS <span aria-hidden="true">↑</span>'
            : 'VIEW CALCULATION HISTORY <span aria-hidden="true">↓</span>';
    };

    const saveHistory = (entry) => {
        history.unshift(entry);
        history = history.slice(0, 100);
        try {
            localStorage.setItem(historyStorageKey, JSON.stringify(history));
            historyAvailable = true;
        } catch {
            historyAvailable = false;
            showToast('Calculation succeeded, but local history could not be saved.');
        }
        renderHistory();
    };

    const calculate = (source) => {
        const normalized = source
            .replaceAll('×', '*')
            .replaceAll('÷', '/')
            .replaceAll('−', '-')
            .replaceAll('π', 'pi')
            .replace(/\bANS\b/gi, String(lastAnswer));
        const tokenPattern = /(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?|[a-z]+|[()+\-*/%^!,]/gi;
        const tokens = normalized.match(tokenPattern) ?? [];
        const compactSource = normalized.replace(/\s+/g, '');

        if (tokens.join('') !== compactSource || tokens.length === 0) {
            throw new Error('The expression contains unsupported characters.');
        }

        const functions = new Set(['sin', 'cos', 'tan', 'asin', 'acos', 'atan', 'sqrt', 'log', 'ln', 'abs', 'exp', 'floor', 'ceil', 'round']);
        const expandedTokens = [];
        const endsValue = (token) => token === ')' || token === '!' || token.toLowerCase() === 'pi' || token.toLowerCase() === 'e' || /^\d/.test(token) || /^\.\d/.test(token);
        const beginsValue = (token) => token === '(' || token.toLowerCase() === 'pi' || token.toLowerCase() === 'e' || functions.has(token.toLowerCase()) || /^\d/.test(token) || /^\.\d/.test(token);

        tokens.forEach((token, index) => {
            const previous = tokens[index - 1];
            if (previous && endsValue(previous) && beginsValue(token)) {
                expandedTokens.push('*');
            }
            expandedTokens.push(token);
        });

        let position = 0;
        const peek = () => expandedTokens[position];
        const consume = () => expandedTokens[position++];
        const expect = (token) => {
            if (consume() !== token) throw new Error(`Expected "${token}".`);
        };
        const parseExpression = () => {
            let value = parseTerm();
            while (peek() === '+' || peek() === '-') {
                const operator = consume();
                const right = parseTerm();
                value = operator === '+' ? value + right : value - right;
            }
            return value;
        };
        const parseTerm = () => {
            let value = parseUnary();
            while (['*', '/', '%'].includes(peek())) {
                const operator = consume();
                const right = parseUnary();
                if ((operator === '/' || operator === '%') && right === 0) throw new Error('Cannot divide by zero.');
                if (operator === '*') value *= right;
                if (operator === '/') value /= right;
                if (operator === '%') value %= right;
            }
            return value;
        };
        const parseUnary = () => {
            if (peek() === '+') {
                consume();
                return parseUnary();
            }
            if (peek() === '-') {
                consume();
                return -parseUnary();
            }
            return parsePower();
        };
        const parsePower = () => {
            const base = parsePostfix();
            if (peek() === '^') {
                consume();
                return base ** parseUnary();
            }
            return base;
        };
        const parsePostfix = () => {
            let value = parsePrimary();
            while (peek() === '!') {
                consume();
                if (!Number.isInteger(value) || value < 0 || value > 170) throw new Error('Factorial requires an integer from 0 to 170.');
                for (let factor = value - 1; factor > 1; factor -= 1) value *= factor;
            }
            return value;
        };
        const parsePrimary = () => {
            const token = consume();
            if (token === undefined) throw new Error('Incomplete expression.');
            if (token === '(') {
                const value = parseExpression();
                expect(')');
                return value;
            }

            const loweredToken = token.toLowerCase();
            if (loweredToken === 'pi') return Math.PI;
            if (loweredToken === 'e') return Math.E;
            if (functions.has(loweredToken)) {
                expect('(');
                const argument = parseExpression();
                expect(')');
                const result = ({
                    sin: (value) => Math.sin(value * Math.PI / 180),
                    cos: (value) => Math.cos(value * Math.PI / 180),
                    tan: (value) => Math.tan(value * Math.PI / 180),
                    asin: (value) => Math.asin(value) * 180 / Math.PI,
                    acos: (value) => Math.acos(value) * 180 / Math.PI,
                    atan: (value) => Math.atan(value) * 180 / Math.PI,
                    sqrt: Math.sqrt,
                    log: Math.log10,
                    ln: Math.log,
                    abs: Math.abs,
                    exp: Math.exp,
                    floor: Math.floor,
                    ceil: Math.ceil,
                    round: Math.round,
                })[loweredToken](argument);
                if (!Number.isFinite(result)) throw new Error('The function is undefined for this value.');
                return result;
            }
            if (/^(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(token)) return Number(token);
            throw new Error(`Unknown value "${token}".`);
        };

        const value = parseExpression();
        if (position !== expandedTokens.length) throw new Error(`Unexpected token "${peek()}".`);
        return value;
    };

    const updateDisplay = () => {
        expressionDisplay.textContent = expression || '0';
        if (!expression) resultDisplay.textContent = '0';
        errorDisplay.hidden = true;
    };

    const appendToExpression = (value) => {
        const startsNewValue = /^[\d.]$/.test(value) || value === 'pi' || value === 'e' || /[a-z]+\($/i.test(value) || value === '(';
        if (justEvaluated && startsNewValue) expression = '';
        justEvaluated = false;
        expression += value;
        updateDisplay();
    };

    const handleAction = (action) => {
        if (!action) return;
        if (action === 'clear') {
            expression = '';
            justEvaluated = false;
            updateDisplay();
            return;
        }
        if (action === 'backspace') {
            if (justEvaluated) expression = '';
            else expression = expression.slice(0, -1);
            justEvaluated = false;
            updateDisplay();
            return;
        }
        if (action === 'sign') {
            expression = expression.startsWith('-') ? expression.slice(1) : `-${expression || '0'}`;
            justEvaluated = false;
            updateDisplay();
            return;
        }
        if (action === 'equals') {
            if (!expression) return;
            try {
                const value = calculate(expression);
                const result = formatResult(value);
                resultDisplay.textContent = result;
                statusDisplay.innerHTML = 'COMPLETE <span>·</span> DEG';
                errorDisplay.hidden = true;
                saveHistory({ expression, result, timestamp: new Date().toISOString() });
                lastAnswer = value;
                justEvaluated = true;
            } catch (error) {
                errorDisplay.textContent = error instanceof Error ? error.message : 'Could not evaluate this expression.';
                errorDisplay.hidden = false;
                statusDisplay.innerHTML = 'CHECK EXPRESSION <span>·</span> DEG';
            }
            return;
        }

        if (action === '^2') {
            appendToExpression('^2');
            return;
        }
        if (action === 'mod') {
            appendToExpression('%');
            return;
        }
        if (action === 'factorial') {
            appendToExpression('!');
            return;
        }
        if (action === 'answer') {
            appendToExpression('ANS');
            return;
        }
        appendToExpression(action);
    };

    calculator.querySelectorAll('[data-calc-action]').forEach((button) => {
        button.addEventListener('click', () => handleAction(button.dataset.calcAction));
    });

    modeInput.addEventListener('change', () => {
        const scientific = modeInput.checked;
        calculator.classList.toggle('is-scientific', scientific);
        calculator.querySelectorAll('.calc-scientific-key').forEach((key) => {
            key.hidden = !scientific;
        });
        calculator.querySelectorAll('[data-mode-label]').forEach((label) => {
            label.classList.toggle('is-active', label.dataset.modeLabel === (scientific ? 'scientific' : 'basic'));
        });
    });

    calculator.addEventListener('keydown', (event) => {
        if (/^[\d.+\-*/()%^!]$/.test(event.key)) {
            event.preventDefault();
            handleAction(event.key);
        } else if (event.key === 'Enter' || event.key === '=') {
            event.preventDefault();
            handleAction('equals');
        } else if (event.key === 'Backspace') {
            event.preventDefault();
            handleAction('backspace');
        } else if (event.key === 'Escape') {
            event.preventDefault();
            handleAction('clear');
        }
    });

    historyExpand.addEventListener('click', () => {
        historyExpanded = !historyExpanded;
        renderHistory();
    });

    historyClear.addEventListener('click', () => {
        if (!window.confirm('Clear all locally stored calculator history?')) return;
        history = [];
        historyExpanded = false;
        try {
            localStorage.removeItem(historyStorageKey);
            historyAvailable = true;
        } catch {
            historyAvailable = false;
            showToast('Could not clear local calculation history.');
        }
        renderHistory();
    });

    renderHistory();
}
