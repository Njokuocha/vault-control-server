import './bootstrap';
import './alerts';
import './video-uploader';
import { evaluateTriggerExpression } from './shell-math.mjs';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const pusherKey = import.meta.env.VITE_PUSHER_APP_KEY;
const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER;

if (pusherKey && pusherCluster) {
    window.Pusher = Pusher;
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: pusherKey,
        cluster: pusherCluster,
        forceTLS: true,
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        },
    });

    window.Echo.private('admin.vault')
        .listen('.file.uploaded', (event) => {
            window.vaultAlert({ type: 'info', message: `${event.name} received in the private archive.` });
            window.dispatchEvent(new CustomEvent('vault:file-uploaded', { detail: event }));
        })
        .listen('.file.edited', (event) => {
            window.vaultAlert({ type: 'success', message: `${event.name} was updated in the private archive.` });
            window.dispatchEvent(new CustomEvent('vault:file-edited', { detail: event }));
        })
        .listen('.file.deleted', (event) => {
            window.vaultAlert({ type: 'info', message: `${event.name} was removed from the private archive.` });
            window.dispatchEvent(new CustomEvent('vault:file-deleted', { detail: event }));
        })
        .listen('.archive.index-updated', (event) => {
            window.dispatchEvent(new CustomEvent('vault:archive-index-updated', { detail: event }));
        })
        .listen('.video.upload.status', (event) => {
            window.dispatchEvent(new CustomEvent('vault:video-upload-status', { detail: event }));
        });

    window.Echo.connector.pusher.connection.bind('state_change', ({ current, previous }) => {
        if (previous === 'connected' && current !== 'connected') {
            window.vaultAlert({ type: 'warning', message: 'Realtime connection lost. The archive remains available; updates may be delayed.' });
        } else if (previous !== 'connected' && current === 'connected') {
            window.vaultAlert({ type: 'success', message: 'Realtime connection restored.' });
        }
    });
}

let pageCleanup = [];
let clockInterval = null;
let navigationInProgress = false;

const listenForPageEvent = (target, type, listener, options) => {
    target.addEventListener(type, listener, options);
    pageCleanup.push(() => target.removeEventListener(type, listener, options));
};

const initializeVaultPage = () => {
    pageCleanup.forEach((cleanup) => cleanup());
    pageCleanup = [];
    if (clockInterval !== null) window.clearInterval(clockInterval);

const clock = document.querySelector('[data-clock]');
const date = document.querySelector('[data-date]');
const search = document.querySelector('[data-search]');
const sidebar = document.querySelector('#sidebar');
const menuToggle = document.querySelector('[data-menu-toggle]');
const menuClose = document.querySelector('[data-menu-close]');
const toastRegion = document.querySelector('[data-toast]');
const appAcronym = document.querySelector('meta[name="application-acronym"]')?.content ?? 'VCS';

const showToast = (message, type = 'info') => {
    window.vaultAlert({ type, message });
};

document.querySelector('[data-confirm-key-rotation]')?.addEventListener('submit', async (event) => {
    const form = event.currentTarget;
    if (!(form instanceof HTMLFormElement) || form.dataset.confirmed === 'true') {
        if (form instanceof HTMLFormElement) delete form.dataset.confirmed;
        return;
    }
    event.preventDefault();
    const confirmed = await window.vaultConfirm({
        title: 'Rotate upload key',
        message: 'The current API key will stop working immediately. Continue with key rotation?',
        confirmLabel: 'ROTATE KEY',
        destructive: true,
    });
    if (!confirmed) return;
    form.dataset.confirmed = 'true';
    form.requestSubmit();
});

if (document.querySelector('model-viewer')) {
    import('@google/model-viewer').catch((error) => {
        console.error('Unable to load the 3D avatar viewer.', error);
        showToast('3D avatar preview could not be loaded.', 'error');
    });
}

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
clockInterval = window.setInterval(updateTime, 1000);

menuToggle?.addEventListener('click', () => {
    setMenuOpen(menuToggle.getAttribute('aria-expanded') !== 'true');
});
menuClose?.addEventListener('click', () => setMenuOpen(false));

const archiveCount = document.querySelector('[data-archive-count]');
const archiveFilterEmpty = document.querySelector('[data-filter-empty]');
let activeArchiveFilter = 'all';
let archiveSearchTimer = null;

const filterArchive = () => {
    const query = search?.value.trim().toLocaleLowerCase() ?? '';
    let visibleCount = 0;

    document.querySelectorAll('[data-archive-record]').forEach((record) => {
        const matchesFilter = activeArchiveFilter === 'all'
            || record.dataset.archiveCategory === activeArchiveFilter;
        const matchesSearch = (record.dataset.archiveSearch ?? '').includes(query);
        const visible = matchesFilter && matchesSearch;
        record.hidden = !visible;
        if (visible) visibleCount += 1;
    });

    document.querySelectorAll('[data-archive-month]').forEach((month) => {
        month.hidden = !month.querySelector('[data-archive-record]:not([hidden])');
    });
    if (archiveCount) archiveCount.textContent = String(visibleCount).padStart(2, '0');
    if (archiveFilterEmpty) archiveFilterEmpty.hidden = visibleCount > 0 || document.querySelectorAll('[data-archive-record]').length === 0;
};

search?.addEventListener('input', filterArchive);
search?.addEventListener('input', () => {
    window.clearTimeout(archiveSearchTimer);
    archiveSearchTimer = window.setTimeout(() => {
        window.Livewire?.dispatch('dashboard-archive-search', { search: search.value });
    }, 250);
});
search?.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    search.value = '';
    filterArchive();
    window.Livewire?.dispatch('dashboard-archive-search', { search: '' });
});
document.querySelectorAll('[data-archive-filter]').forEach((button) => {
    button.addEventListener('click', () => {
        activeArchiveFilter = button.dataset.archiveFilter ?? 'all';
        window.Livewire?.dispatch('dashboard-archive-filter', { filter: activeArchiveFilter });
        document.querySelectorAll('[data-archive-filter]').forEach((filterButton) => {
            const selected = filterButton === button;
            filterButton.classList.toggle('is-selected', selected);
            filterButton.setAttribute('aria-pressed', String(selected));
        });
        filterArchive();
    });
});

listenForPageEvent(document, 'keydown', (event) => {
    if (event.key === 'Escape') {
        setMenuOpen(false);
    }

    if (event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey) {
        const target = event.target;
        const isTyping = target instanceof HTMLElement
            && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

        if (!isTyping) {
            event.preventDefault();
            const modalSearch = caseLinkDialog?.open
                ? caseLinkSearch
                : (archiveBrowser && !archiveBrowser.hidden ? archiveBrowserSearch : null);
            (modalSearch ?? document.querySelector('[data-page-search]') ?? search)?.focus();
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

document.querySelector('[data-generate-key]')?.addEventListener('click', () => {
    const input = document.querySelector('#upload-key');

    if (!(input instanceof HTMLInputElement)) return;

    if (!window.crypto?.getRandomValues) {
        showToast('Secure key generation is unavailable in this browser.', 'warning');
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
            showToast('Could not find the text to copy.', 'error');
            return;
        }

        try {
            await navigator.clipboard.writeText(target.textContent?.trim() ?? '');
            showToast('Copied to clipboard.', 'success');
        } catch {
            showToast('Clipboard access was denied. Select and copy the text manually.', 'error');
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
    const streamUrl = file.stream_url ?? pathUrl(shellEndpoints.stream, file.path).toString();
    const archiveElements = [];
    if (file.is_archive) {
        const archiveSection = document.createElement('section');
        archiveSection.className = 'archive-entry-preview';
        const heading = document.createElement('div');
        heading.className = 'archive-entry-preview-heading';
        const title = document.createElement('strong');
        title.textContent = 'ARCHIVE CONTENTS';
        const count = document.createElement('span');
        count.textContent = `${file.archive_entries_count ?? file.archive_entries.length} ENTRIES`;
        heading.append(title, count);
        const list = document.createElement('ul');
        list.className = 'archive-entry-list';
        if (file.archive_status === 'pending') {
            const pending = document.createElement('p');
            pending.className = 'archive-entry-empty';
            pending.textContent = 'Archive index pending. The entry listing will appear when background indexing finishes.';
            archiveSection.append(heading, pending);
        } else if (file.archive_status === 'failed') {
            const failed = document.createElement('p');
            failed.className = 'archive-entry-empty';
            failed.textContent = 'Archive index unavailable. The archive can still be downloaded.';
            archiveSection.append(heading, failed);
        } else if (file.archive_tree?.children?.length > 0) {
            const appendNodes = (parent, nodes) => {
                nodes.forEach((node) => {
                    const item = document.createElement('li');
                    if (node.type === 'directory') {
                        const folder = document.createElement('details');
                        const summary = document.createElement('summary');
                        summary.textContent = `${node.name}/`;
                        const children = document.createElement('ul');
                        appendNodes(children, node.children ?? []);
                        folder.append(summary, children);
                        item.append(folder);
                    } else {
                        const open = document.createElement('button');
                        open.type = 'button';
                        open.className = 'archive-entry-open';
                        open.textContent = `${node.name} · ${formatBytes(node.size ?? 0)}`;
                        open.addEventListener('click', async () => {
                            const entryUrl = new URL(file.archive_entry_url, window.location.origin);
                            entryUrl.searchParams.set('path', node.path);
                            try {
                                const response = await fetch(entryUrl, { headers: { Accept: 'application/json' } });
                                const result = await response.json();
                                if (response.status === 413) {
                                    const message = document.createElement('p');
                                    message.className = 'preview-unavailable';
                                    message.textContent = result.message;
                                    const download = document.createElement('a');
                                    download.href = result.download_url;
                                    download.textContent = 'DOWNLOAD ENTRY';
                                    archiveSection.replaceChildren(message, download);
                                    return;
                                }
                                if (!response.ok) throw new Error(result.message ?? 'Unable to preview archive entry.');
                                if (result.preview_available) {
                                    const content = document.createElement('pre');
                                    content.className = 'preview-text';
                                    content.textContent = result.content;
                                    const download = document.createElement('a');
                                    download.href = result.download_url;
                                    download.textContent = 'DOWNLOAD ENTRY';
                                    archiveSection.replaceChildren(heading, content, download);
                                } else {
                                    const message = document.createElement('p');
                                    message.className = 'preview-unavailable';
                                    message.textContent = result.message ?? 'Preview unavailable for this entry.';
                                    const download = document.createElement('a');
                                    download.href = result.download_url;
                                    download.textContent = 'DOWNLOAD ENTRY';
                                    archiveSection.replaceChildren(heading, message, download);
                                }
                            } catch (error) {
                                showToast(error instanceof Error ? error.message : 'Unable to preview archive entry.', 'error');
                            }
                        });
                        item.append(open);
                    }
                    parent.append(item);
                });
            };
            appendNodes(list, file.archive_tree.children);
            archiveSection.append(heading, list);
        } else if (file.archive_entries.length > 0) {
            file.archive_entries.forEach((entryName) => {
                const item = document.createElement('li');
                item.textContent = entryName;
                list.append(item);
            });
            archiveSection.append(heading, list);
        } else {
            const empty = document.createElement('p');
            empty.className = 'archive-entry-empty';
            empty.textContent = 'This ZIP archive contains no files.';
            archiveSection.append(heading, empty);
        }
        if (file.archive_entries_truncated) {
            const notice = document.createElement('p');
            notice.className = 'preview-truncated';
            notice.textContent = `Archive listing capped at 1,000 entries; and ${Math.max(0, file.archive_entries_count - 1000)} more are indexed.`;
            archiveSection.append(notice);
        }
        archiveElements.push(archiveSection);
    }

    if (file.preview !== null) {
        const pre = document.createElement('pre');
        pre.className = 'preview-text';
        pre.textContent = file.preview;
        const elements = [...archiveElements];
        if (file.extracted) {
            const notice = document.createElement('div');
            notice.className = 'preview-truncated';
            notice.textContent = 'Text extracted from the document. Embedded content and formatting are not rendered.';
            elements.push(notice);
        }
        if (file.truncated) {
            const notice = document.createElement('div');
            notice.className = 'preview-truncated';
            notice.textContent = 'Preview limited to the first 1 MB. Download the file to view the remainder.';
            elements.push(notice);
        }
        elements.push(pre);
        return elements;
    }

    if (file.is_archive) return archiveElements;

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
    previewMeta.textContent = `${file.mime.toUpperCase()} · ${formatBytes(file.size)}${file.path ? ` · ${file.path}` : ''}`;
    previewContent.replaceChildren(...makePreviewElement(file));
    previewDownload.href = file.download_url ?? pathUrl(shellEndpoints.download, file.path).toString();
    previewDownload.download = file.name;
    previewBackdrop.hidden = false;
    document.querySelector('[data-preview-close]').focus();
};

const archiveBrowser = document.querySelector('[data-archive-browser]');
const archiveBrowserRecords = archiveBrowser?.querySelector('[data-full-archive-records]');
const archiveBrowserState = archiveBrowser?.querySelector('[data-full-archive-state]');
const archiveBrowserLoading = archiveBrowser?.querySelector('[data-full-archive-loading]');
const archiveBrowserSearch = archiveBrowser?.querySelector('[data-full-archive-search]');
const archiveBrowserYear = archiveBrowser?.querySelector('[data-full-archive-year]');
const archiveBrowserMonth = archiveBrowser?.querySelector('[data-full-archive-month]');
const archiveBrowserExtension = archiveBrowser?.querySelector('[data-full-archive-extension]');
const archiveBrowserUploadMethod = archiveBrowser?.querySelector('[data-full-archive-upload-method]');
const archiveBrowserPreview = archiveBrowser?.querySelector('[data-full-archive-preview-content]');
let archiveBrowserRecordsLoaded = [];
let archiveBrowserPage = 0;
let archiveBrowserLastPage = 1;
let archiveBrowserRequest = null;
let archiveBrowserSearchTimer = null;

const renderFullArchive = () => {
    if (!archiveBrowserRecords) return;
    archiveBrowserRecords.replaceChildren();
    archiveBrowserRecords.append(archiveBrowserState);

    const groups = new Map();
    archiveBrowserRecordsLoaded.forEach((record) => {
        if (!groups.has(record.month_key)) groups.set(record.month_key, []);
        groups.get(record.month_key).push(record);
    });

    groups.forEach((records, monthKey) => {
        const group = document.createElement('section');
        group.className = 'archive-month';
        group.dataset.archiveMonth = monthKey;
        const heading = document.createElement('h3');
        heading.className = 'archive-month-heading';
        heading.textContent = records[0].month_label;
        const count = document.createElement('span');
        count.textContent = `${records.length} FILE${records.length === 1 ? '' : 'S'}`;
        heading.append(count);
        group.append(heading);

        records.forEach((record) => {
            const row = document.createElement('article');
            row.className = 'archive-record';
            const name = document.createElement('div');
            name.className = 'archive-record-name';
            const icon = document.createElement('span');
            icon.className = 'archive-file-icon';
            icon.textContent = (record.extension || 'FILE').toUpperCase();
            icon.setAttribute('aria-hidden', 'true');
            const details = document.createElement('span');
            const strong = document.createElement('strong');
            strong.textContent = record.name;
            const mime = document.createElement('small');
            mime.textContent = record.mime;
            details.append(strong, mime);
            name.append(icon, details);

            const category = document.createElement('span');
            category.className = 'archive-record-category';
            category.textContent = record.category.toUpperCase();
            const uploadMethod = document.createElement('span');
            uploadMethod.className = `upload-method-badge upload-method-${record.upload_method || 'unknown'}`;
            uploadMethod.textContent = (record.upload_method || 'unknown').toUpperCase();
            const date = document.createElement('time');
            date.className = 'archive-record-date';
            date.dateTime = record.created_at;
            date.textContent = record.date_label;
            const size = document.createElement('span');
            size.className = 'archive-record-size';
            size.textContent = formatBytes(record.size);
            const actions = document.createElement('div');
            actions.className = 'archive-record-actions';
            const open = document.createElement('button');
            open.type = 'button';
            open.textContent = 'OPEN';
            open.dataset.fullArchivePreview = record.uuid;
            open.setAttribute('aria-label', `Preview ${record.name}`);
            const download = document.createElement('a');
            download.href = record.download_url;
            download.textContent = '↓';
            download.setAttribute('aria-label', `Download ${record.name}`);
            actions.append(open, download);
            row.append(name, category, uploadMethod, date, size, actions);
            group.append(row);
        });
        archiveBrowserRecords.append(group);
    });

    if (archiveBrowserRecordsLoaded.length === 0) {
        archiveBrowserState.hidden = false;
        archiveBrowserState.textContent = 'No archive records match the selected filters.';
    } else {
        archiveBrowserState.hidden = true;
    }

    if (archiveBrowserPage < archiveBrowserLastPage) {
        const more = document.createElement('div');
        more.className = 'archive-browser-more';
        more.textContent = 'Scroll to load more records';
        archiveBrowserRecords.append(more);
    }
};

const loadFullArchive = async (reset = false) => {
    if (!archiveBrowser || !archiveBrowserRecords) return;
    if (reset) {
        archiveBrowserRequest?.abort();
        archiveBrowserRequest = null;
        archiveBrowserRecordsLoaded = [];
        archiveBrowserPage = 0;
        archiveBrowserLastPage = 1;
        archiveBrowserRecords.scrollTop = 0;
        archiveBrowserRecords.replaceChildren(archiveBrowserState);
        archiveBrowserState.hidden = false;
        archiveBrowserState.textContent = 'Loading archive records…';
    }
    if (archiveBrowserRequest || archiveBrowserPage >= archiveBrowserLastPage) return;

    const controller = new AbortController();
    archiveBrowserRequest = controller;
    archiveBrowserLoading.hidden = false;
    const url = new URL(archiveBrowser.dataset.filesUrl, window.location.origin);
    const params = new URLSearchParams({
        page: String(archiveBrowserPage + 1),
        q: archiveBrowserSearch.value.trim(),
        year: archiveBrowserYear.value,
        month: archiveBrowserMonth.value,
        extension: archiveBrowserExtension.value,
        upload_method: archiveBrowserUploadMethod.value,
    });
    url.search = params.toString();

    try {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        if (!response.ok) throw new Error(await responseError(response));

        const result = await response.json();
        if (controller.signal.aborted) return;
        archiveBrowserRecordsLoaded.push(...result.records);
        archiveBrowserPage = result.current_page;
        archiveBrowserLastPage = result.last_page;
        archiveBrowser.querySelector('[data-full-archive-total]').textContent = `${Number(result.total).toLocaleString()} RECORDS`;

        const yearSelect = archiveBrowserYear;
        const selectedYear = yearSelect.value;
        const years = new Set([...yearSelect.options].map((option) => option.value));
        result.years.forEach((year) => {
            if (years.has(year)) return;
            const option = document.createElement('option');
            option.value = year;
            option.textContent = year;
            yearSelect.append(option);
        });
        yearSelect.value = selectedYear;

        const extensionSelect = archiveBrowserExtension;
        const selectedExtension = extensionSelect.value;
        const extensions = new Set([...extensionSelect.options].map((option) => option.value));
        result.extensions.forEach((extension) => {
            if (extensions.has(extension)) return;
            const option = document.createElement('option');
            option.value = extension;
            option.textContent = extension === '__none__' ? 'No extension' : `.${extension}`;
            extensionSelect.append(option);
        });
        extensionSelect.value = selectedExtension;
        renderFullArchive();
    } catch (error) {
        if (error.name !== 'AbortError') {
            archiveBrowserState.hidden = false;
            archiveBrowserState.textContent = error instanceof Error ? error.message : 'Unable to load archive records.';
            showToast(archiveBrowserState.textContent, 'error');
        }
    } finally {
        if (archiveBrowserRequest === controller) {
            archiveBrowserRequest = null;
            archiveBrowserLoading.hidden = true;
        }
    }
};

const openFullArchive = () => {
    if (!archiveBrowser) return;
    window.Livewire?.dispatch('open-archive');
    document.body.classList.add('archive-browser-open');
    window.setTimeout(() => archiveBrowser.querySelector('[data-full-archive-search]')?.focus(), 250);
};

const closeFullArchive = () => {
    if (!archiveBrowser) return;
    window.Livewire?.dispatch('close-archive');
    document.body.classList.remove('archive-browser-open');
};

const refreshDashboardSnapshot = async () => {
    if (document.querySelectorAll('[data-dashboard-stat]').length === 0) return;
    const snapshotUrl = document.querySelector('meta[name="dashboard-snapshot-url"]')?.content;
    if (!snapshotUrl) return;

    try {
        const response = await fetch(snapshotUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(await responseError(response));
        const snapshot = await response.json();
        const metrics = snapshot.metrics;

        document.querySelectorAll('[data-dashboard-stat]').forEach((node) => {
            const value = metrics[node.dataset.dashboardStat];
            node.textContent = node.dataset.dashboardStat === 'lastActivity'
                ? (value ? new Intl.DateTimeFormat(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false }).format(new Date(value)) : '—')
                : (typeof value === 'number' ? value.toLocaleString() : value);
        });
        document.querySelectorAll('[data-archive-filter-count]').forEach((node) => {
            node.textContent = Number(metrics[node.dataset.archiveFilterCount] ?? 0).toLocaleString();
        });

        const recordsRoot = document.querySelector('[data-archive-records]');
        if (!recordsRoot) return;
        recordsRoot.replaceChildren();
        recordsRoot.hidden = snapshot.files.length === 0;
        const emptyState = document.querySelector('[data-empty-state]');
        if (emptyState) emptyState.hidden = snapshot.files.length > 0;
        const fullArchiveLink = document.querySelector('[data-archive-open]')?.closest('.archive-full-link-row');
        if (fullArchiveLink) fullArchiveLink.hidden = snapshot.files.length === 0;

        const groups = new Map();
        snapshot.files.forEach((file) => {
            if (!groups.has(file.month_key)) groups.set(file.month_key, { label: file.month_label, files: [] });
            groups.get(file.month_key).files.push(file);
        });
        groups.forEach((group) => {
            const section = document.createElement('section');
            section.className = 'archive-month';
            section.dataset.archiveMonth = '';
            const heading = document.createElement('h3');
            heading.className = 'archive-month-heading';
            heading.textContent = group.label;
            const count = document.createElement('span');
            count.textContent = `${group.files.length} FILE${group.files.length === 1 ? '' : 'S'}`;
            heading.append(count);
            section.append(heading);

            group.files.forEach((file) => {
                const categoryGroup = ['image', 'video', 'audio'].includes(file.category)
                    ? 'media'
                    : (['pdf', 'text', 'code'].includes(file.category) ? 'documents' : 'other');
                const row = document.createElement('article');
                row.className = 'archive-record';
                row.dataset.archiveRecord = '';
                row.dataset.archiveSearch = `${file.name} ${file.mime} ${file.category}`.toLocaleLowerCase();
                row.dataset.archiveCategory = categoryGroup;
                const name = document.createElement('div');
                name.className = 'archive-record-name';
                const icon = document.createElement('span');
                icon.className = 'archive-file-icon';
                icon.textContent = (file.extension || 'FILE').toUpperCase();
                icon.setAttribute('aria-hidden', 'true');
                const details = document.createElement('span');
                const title = document.createElement('strong');
                title.textContent = file.name;
                const mime = document.createElement('small');
                mime.textContent = file.mime;
                details.append(title, mime);
                name.append(icon, details);

                const category = document.createElement('span');
                category.className = 'archive-record-category';
                category.textContent = file.category.toUpperCase();
                const method = document.createElement('span');
                method.className = `upload-method-badge upload-method-${file.upload_method || 'unknown'}`;
                method.textContent = (file.upload_method || 'UNKNOWN').toUpperCase();
                const date = document.createElement('time');
                date.className = 'archive-record-date';
                date.dateTime = file.created_at;
                date.textContent = file.date_label;
                const size = document.createElement('span');
                size.className = 'archive-record-size';
                size.textContent = formatBytes(file.size);
                const actions = document.createElement('div');
                actions.className = 'archive-record-actions';
                const open = document.createElement('button');
                open.type = 'button';
                open.dataset.archivePreview = file.uuid;
                open.textContent = 'OPEN';
                open.setAttribute('aria-label', `Preview ${file.name}`);
                const download = document.createElement('a');
                download.href = file.download_url;
                download.textContent = '↓';
                download.setAttribute('aria-label', `Download ${file.name}`);
                actions.append(open, download);
                row.append(name, category, method, date, size, actions);
                section.append(row);
            });
            recordsRoot.append(section);
        });

        const summary = document.querySelector('[data-dashboard-record-summary]');
        if (summary) summary.textContent = `${snapshot.files.length} LATEST OF ${Number(metrics.fileCount).toLocaleString()} RECORDS · ${groups.size} GROUPS`;
        filterArchive();
        if (archiveBrowser && !archiveBrowser.hidden) window.Livewire?.dispatch('refresh-archive');
    } catch (error) {
        window.vaultAlert({ type: 'warning', message: error instanceof Error ? error.message : 'Realtime archive refresh failed.' });
    }
};

listenForPageEvent(window, 'vault:file-deleted', (event) => {
    if (previewBackdrop?.dataset.fileUuid === event.detail.uuid) closePreview();
});
listenForPageEvent(window, 'vault:archive-index-updated', (event) => {
    window.Livewire?.dispatch('refresh-archive');
    window.Livewire?.dispatch('refresh-private-preview', event.detail.uuid);
});

listenForPageEvent(window, 'vault-file-preview-ready', (event) => {
    const file = event.detail.file;
    if (!file) return;

    previewTitle.textContent = file.name;
    previewMeta.textContent = `${file.mime.toUpperCase()} · ${formatBytes(file.size)}`;
    previewContent.replaceChildren(...makePreviewElement(file));
    previewDownload.href = file.download_url;
    previewDownload.download = file.name;
    previewBackdrop.dataset.fileUuid = event.detail.uuid;
    previewBackdrop.hidden = false;
    document.querySelector('[data-preview-close]')?.focus();
});

listenForPageEvent(window, 'archive-preview-ready', (event) => {
    const browser = document.querySelector('[data-archive-browser]');
    const preview = browser?.querySelector('[data-full-archive-preview-content]');
    if (!browser || !preview) return;

    const file = event.detail.file;
    const previewView = browser.querySelector('[data-full-archive-preview-view]');
    const previewEmpty = browser.querySelector('[data-full-archive-preview-empty]');
    const title = browser.querySelector('[data-full-archive-preview-title]');
    const meta = browser.querySelector('[data-full-archive-preview-meta]');
    const type = browser.querySelector('[data-full-archive-preview-type]');
    const download = browser.querySelector('[data-full-archive-preview-download]');
    if (!file || !previewView || !previewEmpty || !title || !meta || !type || !download) return;

    title.textContent = file.name;
    meta.textContent = `${file.mime.toUpperCase()} · ${formatBytes(file.size)}`;
    type.textContent = file.name.split('.').pop().slice(0, 4).toUpperCase() || 'FILE';
    download.href = file.download_url;
    download.download = file.name;
    preview.dataset.fileUuid = event.detail.uuid;
    preview.replaceChildren(...makePreviewElement(file));
    previewEmpty.hidden = true;
    previewView.hidden = false;
    browser.querySelectorAll('.archive-record.is-selected').forEach((row) => row.classList.remove('is-selected'));
    browser.querySelector(`[data-full-archive-preview="${CSS.escape(event.detail.uuid)}"]`)?.closest('.archive-record')?.classList.add('is-selected');
});
listenForPageEvent(window, 'archive-closed', () => {
    document.body.classList.remove('archive-browser-open');
});

document.querySelector('[data-archive-open]')?.addEventListener('click', openFullArchive);
listenForPageEvent(document, 'keydown', (event) => {
    if (event.altKey && !event.ctrlKey && !event.metaKey && !event.repeat && event.key.toLowerCase() === 'a') {
        event.preventDefault();
        openFullArchive();
    }
});
listenForPageEvent(document, 'keydown', (event) => {
    if (event.key === 'Escape' && archiveBrowser && !archiveBrowser.hidden) closeFullArchive();
});

const portableCalculatorTemplate = document.querySelector('#portable-calculator-template');
const portableCalculatorStorageKey = 'vcs.portable-calculator.v1';
let portableCalculator = null;
let portableCalculatorExpression = '';
let portableCalculatorCompleted = false;

const savePortableCalculator = () => {
    if (!portableCalculator) return;
    const rect = portableCalculator.getBoundingClientRect();
    try {
        sessionStorage.setItem(portableCalculatorStorageKey, JSON.stringify({
            open: true,
            expression: portableCalculatorExpression,
            completed: portableCalculatorCompleted,
            left: rect.left,
            top: rect.top,
        }));
    } catch {
        showToast('Unable to preserve the portable calculator in this browser session.', 'warning');
    }
};

const updatePortableCalculator = () => {
    if (!portableCalculator) return;
    const expressionDisplay = portableCalculator.querySelector('[data-portable-calc-expression]');
    const resultDisplay = portableCalculator.querySelector('[data-portable-calc-result]');
    expressionDisplay.textContent = portableCalculatorExpression || '0';
    if (!portableCalculatorExpression) {
        resultDisplay.textContent = '0';
        return;
    }
    try {
        resultDisplay.textContent = evaluateTriggerExpression(portableCalculatorExpression) ?? '—';
    } catch {
        resultDisplay.textContent = 'Error';
    }
};

const closePortableCalculator = () => {
    if (!portableCalculator) return;
    portableCalculator.remove();
    portableCalculator = null;
    portableCalculatorExpression = '';
    portableCalculatorCompleted = false;
    try {
        sessionStorage.removeItem(portableCalculatorStorageKey);
    } catch {
        showToast('Unable to clear the saved portable calculator state.', 'error');
    }
};

const openPortableCalculator = (savedState = null) => {
    if (!(portableCalculatorTemplate instanceof HTMLTemplateElement)) return;
    if (portableCalculator) {
        portableCalculator.style.zIndex = String(++nextZIndex);
        return;
    }

    portableCalculator = portableCalculatorTemplate.content.firstElementChild.cloneNode(true);
    portableCalculatorExpression = typeof savedState?.expression === 'string'
        && /^[\d.eE+\-*/%()]*$/.test(savedState.expression)
        ? savedState.expression.slice(0, 120)
        : '';
    portableCalculatorCompleted = savedState?.completed === true;
    portableCalculator.style.zIndex = String(++nextZIndex);
    if (Number.isFinite(savedState?.left) && Number.isFinite(savedState?.top)) {
        portableCalculator.style.left = `${savedState.left}px`;
        portableCalculator.style.top = `${savedState.top}px`;
        portableCalculator.style.right = 'auto';
    }
    shellLayer?.append(portableCalculator);
    const calculatorRect = portableCalculator.getBoundingClientRect();
    const maxLeft = Math.max(8, window.innerWidth - calculatorRect.width - 8);
    const maxTop = Math.max(8, window.innerHeight - calculatorRect.height - 8);
    portableCalculator.style.left = `${Math.min(Math.max(8, calculatorRect.left), maxLeft)}px`;
    portableCalculator.style.top = `${Math.min(Math.max(8, calculatorRect.top), maxTop)}px`;
    portableCalculator.style.right = 'auto';
    portableCalculator.style.bottom = 'auto';

    portableCalculator.querySelector('[data-portable-calc-close]').addEventListener('click', closePortableCalculator);
    portableCalculator.addEventListener('pointerdown', () => {
        portableCalculator.style.zIndex = String(++nextZIndex);
    });
    portableCalculator.querySelector('[data-portable-calc-keys]').addEventListener('click', (event) => {
        const button = event.target.closest('[data-portable-calc-key]');
        if (!button) return;
        const action = button.dataset.portableCalcKey;
        const value = button.dataset.value ?? '';

        if (action === 'clear') {
            portableCalculatorExpression = '';
            portableCalculatorCompleted = false;
        } else if (action === 'backspace') {
            portableCalculatorExpression = portableCalculatorExpression.slice(0, -1);
            portableCalculatorCompleted = false;
        } else if (action === 'digit') {
            if (portableCalculatorCompleted) {
                portableCalculatorExpression = '';
                portableCalculatorCompleted = false;
            }
            const currentNumber = portableCalculatorExpression.split(/[+\-*/%()]/).at(-1) ?? '';
            if (value === '.' && currentNumber.includes('.')) return;
            portableCalculatorExpression += value;
        } else if (action === 'operator') {
            portableCalculatorCompleted = false;
            if (!portableCalculatorExpression && value !== '-') return;
            if (/[+\-*/%]$/.test(portableCalculatorExpression)) {
                portableCalculatorExpression = portableCalculatorExpression.slice(0, -1);
            }
            portableCalculatorExpression += value;
        } else if (action === 'equals') {
            try {
                const result = evaluateTriggerExpression(portableCalculatorExpression);
                if (result === null) throw new Error('Enter a valid calculation.');
                portableCalculatorExpression = result;
                portableCalculatorCompleted = true;
            } catch (error) {
                showToast(error instanceof Error ? error.message : 'Unable to calculate this expression.', 'error');
                return;
            }
        }

        updatePortableCalculator();
        savePortableCalculator();
    });

    const dragHandle = portableCalculator.querySelector('[data-portable-calc-drag]');
    dragHandle.addEventListener('pointerdown', (event) => {
        if (event.target.closest('button')) return;
        event.preventDefault();
        const rect = portableCalculator.getBoundingClientRect();
        const startX = event.clientX;
        const startY = event.clientY;
        const left = rect.left;
        const top = rect.top;
        dragHandle.setPointerCapture(event.pointerId);
        const move = (moveEvent) => {
            const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
            const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
            portableCalculator.style.left = `${Math.min(Math.max(8, left + moveEvent.clientX - startX), maxLeft)}px`;
            portableCalculator.style.top = `${Math.min(Math.max(8, top + moveEvent.clientY - startY), maxTop)}px`;
            portableCalculator.style.right = 'auto';
            portableCalculator.style.bottom = 'auto';
        };
        const finish = () => {
            dragHandle.removeEventListener('pointermove', move);
            dragHandle.removeEventListener('pointerup', finish);
            dragHandle.removeEventListener('pointercancel', finish);
            savePortableCalculator();
        };
        dragHandle.addEventListener('pointermove', move);
        dragHandle.addEventListener('pointerup', finish);
        dragHandle.addEventListener('pointercancel', finish);
    });

    updatePortableCalculator();
    savePortableCalculator();
};

document.querySelectorAll('[data-portable-calc-open]').forEach((button) => {
    button.addEventListener('click', () => openPortableCalculator());
});
listenForPageEvent(document, 'keydown', (event) => {
    if (event.ctrlKey && !event.shiftKey && !event.altKey && event.key.toLowerCase() === 'q') {
        event.preventDefault();
        if (portableCalculator) closePortableCalculator();
        else openPortableCalculator();
    }
});
try {
    const savedCalculator = JSON.parse(sessionStorage.getItem(portableCalculatorStorageKey) ?? 'null');
    if (savedCalculator?.open === true) openPortableCalculator(savedCalculator);
} catch {
    showToast('Saved portable calculator state could not be restored.', 'warning');
}
document.querySelector('.sidebar-logout')?.addEventListener('submit', () => {
    try {
        sessionStorage.removeItem(shellWindowStorageKey);
        sessionStorage.removeItem(portableCalculatorStorageKey);
    } catch {
        showToast('Temporary window state could not be cleared before sign out.', 'warning');
    }
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
            'launch [file] — preview a file; launch vs opens another console; launch calc opens the portable calculator.',
            'del [file] — delete a file after yes/no confirmation.',
            'trigger [text|calculation] — display text or calculate arithmetic; quote text to keep it literal.',
            'exit — close this console window.',
            '?help — show this command reference.',
            'All paths stay inside the private /root directory. Existing destinations are never overwritten.',
        ].forEach((line) => appendOutput(windowElement, line, 'shell-output-muted'));
        return;
    }

    if (normalizedCommand === '?shortcut') {
        [
            'Application keyboard shortcuts:',
            'Ctrl + Shift + X — toggle Vault Shell windows.',
            'Ctrl + Q — open or close the portable basic calculator.',
            'Alt + A — open the full archive window.',
            '/ — focus archive search when not typing in a field.',
            'Escape — close an open archive or file preview, or close mobile navigation.',
            'On the /calculator page: digits and operators enter input; Enter or = calculates; Backspace deletes; Escape clears.',
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
        if (args[0].toLowerCase() === 'calc') {
            appendOutput(windowElement, 'Opening the portable calculator…', 'shell-output-success');
            openPortableCalculator();
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

const shellWindowStorageKey = 'vcs.shell-windows.v1';

const saveShellWindows = () => {
    try {
        const state = [...shellWindows].map((windowElement) => {
            const rect = windowElement.getBoundingClientRect();
            const transcript = [...windowElement.querySelector('[data-shell-output]').children]
                .filter((line) => line.matches('.shell-output-line, .shell-command-echo'))
                .slice(-80)
                .map((line) => ({ className: line.className, text: (line.textContent ?? '').slice(0, 2000) }));

            return {
                cwd: (windowElement.dataset.cwd ?? '/root').slice(0, 500),
                left: rect.left,
                top: rect.top,
                width: rect.width,
                height: rect.height,
                fullscreen: windowElement.classList.contains('is-fullscreen'),
                hidden: windowElement.classList.contains('is-hidden'),
                transcript,
            };
        });
        sessionStorage.setItem(shellWindowStorageKey, JSON.stringify(state));
    } catch {
        showToast('Unable to preserve the Vault Shell windows in this browser session.', 'warning');
    }
};

const readShellWindows = () => {
    try {
        const saved = JSON.parse(sessionStorage.getItem(shellWindowStorageKey) ?? '[]');
        return Array.isArray(saved) ? saved.slice(0, 10) : [];
    } catch {
        showToast('Saved Vault Shell window state could not be restored.', 'warning');
        return [];
    }
};

const closeWindow = (windowElement) => {
    shellWindows.delete(windowElement);
    if (pendingDelete?.window === windowElement) pendingDelete = null;
    saveShellWindows();
    windowElement.classList.add('is-closing');
    window.setTimeout(() => windowElement.remove(), 180);
};

const createShellWindow = (savedState = null) => {
    if (!shellLayer || !(shellTemplate instanceof HTMLTemplateElement)) return null;

    const windowElement = shellTemplate.content.firstElementChild.cloneNode(true);
    const instance = nextWindowNumber++;
    windowElement.dataset.cwd = typeof savedState?.cwd === 'string'
        && savedState.cwd.length <= 500
        && (savedState.cwd === '/root' || savedState.cwd.startsWith('/root/'))
        ? savedState.cwd
        : '/root';
    windowElement.querySelector('.shell-window-id').textContent = `VS / INSTANCE ${String(instance).padStart(2, '0')}`;
    windowElement.querySelector('[data-shell-prompt]').textContent = `${appAcronym} vcs:/root>`;
    windowElement.querySelector('[data-shell-input]').id = `shell-command-${instance}`;
    windowElement.querySelector('[data-shell-prompt]').htmlFor = `shell-command-${instance}`;
    if (Number.isFinite(savedState?.left) && Number.isFinite(savedState?.top)) {
        windowElement.style.left = `${savedState.left}px`;
        windowElement.style.top = `${savedState.top}px`;
        windowElement.style.right = 'auto';
        windowElement.style.bottom = 'auto';
    }
    if (Number.isFinite(savedState?.width)) windowElement.style.width = `${savedState.width}px`;
    if (Number.isFinite(savedState?.height)) windowElement.style.height = `${savedState.height}px`;
    if (savedState?.fullscreen === true) windowElement.classList.add('is-fullscreen');
    if (savedState?.hidden === true) windowElement.classList.add('is-hidden');
    windowElement.style.zIndex = String(++nextZIndex);
    shellLayer.append(windowElement);
    if (!windowElement.classList.contains('is-fullscreen')) {
        const rect = windowElement.getBoundingClientRect();
        clampWindow(windowElement, rect.left, rect.top);
    }
    shellWindows.add(windowElement);

    const output = windowElement.querySelector('[data-shell-output]');
    if (Array.isArray(savedState?.transcript)) {
        output.replaceChildren();
        savedState.transcript.slice(-200).forEach((item) => {
            if (typeof item?.text !== 'string') return;
            const line = document.createElement('div');
            line.className = item.className === 'shell-command-echo'
                ? 'shell-command-echo'
                : `shell-output-line${/^shell-output-line(?: shell-output-[a-z]+)?$/.test(item.className) ? item.className.slice('shell-output-line'.length) : ''}`;
            line.textContent = item.text;
            output.append(line);
        });
    }

    const closeButton = windowElement.querySelector('[data-shell-close]');
    closeButton.addEventListener('click', () => closeWindow(windowElement));
    windowElement.querySelector('[data-shell-fullscreen]').addEventListener('click', () => {
        windowElement.classList.toggle('is-fullscreen');
        if (!windowElement.classList.contains('is-fullscreen')) {
            const rect = windowElement.getBoundingClientRect();
            clampWindow(windowElement, rect.left, rect.top);
        }
        saveShellWindows();
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
        saveShellWindows();
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
            saveShellWindows();
        };
        dragHandle.addEventListener('pointermove', move);
        dragHandle.addEventListener('pointerup', finish);
        dragHandle.addEventListener('pointercancel', finish);
    });

    fetchDirectory(windowElement, windowElement.dataset.cwd).catch((error) => {
        appendOutput(windowElement, error instanceof Error ? error.message : 'Unable to read the private directory.', 'shell-output-error');
    });
    window.requestAnimationFrame(() => {
        windowElement.classList.add('is-open');
        if (!windowElement.classList.contains('is-hidden')) input.focus();
    });

    saveShellWindows();
    return windowElement;
};

document.querySelectorAll('[data-shell-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const existing = [...shellWindows].at(-1);
        if (existing) {
            existing.classList.remove('is-hidden');
            focusWindow(existing);
            existing.querySelector('[data-shell-input]').focus();
            saveShellWindows();
            return;
        }
        createShellWindow();
    });
});

listenForPageEvent(document, 'keydown', (event) => {
    if (event.ctrlKey && event.shiftKey && event.key.toLowerCase() === 'x') {
        event.preventDefault();
        const visibleWindows = [...shellWindows].filter((windowElement) => !windowElement.classList.contains('is-hidden'));
        if (visibleWindows.length > 0) {
            visibleWindows.forEach((windowElement) => windowElement.classList.add('is-hidden'));
            saveShellWindows();
            return;
        }

        const existing = [...shellWindows].at(-1);
        if (existing) {
            existing.classList.remove('is-hidden');
            focusWindow(existing);
            existing.querySelector('[data-shell-input]').focus();
            saveShellWindows();
        } else {
            createShellWindow();
        }
    }
});

readShellWindows().forEach((state) => {
    if (typeof state?.cwd !== 'string' || !state.cwd.startsWith('/root')) return;
    createShellWindow(state);
});

document.querySelector('[data-preview-close]')?.addEventListener('click', closePreview);
previewBackdrop?.addEventListener('click', (event) => {
    if (event.target === previewBackdrop) closePreview();
});
listenForPageEvent(document, 'keydown', (event) => {
    if (event.key === 'Escape' && previewBackdrop && !previewBackdrop.hidden) closePreview();
});

listenForPageEvent(window, 'resize', () => {
    shellWindows.forEach((windowElement) => {
        if (windowElement.classList.contains('is-fullscreen')) return;
        const rect = windowElement.getBoundingClientRect();
        clampWindow(windowElement, rect.left, rect.top);
    });
    if (portableCalculator) {
        const rect = portableCalculator.getBoundingClientRect();
        const maxLeft = Math.max(8, window.innerWidth - rect.width - 8);
        const maxTop = Math.max(8, window.innerHeight - rect.height - 8);
        portableCalculator.style.left = `${Math.min(Math.max(8, rect.left), maxLeft)}px`;
        portableCalculator.style.top = `${Math.min(Math.max(8, rect.top), maxTop)}px`;
        savePortableCalculator();
    }
});

const uploadInput = document.querySelector('[data-upload-input]');
const uploadSelection = document.querySelector('[data-upload-selection]');
const uploadSubmit = document.querySelector('[data-upload-submit]');
const uploadDropzone = document.querySelector('[data-upload-dropzone]');
const manualUploadForm = document.querySelector('[data-manual-upload]');
const uploadMaxMb = Number(manualUploadForm?.dataset.maxUploadMb ?? 0);
const videoMaxMb = Number(manualUploadForm?.dataset.maxVideoUploadMb ?? 0);
const phpUploadMaxBytes = Number(manualUploadForm?.dataset.phpUploadMaxBytes ?? 0);
const phpPostMaxBytes = Number(manualUploadForm?.dataset.phpPostMaxBytes ?? 0);
const probableVideoExtensions = new Set(['mp4', 'mov', 'mkv', 'avi', 'wmv', 'flv', 'webm', 'mpeg', 'mpg', '3gp', 'm4v', 'ts', 'ogv']);

const displaySelectedFiles = () => {
    if (!(uploadInput instanceof HTMLInputElement) || !uploadSelection) return;

    const files = [...(uploadInput.files ?? [])];
    const allowedFileBytes = phpUploadMaxBytes > 0
        ? Math.min(uploadMaxMb * 1024 * 1024, phpUploadMaxBytes)
        : uploadMaxMb * 1024 * 1024;
    const tooLargeFile = files.find((file) => file.size > allowedFileBytes);
    const tooLargeVideo = files.find((file) => {
        const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
        const isVideo = file.type.startsWith('video/') || probableVideoExtensions.has(extension);
        const videoLimit = videoMaxMb > 0 ? Math.min(videoMaxMb * 1024 * 1024, allowedFileBytes) : allowedFileBytes;
        return isVideo && file.size > videoLimit;
    });
    const videosRequireChunking = files.some((file) => (
        file.type.startsWith('video/')
        || probableVideoExtensions.has(file.name.split('.').pop()?.toLowerCase() ?? '')
    ));
    const requestTooLarge = phpPostMaxBytes > 0 && files.reduce((total, file) => total + file.size, 0) + 512 * 1024 > phpPostMaxBytes;
    if (videosRequireChunking) {
        uploadSelection.textContent = 'Use the background video upload control for video files.';
        window.vaultAlert({ type: 'info', message: 'Video files must use the chunked background uploader.' });
    } else if (tooLargeFile || tooLargeVideo || requestTooLarge) {
        const effectiveBytes = tooLargeVideo
            ? (videoMaxMb > 0 ? Math.min(videoMaxMb * 1024 * 1024, allowedFileBytes) : allowedFileBytes)
            : allowedFileBytes;
        const limit = Math.floor(effectiveBytes / (1024 * 1024));
        const label = requestTooLarge && !tooLargeFile && !tooLargeVideo ? 'The selected files exceed the server request limit.' : (tooLargeVideo ? 'Video exceeds' : 'File exceeds');
        uploadSelection.textContent = requestTooLarge && !tooLargeFile && !tooLargeVideo
            ? `${label} Remove files before continuing.`
            : `${label} the ${limit} MB upload limit. Remove the oversized file before continuing.`;
        window.vaultAlert({ type: 'error', message: requestTooLarge && !tooLargeFile && !tooLargeVideo ? label : `${label} the maximum allowed size of ${limit} MB.` });
    } else {
        uploadSelection.textContent = files.length
            ? `${files.length} file${files.length === 1 ? '' : 's'} selected: ${files.map((file) => file.name).join(', ')}`
            : 'No files selected.';
    }
    if (uploadSubmit instanceof HTMLButtonElement) uploadSubmit.disabled = files.length === 0 || files.length > 20 || videosRequireChunking || Boolean(tooLargeFile || tooLargeVideo || requestTooLarge);
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
manualUploadForm?.addEventListener('submit', () => {
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

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const addPersonImageInput = (list) => {
    const input = document.createElement('input');
    input.type = 'file';
    input.name = 'other_images[]';
    input.accept = 'image/jpeg,image/png,image/webp,image/gif';
    input.className = 'visually-hidden-file';
    input.dataset.personImageInput = '';
    list.append(input);
    input.click();
};

document.querySelector('[data-main-image-trigger]')?.addEventListener('click', () => {
    document.querySelector('[data-main-image-input]')?.click();
});
document.querySelector('[data-main-image-input]')?.addEventListener('change', (event) => {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file) return;
    const image = document.createElement('img');
    image.src = URL.createObjectURL(file);
    image.alt = 'Selected main image';
    image.dataset.mainImagePreview = '';
    const placeholder = document.querySelector('[data-main-image-placeholder]');
    if (placeholder) placeholder.replaceWith(image);
    else document.querySelector('[data-main-image-preview]')?.replaceWith(image);
});
document.querySelector('[data-add-person-image]')?.addEventListener('click', (event) => {
    addPersonImageInput(event.currentTarget.closest('.record-form-visual').querySelector('[data-person-gallery-upload-list]'));
});
listenForPageEvent(document, 'change', (event) => {
    const input = event.target.closest('[data-person-image-input]');
    if (!input || !input.files?.[0]) return;
    const list = input.closest('[data-person-gallery-upload-list]');
    const file = input.files[0];
    const card = document.createElement('div');
    card.className = 'new-upload-image';
    const preview = document.createElement('img');
    preview.src = URL.createObjectURL(file);
    preview.alt = file.name;
    const name = document.createElement('span');
    name.textContent = file.name;
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.textContent = '×';
    remove.setAttribute('aria-label', `Remove ${file.name}`);
    remove.addEventListener('click', () => card.remove());
    card.append(input, preview, name, remove);
    list.append(card);
});

listenForPageEvent(document, 'click', async (event) => {
    const button = event.target.closest('[data-delete-person-image], [data-delete-case-image]');
    if (!button) return;
    const confirmed = await window.vaultConfirm({
        title: 'Delete private media',
        message: 'This permanently removes the selected image or video from this record.',
        confirmLabel: 'DELETE MEDIA',
        destructive: true,
    });
    if (!confirmed) return;
    button.disabled = true;
    try {
        const response = await fetch(button.dataset.deletePersonImage ?? button.dataset.deleteCaseImage, {
            method: 'DELETE',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
        });
        if (!response.ok) throw new Error(await responseError(response));
        button.closest('[data-existing-image], [data-existing-case-image]')?.remove();
        showToast('Image removed from the private record.', 'success');
    } catch (error) {
        button.disabled = false;
        showToast(error instanceof Error ? error.message : 'Unable to remove this image.', 'error');
    }
});

const createCategoryEditor = (container, template) => {
    if (container.dataset.nextCategoryIndex === undefined) {
        const existingIndices = [...container.querySelectorAll('[data-case-category]')]
            .map((category) => Number(category.dataset.caseIndex))
            .filter(Number.isFinite);
        container.dataset.nextCategoryIndex = String(existingIndices.length ? Math.max(...existingIndices) + 1 : 0);
    }
    const categoryIndex = Number(container.dataset.nextCategoryIndex);
    if (categoryIndex >= 30) {
        showToast('A case file can have up to 30 image categories.', 'warning');
        return;
    }
    container.dataset.nextCategoryIndex = String(categoryIndex + 1);
    const category = template.content.firstElementChild.cloneNode(true);
    category.dataset.caseIndex = String(categoryIndex);
    category.querySelector('[data-category-number]').textContent = String(categoryIndex + 1).padStart(2, '0');
    category.querySelectorAll('[data-category-field]').forEach((field) => {
        field.name = `categories[${categoryIndex}][${field.dataset.categoryField}]`;
    });
    container.append(category);
};

document.querySelectorAll('[data-case-form-layout]').forEach((layout) => {
    const categoryList = layout.querySelector('[data-case-category-list]');
    const categoryTemplate = layout.querySelector('[data-case-category-template]');
    const imageTemplate = layout.querySelector('[data-case-image-template]');
    layout.querySelector('[data-add-case-category]')?.addEventListener('click', () => createCategoryEditor(categoryList, categoryTemplate));
    layout.addEventListener('click', async (event) => {
        const removeCategory = event.target.closest('[data-remove-category]');
        if (removeCategory) {
            const category = removeCategory.closest('[data-case-category]');
            const idInput = category.querySelector('input[name$="[id]"]');
            if (idInput) {
                const confirmed = await window.vaultConfirm({
                    title: 'Delete evidence category',
                    message: 'Saving this change permanently removes the category and its attached media.',
                    confirmLabel: 'DELETE CATEGORY',
                    destructive: true,
                });
                if (!confirmed) return;
                const deleted = document.createElement('input');
                deleted.type = 'hidden';
                deleted.name = 'delete_categories[]';
                deleted.value = idInput.value;
                layout.closest('form').append(deleted);
            }
            category.remove();
            return;
        }

        const addImage = event.target.closest('[data-add-case-image]');
        if (addImage) {
            const category = addImage.closest('[data-case-category]');
            const index = category.dataset.caseIndex;
            if (index === undefined) return;
            const input = imageTemplate.content.querySelector('input').cloneNode(true);
            input.name = `categories[${index}][images][]`;
            category.querySelector('[data-case-image-list]').append(input);
            input.click();
        }

        const removeImage = event.target.closest('[data-remove-case-image]');
        if (removeImage) removeImage.closest('[data-new-case-image]')?.remove();
    });
    layout.addEventListener('change', (event) => {
        const input = event.target.closest('[data-case-image-input]');
        if (!input || !input.files?.[0]) return;
        const list = input.closest('[data-case-image-list]');
        const file = input.files[0];
        const wrapper = imageTemplate.content.firstElementChild.cloneNode(true);
        const preview = document.createElement('img');
        preview.src = URL.createObjectURL(file);
        preview.alt = file.name;
        wrapper.querySelector('input')?.remove();
        wrapper.querySelector('[data-case-image-name]').textContent = file.name;
        wrapper.insertBefore(input, wrapper.querySelector('[data-case-image-name]'));
        wrapper.insertBefore(preview, wrapper.querySelector('[data-case-image-name]'));
        wrapper.dataset.newCaseImage = '';
        list?.append(wrapper);
    });
});

listenForPageEvent(document, 'click', (event) => {
    const personVideoButton = event.target.closest('[data-add-person-video]');
    if (personVideoButton) {
        personVideoButton.closest('form')?.querySelector('[data-person-video-upload] [data-video-upload-input]')?.click();
        return;
    }

    const caseVideoButton = event.target.closest('[data-add-case-video]');
    if (caseVideoButton) {
        caseVideoButton.closest('[data-case-category]')?.querySelector('[data-video-upload-input]')?.click();
    }
});

document.querySelectorAll('[data-glb-form]').forEach((form) => {
    const input = form.querySelector('[data-glb-input]');
    form.querySelector('[data-glb-select]')?.addEventListener('click', () => input.click());
    input.addEventListener('change', () => {
        const name = form.querySelector('[data-glb-file-name]');
        name.textContent = input.files?.[0]?.name ?? 'No file selected';
    });
});

const imagePinDialog = document.querySelector('[data-image-pin-dialog]');
const imagePinDigits = [...(imagePinDialog?.querySelectorAll('[data-pin-digit]') ?? [])];
const imagePinError = imagePinDialog?.querySelector('[data-pin-error]');
const imagePinForm = imagePinDialog?.querySelector('[data-image-pin-form]');
const submitImagePin = imagePinDialog?.querySelector('[data-submit-image-pin]');
const profileImageToggle = document.querySelector('[data-profile-visual-toggle]');
const profileMainImage = document.querySelector('[data-profile-main-image]');
let personViewablesUnlocked = false;

const loadProtectedImage = (image) => {
    if (image && !image.getAttribute('src') && image.dataset.imageUrl) {
        image.src = image.dataset.imageUrl;
    }
};

const activatePersonViewables = () => {
    personViewablesUnlocked = true;
    document.querySelectorAll('[data-protected-image]').forEach((image) => {
        image.hidden = false;
        if (!image.closest('[hidden]')) loadProtectedImage(image);
        image.closest('[data-image-viewer]')?.querySelector('[data-image-lock-placeholder]')?.remove();
    });
    document.querySelector('[data-profile-gallery]')?.classList.remove('is-locked');
    document.querySelectorAll('[data-video-viewer][data-video-pin-required]').forEach((video) => {
        video.querySelector('[data-image-lock-placeholder]')?.remove();
        video.setAttribute('aria-label', `Play ${video.dataset.videoTitle ?? 'private video'}`);
    });
    if (profileImageToggle instanceof HTMLButtonElement) {
        profileImageToggle.disabled = false;
        profileImageToggle.innerHTML = 'VIEW MAIN IMAGE <span>↗</span>';
    }
    const unlockButton = document.querySelector('[data-open-image-pin]');
    if (unlockButton) {
        unlockButton.textContent = 'VIEWABLES UNLOCKED';
        unlockButton.disabled = true;
        unlockButton.setAttribute('aria-pressed', 'true');
    }
};

const authenticateImagePin = async () => {
    if (!imagePinDialog || !submitImagePin || submitImagePin.disabled) return;
    const pin = imagePinDigits.map((input) => input.value).join('');
    if (!/^\d{4}$/.test(pin)) {
        if (imagePinError) imagePinError.textContent = 'Enter all four PIN digits.';
        imagePinDigits.find((input) => !input.value)?.focus();
        return;
    }
    submitImagePin.disabled = true;
    submitImagePin.textContent = 'VERIFYING...';
    if (imagePinError) imagePinError.textContent = '';
    try {
        const response = await fetch(imagePinDialog.dataset.unlockUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ pin }),
        });
        if (!response.ok) throw new Error(await responseError(response));
        activatePersonViewables();
        imagePinDigits.forEach((input) => { input.value = ''; });
        imagePinDialog.close();
    } catch (error) {
        if (imagePinError) imagePinError.textContent = error instanceof Error ? error.message : 'PIN verification failed.';
        imagePinDigits.forEach((input) => { input.value = ''; });
        imagePinDigits[0]?.focus();
    } finally {
        if (imagePinDialog.open) {
            submitImagePin.disabled = false;
            submitImagePin.textContent = 'AUTHENTICATE PIN';
        }
    }
};

document.querySelector('[data-open-image-pin]')?.addEventListener('click', (event) => {
    if (personViewablesUnlocked) {
        event.currentTarget.disabled = true;
        return;
    }
    imagePinDialog?.showModal();
    imagePinDigits[0]?.focus();
});
imagePinForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    authenticateImagePin();
});
imagePinDigits.forEach((input, index) => {
    input.addEventListener('input', () => {
        const enteredDigits = input.value.replace(/\D/g, '').slice(0, 4);
        if (enteredDigits.length > 1) {
            [...enteredDigits].forEach((digit, digitIndex) => {
                if (imagePinDigits[digitIndex]) imagePinDigits[digitIndex].value = digit;
            });
            imagePinDigits[Math.min(enteredDigits.length, 3)]?.focus();
            return;
        }
        input.value = enteredDigits.slice(-1);
        if (input.value) imagePinDigits[index + 1]?.focus();
    });
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value) imagePinDigits[index - 1]?.focus();
    });
    input.addEventListener('paste', (event) => {
        const pasted = event.clipboardData?.getData('text').replace(/\D/g, '').slice(0, 4);
        if (!pasted) return;
        event.preventDefault();
        [...pasted].forEach((digit, digitIndex) => {
            if (imagePinDigits[digitIndex]) imagePinDigits[digitIndex].value = digit;
        });
        imagePinDigits[Math.min(pasted.length, 3)]?.focus();
    });
});
profileImageToggle?.addEventListener('click', () => {
    if (!profileMainImage) return;
    const showImage = profileMainImage.hidden;
    if (showImage) loadProtectedImage(profileMainImage.querySelector('[data-protected-image]'));
    profileMainImage.hidden = !showImage;
    document.querySelectorAll('[data-profile-model]').forEach((model) => { model.hidden = showImage; });
    profileImageToggle.innerHTML = showImage ? 'VIEW 3D MODEL <span>↗</span>' : 'VIEW MAIN IMAGE <span>↗</span>';
});

const imageViewerDialog = document.querySelector('[data-image-viewer-dialog]');
const imageViewer = imageViewerDialog?.querySelector('[data-image-viewer-image]');
const imageViewerTitle = imageViewerDialog?.querySelector('[data-image-viewer-title]');
const imageViewerDownload = imageViewerDialog?.querySelector('[data-image-viewer-download]');
const imageViewerStage = imageViewerDialog?.querySelector('[data-image-viewer-stage]');
const imageZoomIn = imageViewerDialog?.querySelector('[data-image-zoom-in]');
const imageZoomOut = imageViewerDialog?.querySelector('[data-image-zoom-out]');
const imageZoomLevel = imageViewerDialog?.querySelector('[data-image-zoom-level]');
const imageGrayscaleToggle = imageViewerDialog?.querySelector('[data-image-grayscale]');
const videoViewerDialog = document.querySelector('[data-video-viewer-dialog]');
const videoViewerPlayer = videoViewerDialog?.querySelector('[data-video-viewer-player]');
const videoViewerTitle = videoViewerDialog?.querySelector('[data-video-viewer-title]');
const videoViewerDownload = videoViewerDialog?.querySelector('[data-video-viewer-download]');
const videoViewerFallback = videoViewerDialog?.querySelector('[data-video-viewer-fallback]');
const openVideoViewer = (trigger) => {
    if (!videoViewerDialog || !videoViewerPlayer) return;
    const source = trigger.dataset.videoUrl;
    if (!source) return;
    videoViewerPlayer.src = source;
    videoViewerPlayer.load();
    if (videoViewerFallback) videoViewerFallback.hidden = true;
    if (videoViewerTitle) videoViewerTitle.textContent = trigger.dataset.videoTitle ?? 'Private video';
    if (videoViewerDownload) videoViewerDownload.href = trigger.dataset.videoDownloadUrl ?? source;
    videoViewerDialog.showModal();
};
videoViewerPlayer?.addEventListener('error', () => {
    if (videoViewerFallback) videoViewerFallback.hidden = false;
});
const closeVideoViewer = () => {
    if (videoViewerPlayer) {
        videoViewerPlayer.pause();
        videoViewerPlayer.removeAttribute('src');
        videoViewerPlayer.load();
    }
    videoViewerDownload?.removeAttribute('href');
    if (videoViewerFallback) videoViewerFallback.hidden = true;
};
videoViewerDialog?.querySelector('[data-close-video-viewer]')?.addEventListener('click', () => videoViewerDialog.close());
videoViewerDialog?.addEventListener('click', (event) => {
    if (event.target === videoViewerDialog) videoViewerDialog.close();
});
videoViewerDialog?.addEventListener('close', closeVideoViewer);
let imageZoom = 1;
let imagePanX = 0;
let imagePanY = 0;
let imageDragPointer = null;

const updateImageViewerTransform = () => {
    if (!imageViewer || !imageViewerStage) return;
    const imageFitScale = imageViewer.naturalWidth && imageViewer.naturalHeight
        ? Math.min(imageViewer.clientWidth / imageViewer.naturalWidth, imageViewer.clientHeight / imageViewer.naturalHeight)
        : 0;
    const displayedWidth = imageViewer.naturalWidth * imageFitScale;
    const displayedHeight = imageViewer.naturalHeight * imageFitScale;
    const stageStyle = getComputedStyle(imageViewerStage);
    const stageWidth = imageViewerStage.clientWidth - parseFloat(stageStyle.paddingLeft) - parseFloat(stageStyle.paddingRight);
    const stageHeight = imageViewerStage.clientHeight - parseFloat(stageStyle.paddingTop) - parseFloat(stageStyle.paddingBottom);
    const maxX = Math.max(0, (displayedWidth * imageZoom - stageWidth) / 2);
    const maxY = Math.max(0, (displayedHeight * imageZoom - stageHeight) / 2);
    imagePanX = Math.max(-maxX, Math.min(maxX, imagePanX));
    imagePanY = Math.max(-maxY, Math.min(maxY, imagePanY));
    imageViewer.style.setProperty('--image-zoom', String(imageZoom));
    imageViewer.style.setProperty('--image-pan-x', `${imagePanX}px`);
    imageViewer.style.setProperty('--image-pan-y', `${imagePanY}px`);
    imageViewerStage.classList.toggle('is-zoomed', imageZoom > 1);
    if (imageZoomLevel) imageZoomLevel.textContent = `${Math.round(imageZoom * 100)}%`;
    if (imageZoomIn instanceof HTMLButtonElement) imageZoomIn.disabled = imageZoom >= 5;
    if (imageZoomOut instanceof HTMLButtonElement) imageZoomOut.disabled = imageZoom <= 1;
};

const resetImageViewer = () => {
    imageZoom = 1;
    imagePanX = 0;
    imagePanY = 0;
    imageDragPointer = null;
    imageViewerStage?.classList.remove('is-dragging');
    if (imageViewer) imageViewer.style.filter = '';
    if (imageGrayscaleToggle instanceof HTMLInputElement) imageGrayscaleToggle.checked = false;
    updateImageViewerTransform();
};

const updateImageGrayscale = () => {
    if (imageViewer && imageGrayscaleToggle instanceof HTMLInputElement) {
        imageViewer.style.filter = imageGrayscaleToggle.checked ? 'grayscale(1)' : '';
    }
};

const downloadGrayscaleImage = async (event) => {
    if (!(imageGrayscaleToggle instanceof HTMLInputElement) || !imageGrayscaleToggle.checked) return;
    event.preventDefault();
    if (!imageViewer?.src || !imageViewerDownload) return;

    try {
        await imageViewer.decode();
        const canvas = document.createElement('canvas');
        canvas.width = imageViewer.naturalWidth;
        canvas.height = imageViewer.naturalHeight;
        const context = canvas.getContext('2d');
        if (!context) throw new Error('Could not prepare the grayscale image for download.');
        context.filter = 'grayscale(1)';
        context.drawImage(imageViewer, 0, 0);
        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('Could not create the grayscale image download.');

        const objectUrl = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        const baseName = (imageViewerTitle?.textContent || 'image')
            .replace(/\.[^.]+$/, '')
            .replace(/[<>:"/\\|?*\u0000-\u001f]/g, '_')
            .trim() || 'image';
        anchor.href = objectUrl;
        anchor.download = `${baseName}-grayscale.png`;
        document.body.append(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000);
    } catch (error) {
        showToast(error instanceof Error ? error.message : 'Could not prepare the grayscale image download.', 'error');
    }
};

imageZoomIn?.addEventListener('click', () => {
    imageZoom = Math.min(5, Math.round((imageZoom + 0.25) * 100) / 100);
    updateImageViewerTransform();
});
imageZoomOut?.addEventListener('click', () => {
    imageZoom = Math.max(1, Math.round((imageZoom - 0.25) * 100) / 100);
    updateImageViewerTransform();
});
imageGrayscaleToggle?.addEventListener('change', updateImageGrayscale);
imageViewerDownload?.addEventListener('click', downloadGrayscaleImage);
imageViewer?.addEventListener('load', updateImageViewerTransform);
imageViewerStage?.addEventListener('pointerdown', (event) => {
    if (imageZoom <= 1 || event.target !== imageViewer || event.button !== 0) return;
    imageDragPointer = { id: event.pointerId, x: event.clientX, y: event.clientY };
    imageViewerStage.setPointerCapture(event.pointerId);
    imageViewerStage.classList.add('is-dragging');
    event.preventDefault();
});
imageViewerStage?.addEventListener('pointermove', (event) => {
    if (!imageDragPointer || event.pointerId !== imageDragPointer.id) return;
    imagePanX += event.clientX - imageDragPointer.x;
    imagePanY += event.clientY - imageDragPointer.y;
    imageDragPointer.x = event.clientX;
    imageDragPointer.y = event.clientY;
    updateImageViewerTransform();
});
imageViewerStage?.addEventListener('pointerup', (event) => {
    if (!imageDragPointer || event.pointerId !== imageDragPointer.id) return;
    imageDragPointer = null;
    imageViewerStage.classList.remove('is-dragging');
    if (imageViewerStage.hasPointerCapture(event.pointerId)) imageViewerStage.releasePointerCapture(event.pointerId);
});
imageViewerStage?.addEventListener('pointercancel', () => {
    imageDragPointer = null;
    imageViewerStage.classList.remove('is-dragging');
});

listenForPageEvent(document, 'click', (event) => {
    const expandButton = event.target.closest('[data-expand-gallery]');
    if (expandButton) {
        event.preventDefault();
        event.stopPropagation();
        const gallery = expandButton.closest('[data-expandable-gallery], [data-profile-gallery]');
        gallery?.querySelectorAll('[data-gallery-extra]').forEach((image) => {
            image.hidden = false;
            if (personViewablesUnlocked) {
                loadProtectedImage(image.matches('[data-protected-image]') ? image : image.querySelector('[data-protected-image]'));
            }
        });
        gallery?.classList.add('is-expanded');
        expandButton.remove();
        return;
    }

    const videoTrigger = event.target.closest('[data-video-viewer]');
    if (videoTrigger) {
        event.preventDefault();
        if (videoTrigger.hasAttribute('data-video-pin-required') && !personViewablesUnlocked) {
            imagePinDialog?.showModal();
            imagePinDigits[0]?.focus();
            return;
        }
        openVideoViewer(videoTrigger);
        return;
    }

    const imageTrigger = event.target.closest('[data-image-viewer]');
    if (!imageTrigger || !imageViewerDialog || !imageViewer) return;
    event.preventDefault();
    const protectedImage = imageTrigger.matches('[data-protected-image]')
        ? imageTrigger
        : imageTrigger.querySelector('[data-protected-image]');
    if (protectedImage && !protectedImage.getAttribute('src') && !personViewablesUnlocked) {
        imagePinDialog?.showModal();
        imagePinDigits[0]?.focus();
        return;
    }
    if (protectedImage) loadProtectedImage(protectedImage);
    const imageUrl = protectedImage?.src || imageTrigger.dataset.imageUrl;
    if (!imageUrl) return;
    imageViewer.src = imageUrl;
    imageViewer.alt = imageTrigger.dataset.imageTitle
        ?? protectedImage?.alt
        ?? imageTrigger.querySelector('img')?.alt
        ?? 'Private image';
    if (imageViewerTitle) imageViewerTitle.textContent = imageViewer.alt;
    resetImageViewer();
    if (imageViewerDownload) imageViewerDownload.href = imageTrigger.dataset.downloadUrl
        ?? protectedImage?.dataset.downloadUrl
        ?? '';
    imageViewerDialog.showModal();
});

document.querySelector('[data-close-image-viewer]')?.addEventListener('click', () => imageViewerDialog?.close());
imageViewerDialog?.addEventListener('click', (event) => {
    if (event.target === imageViewerDialog) imageViewerDialog.close();
});
imageViewerDialog?.addEventListener('close', () => {
    if (imageViewer) imageViewer.removeAttribute('src');
    if (imageViewerDownload) imageViewerDownload.removeAttribute('href');
    resetImageViewer();
});

listenForPageEvent(window, 'pageshow', (event) => {
    if (event.persisted && document.querySelector('[data-image-pin-dialog]')) window.location.reload();
});

const caseLinkDialog = document.querySelector('[data-case-link-dialog]');
const caseLinkResults = document.querySelector('[data-case-link-results]');
const caseLinkSearch = document.querySelector('[data-case-link-search]');
let caseSearchTimer = null;
let caseSearchRequest = null;
let caseSearchSequence = 0;

const searchLinkableCases = async () => {
    if (!caseLinkResults) return;
    caseSearchRequest?.abort();
    const request = new AbortController();
    caseSearchRequest = request;
    const sequence = ++caseSearchSequence;
    const url = new URL(caseLinkResults.dataset.searchUrl, window.location.origin);
    url.searchParams.set('q', caseLinkSearch.value.trim());
    caseLinkResults.textContent = 'Searching case files…';
    try {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: request.signal });
        if (!response.ok) throw new Error(await responseError(response));
        const result = await response.json();
        if (sequence !== caseSearchSequence || request.signal.aborted) return;
        caseLinkResults.replaceChildren();
        if (result.cases.length === 0) {
            caseLinkResults.textContent = 'No unlinked case files found.';
            return;
        }
        result.cases.forEach((caseFile) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'case-link-result';
            const details = document.createElement('span');
            const id = document.createElement('strong');
            id.textContent = caseFile.public_id;
            const title = document.createElement('small');
            title.textContent = caseFile.title;
            details.append(id, title);
            const classification = document.createElement('small');
            classification.textContent = caseFile.classification;
            button.append(details, classification);
            button.addEventListener('click', async () => {
                try {
                    const attachResponse = await fetch(caseLinkResults.dataset.attachUrl, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                        },
                        body: JSON.stringify({ case_id: caseFile.id }),
                    });
                    if (!attachResponse.ok) throw new Error(await responseError(attachResponse));
                    window.location.reload();
                } catch (error) {
                    showToast(error instanceof Error ? error.message : 'Unable to link the case file.', 'error');
                }
            });
            caseLinkResults.append(button);
        });
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') return;
        caseLinkResults.textContent = error instanceof Error ? error.message : 'Unable to search case files.';
    } finally {
        if (caseSearchRequest === request) caseSearchRequest = null;
    }
};

document.querySelector('[data-open-case-link]')?.addEventListener('click', () => {
    caseLinkDialog?.showModal();
    searchLinkableCases();
    caseLinkSearch?.focus();
});
caseLinkSearch?.addEventListener('input', () => {
    caseSearchRequest?.abort();
    caseSearchRequest = null;
    caseSearchSequence++;
    window.clearTimeout(caseSearchTimer);
    caseSearchTimer = window.setTimeout(searchLinkableCases, 180);
});
caseLinkSearch?.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    caseLinkSearch.value = '';
    caseSearchRequest?.abort();
    caseSearchRequest = null;
    caseSearchSequence++;
    window.clearTimeout(caseSearchTimer);
    searchLinkableCases();
});

archiveBrowserSearch?.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    archiveBrowserSearch.value = '';
    archiveBrowserSearch.dispatchEvent(new Event('input', { bubbles: true }));
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
        showToast(error instanceof Error ? error.message : 'Could not read local calculation history.', 'error');
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
            showToast('Calculation succeeded, but local history could not be saved.', 'warning');
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

    historyClear.addEventListener('click', async () => {
        const confirmed = await window.vaultConfirm({
            title: 'Clear calculator history',
            message: 'Clear all calculation history stored locally in this browser?',
            confirmLabel: 'CLEAR HISTORY',
            destructive: true,
        });
        if (!confirmed) return;
        history = [];
        historyExpanded = false;
        try {
            localStorage.removeItem(historyStorageKey);
            historyAvailable = true;
        } catch {
            historyAvailable = false;
            showToast('Could not clear local calculation history.', 'error');
        }
        renderHistory();
    });

    renderHistory();
}

pageCleanup.push(() => {
    window.clearTimeout(archiveBrowserSearchTimer);
    archiveBrowserRequest?.abort();
    window.clearTimeout(caseSearchTimer);
    caseSearchRequest?.abort();
});
};

document.addEventListener('livewire:navigating', () => {
    navigationInProgress = true;
});
document.addEventListener('livewire:navigated', () => {
    if (!navigationInProgress) return;
    navigationInProgress = false;
    initializeVaultPage();
});
initializeVaultPage();
