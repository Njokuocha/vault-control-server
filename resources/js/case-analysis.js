const selectors = {
    root: '[data-case-analysis]',
};

const iconByType = {
    case: 'ri-folder-shield-2-line',
    report: 'ri-file-text-line',
    person: 'ri-user-search-line',
    staff: 'ri-user-settings-line',
    attachment: 'ri-attachment-2',
    update: 'ri-history-line',
};

function initialize(root) {
    if (root.dataset.initialized === 'true') return;
    root.dataset.initialized = 'true';

    const endpoints = JSON.parse(root.dataset.endpoints ?? '{}');
    const canvas = root.querySelector('[data-canvas]');
    const world = root.querySelector('[data-world]');
    const nodesElement = root.querySelector('[data-nodes]');
    const connections = root.querySelector('[data-connections]');
    const minimap = root.querySelector('[data-minimap]');
    const controller = new AbortController();
    const { signal } = controller;
    const state = {
        case: null,
        reports: [],
        people: [],
        staff: [],
        attachments: [],
        updates: [],
        positions: new Map(),
        transform: { x: 0, y: 0, scale: 1 },
        selected: null,
        selectedConnection: null,
        labels: true,
        filters: new Set(['report', 'person', 'staff', 'attachment', 'update']),
        query: '',
        loadToken: 0,
        retry: null,
        searchPage: 1,
        searchLastPage: 1,
        searchTerm: '',
        searchTimer: null,
        noteEditing: null,
        echoChannel: null,
        echoCaseId: null,
        pan: null,
        pointer: null,
        worldHeight: 6000,
        toastTimer: null,
    };

    const request = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                ...(options.body ? { 'Content-Type': 'application/json' } : {}),
                ...options.headers,
            },
            ...options,
        });
        const payload = response.status === 204 ? null : await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(payload?.message ?? `Request failed (${response.status}).`);
            error.status = response.status;
            throw error;
        }

        return payload;
    };

    const endpointFor = (name, caseId = state.case?.id, noteId = null) => {
        let url = endpoints[name];
        if (!url) throw new Error(`The ${name} endpoint is unavailable.`);
        if (caseId !== undefined && caseId !== null) url = url.replaceAll('__CASE__', encodeURIComponent(String(caseId)));
        if (noteId !== null) url = url.replaceAll('__NOTE__', encodeURIComponent(String(noteId)));
        return url;
    };

    const toast = (message, type = 'info') => {
        const element = root.querySelector('[data-toast]');
        element.textContent = message;
        element.dataset.type = type;
        element.classList.add('is-visible');
        clearTimeout(state.toastTimer);
        state.toastTimer = setTimeout(() => element.classList.remove('is-visible'), 3600);
    };

    const showLoadError = (section, message) => {
        const error = root.querySelector('[data-load-error]');
        error.hidden = false;
        root.querySelector('[data-load-error-message]').textContent = message;
        root.querySelector('[data-retry-stage]').textContent = `Retry ${section}`;
        state.retry = section;
    };

    const setStage = (message, visible = true) => {
        const stage = root.querySelector('[data-stage]');
        stage.hidden = !visible;
        root.querySelector('[data-stage-label]').textContent = message;
    };

    const caseUrl = (template, id) => template.replaceAll('__CASE__', encodeURIComponent(String(id)));

    const worldHeightFor = () => {
        const attachmentRows = Math.ceil(state.attachments.length / 2);
        const reportRows = Math.ceil(state.reports.length / 2);
        const peopleRows = Math.ceil(state.people.length / 2);
        return Math.max(6000, 3600 + Math.max(attachmentRows, reportRows, peopleRows) * 170 + state.updates.length * 150);
    };

    const allRecords = () => [
        ...(state.reports ?? []),
        ...(state.people ?? []),
        ...(state.staff ?? []),
        ...(state.attachments ?? []),
        ...(state.updates ?? []),
    ];

    const layoutRecords = () => {
        const positions = new Map();
        const center = { x: 2600, y: 2700 };
        const setGroup = (records, group, x, startY, direction = 1, step = 170) => {
            records.forEach((record, index) => {
                const column = index % 2;
                const row = Math.floor(index / 2);
                positions.set(record.id, {
                    x: x + column * 300 * direction,
                    y: startY + row * step,
                });
            });
        };
        setGroup(state.reports, 'report', 3500, 480, 1, 170);
        setGroup(state.people, 'person', 1700, 480, -1, 170);
        setGroup(state.staff, 'staff', 1450, center.y, -1, 170);
        setGroup(state.attachments, 'attachment', 3500, center.y + 500, 1, 170);
        setGroup(state.updates, 'update', 1700, center.y + 500, -1, 170);
        positions.set(`case-${state.case.id}`, center);
        state.positions = positions;

        return center;
    };

    const card = (record) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `case-analysis-node case-analysis-node--${record.type}`;
        button.dataset.nodeId = record.id;
        button.dataset.nodeType = record.type;
        button.setAttribute('aria-label', `${record.type}: ${record.title}`);

        const icon = document.createElement('span');
        icon.className = 'case-analysis-node__icon';
        const iconElement = document.createElement('i');
        iconElement.className = `ri ${iconByType[record.type] ?? 'ri-node-tree'}`;
        iconElement.setAttribute('aria-hidden', 'true');
        icon.append(iconElement);

        const content = document.createElement('span');
        content.className = 'case-analysis-node__content';
        const eyebrow = document.createElement('span');
        eyebrow.className = 'case-analysis-node__type';
        eyebrow.textContent = record.type === 'case' ? 'INVESTIGATION CASE' : record.type.toUpperCase();
        const title = document.createElement('strong');
        title.className = 'case-analysis-node__title';
        title.textContent = record.title ?? 'Untitled record';
        const summary = document.createElement('span');
        summary.className = 'case-analysis-node__summary';
        summary.textContent = record.summary ?? '';
        content.append(eyebrow, title);
        if (record.summary) content.append(summary);

        const metadata = document.createElement('span');
        metadata.className = 'case-analysis-node__metadata';
        const values = [
            record.public_id,
            record.status,
            record.department,
            record.author,
            record.categories?.join(', '),
            record.created_at ? new Date(record.created_at).toLocaleDateString() : '',
        ].filter(Boolean);
        metadata.textContent = values.slice(0, 2).join(' · ');
        button.append(icon, content);
        if (metadata.textContent) button.append(metadata);

        if (record.type === 'case') {
            button.classList.add('case-analysis-node--central');
            button.dataset.nodeType = 'case';
        }

        return button;
    };

    const nodeRecordById = (id) => {
        if (state.case && id === `case-${state.case.id}`) {
            return {
                ...state.case,
                id,
                type: 'case',
                title: state.case.title,
                summary: `${state.case.status} · ${state.case.classification}`,
            };
        }
        return allRecords().find((record) => record.id === id);
    };

    const isNodeVisible = (record) => {
        if (record.type === 'case') return true;
        if (!state.filters.has(record.type)) return false;
        if (!state.query) return true;
        const searchable = [record.title, record.summary, record.public_id, record.author, record.department]
            .filter(Boolean).join(' ').toLocaleLowerCase();
        return searchable.includes(state.query);
    };

    const applySelectionStyles = () => {
        const connected = new Set();
        if (state.selected) {
            const selectedType = state.selected.type;
            if (selectedType === 'case') {
                allRecords().forEach((record) => connected.add(record.id));
            } else {
                connected.add(`case-${state.case.id}`);
                connected.add(state.selected.id);
            }
        }
        nodesElement.querySelectorAll('[data-node-id]').forEach((element) => {
            element.classList.toggle('is-selected', element.dataset.nodeId === state.selected?.id);
            element.classList.toggle('is-deemphasized', Boolean(state.selected) && !connected.has(element.dataset.nodeId));
        });
        connections.querySelectorAll('[data-connection]').forEach((line) => {
            const related = !state.selected
                || state.selected.type === 'case'
                || line.dataset.connection === state.selected.id;
            line.classList.toggle('is-muted', !related);
            line.classList.toggle('is-highlighted', related && Boolean(state.selected));
        });
    };

    const updateMinimap = () => {
        const group = root.querySelector('[data-minimap-nodes]');
        group.replaceChildren();
        const bounds = `0 0 5200 ${state.worldHeight}`;
        root.querySelector('[data-minimap] svg').setAttribute('viewBox', bounds);
        connections.setAttribute('viewBox', bounds);
        for (const record of [{ id: `case-${state.case?.id}`, type: 'case' }, ...allRecords().filter(isNodeVisible)]) {
            const point = state.positions.get(record.id);
            if (!point) continue;
            const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circle.setAttribute('cx', point.x);
            circle.setAttribute('cy', point.y);
            circle.setAttribute('r', record.type === 'case' ? '70' : '38');
            circle.setAttribute('class', `minimap-node minimap-node--${record.type}`);
            group.append(circle);
        }
        updateMinimapViewport();
    };

    const updateMinimapViewport = () => {
        const viewport = root.querySelector('[data-minimap-viewport]');
        if (!state.case) return;
        const rect = canvas.getBoundingClientRect();
        const width = Math.min(5200, rect.width / state.transform.scale);
        const height = Math.min(state.worldHeight, rect.height / state.transform.scale);
        viewport.setAttribute('width', width);
        viewport.setAttribute('height', height);
        viewport.setAttribute('x', Math.max(0, Math.min(5200 - width, -state.transform.x / state.transform.scale)));
        viewport.setAttribute('y', Math.max(0, Math.min(state.worldHeight - height, -state.transform.y / state.transform.scale)));
    };

    const applyTransform = () => {
        world.style.transform = `translate(${state.transform.x}px, ${state.transform.y}px) scale(${state.transform.scale})`;
        const percent = `${Math.round(state.transform.scale * 100)}%`;
        root.querySelector('[data-zoom-value]').textContent = percent;
        if (state.pointer) {
            const rect = canvas.getBoundingClientRect();
            const worldX = Math.round((state.pointer.x - rect.left - state.transform.x) / state.transform.scale);
            const worldY = Math.round((state.pointer.y - rect.top - state.transform.y) / state.transform.scale);
            root.querySelector('[data-coordinate]').textContent = `X: ${worldX} | Y: ${worldY} | Zoom: ${percent}`;
        } else {
            root.querySelector('[data-coordinate]').textContent = `X: 0 | Y: 0 | Zoom: ${percent}`;
        }
        canvas.style.setProperty('--case-grid-size', `${32 * state.transform.scale}px`);
        canvas.style.setProperty('--case-grid-x', `${state.transform.x % (32 * state.transform.scale)}px`);
        canvas.style.setProperty('--case-grid-y', `${state.transform.y % (32 * state.transform.scale)}px`);
        updateMinimapViewport();
    };

    const renderDiagram = (animate = false) => {
        if (!state.case) return;
        state.worldHeight = worldHeightFor();
        const center = layoutRecords();
        world.style.width = '5200px';
        world.style.height = `${state.worldHeight}px`;
        const caseRecord = { ...state.case, id: `case-${state.case.id}`, type: 'case', title: state.case.title, summary: `${state.case.status} · ${state.case.classification}` };
        const records = [caseRecord, ...allRecords()].filter(isNodeVisible);
        nodesElement.replaceChildren();
        records.forEach((record, index) => {
            const point = state.positions.get(record.id);
            if (!point) return;
            const element = card(record);
            element.style.left = `${point.x}px`;
            element.style.top = `${point.y}px`;
            element.classList.toggle('case-analysis-node--label-hidden', !state.labels);
            if (animate && record.type !== 'case') element.style.animationDelay = `${Math.min(index * 18, 360)}ms`;
            nodesElement.append(element);
        });

        connections.replaceChildren();
        for (const record of allRecords()) {
            if (!isNodeVisible(record)) continue;
            const point = state.positions.get(record.id);
            if (!point) continue;
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', center.x);
            line.setAttribute('y1', center.y);
            line.setAttribute('x2', point.x);
            line.setAttribute('y2', point.y);
            line.setAttribute('class', `case-analysis-link case-analysis-link--${record.type}`);
            line.dataset.connection = record.id;
            line.dataset.connectionType = record.type;
            line.setAttribute('tabindex', '0');
            line.setAttribute('role', 'button');
            line.setAttribute('aria-label', `Show relationship between this case and ${record.type}`);
            if (animate) line.classList.add('is-building');
            connections.append(line);
        }

        canvas.classList.add('has-case');
        root.querySelector('[data-empty]').hidden = true;
        root.querySelector('[data-node-search]').disabled = false;
        root.querySelector('[data-status-line]').hidden = false;
        root.querySelector('[data-status-line]').textContent = `${allRecords().length} linked records · ${state.case.public_id}`;
        root.querySelector('[data-current-case]').innerHTML = '';
        const marker = document.createElement('span');
        marker.className = 'case-analysis-current__marker';
        const details = document.createElement('div');
        const label = document.createElement('small');
        label.textContent = `${state.case.public_id} · ${state.case.status}`;
        const title = document.createElement('strong');
        title.textContent = state.case.title;
        details.append(label, title);
        root.querySelector('[data-current-case]').append(marker, details);
        root.querySelector('[data-clear-case]').disabled = false;
        root.querySelector('[data-refresh-diagram]').disabled = false;
        applyTransform();
        updateMinimap();
        applySelectionStyles();
        if (animate && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setTimeout(() => connections.querySelectorAll('.is-building').forEach((line) => line.classList.remove('is-building')), 1300);
        }
    };

    const openInspector = (record) => {
        state.selected = record;
        state.selectedConnection = null;
        const panel = root.querySelector('[data-inspector]');
        panel.hidden = false;
        root.querySelector('[data-inspector-type]').textContent = record.type === 'case' ? 'INVESTIGATION CASE' : record.type.toUpperCase();
        root.querySelector('[data-inspector-title]').textContent = record.title ?? 'Selected record';
        const body = root.querySelector('[data-inspector-body]');
        body.replaceChildren();
        const fields = [
            ['Record', record.public_id ?? record.record_id ?? `Case ${state.case.public_id}`],
            ['Summary', record.summary],
            ['Status', record.status],
            ['Author', record.author],
            ['Author type', record.author_type],
            ['Department', record.department],
            ['Role', record.role],
            ['Category', record.categories?.join(', ')],
            ['Recorded', record.created_at ? new Date(record.created_at).toLocaleString() : null],
        ].filter(([, value]) => value);
        fields.forEach(([label, value]) => {
            const row = document.createElement('div');
            row.className = 'case-analysis-inspector__field';
            const name = document.createElement('span');
            name.textContent = label;
            const content = document.createElement('strong');
            content.textContent = value;
            row.append(name, content);
            body.append(row);
        });
        if (record.url) {
            const link = document.createElement('a');
            link.className = 'case-analysis-button case-analysis-button--wide';
            link.href = record.url;
            link.textContent = record.type === 'attachment' ? 'Open authorized file preview' : 'Open source record';
            if (record.type === 'attachment') link.target = '_blank';
            body.append(link);
        }
        applySelectionStyles();
    };

    const focusNode = (record) => {
        const point = state.positions.get(record.id);
        if (!point) return;
        const rect = canvas.getBoundingClientRect();
        state.transform.x = rect.width / 2 - point.x * state.transform.scale;
        state.transform.y = rect.height / 2 - point.y * state.transform.scale;
        applyTransform();
    };

    const showRelationship = (line) => {
        const record = nodeRecordById(line.dataset.connection);
        if (!record) return;
        state.selectedConnection = record;
        openInspector({
            id: `relation-${record.id}`,
            type: 'relationship',
            title: `${state.case.public_id} ↔ ${record.type}`,
            summary: `This case is directly linked to this ${record.type} record in the existing application data.`,
            author: record.author,
            author_type: record.author_type,
            created_at: record.created_at,
            url: record.url,
        });
        root.querySelector('[data-inspector-type]').textContent = 'RECORDED RELATIONSHIP';
    };

    const drawTooltip = (record, event) => {
        const tooltip = root.querySelector('[data-tooltip]');
        const rect = canvas.getBoundingClientRect();
        tooltip.replaceChildren();
        const title = document.createElement('strong');
        title.textContent = record.title;
        const summary = document.createElement('span');
        summary.textContent = record.summary || record.status || record.public_id || record.department || '';
        tooltip.append(title, summary);
        tooltip.hidden = false;
        tooltip.style.left = `${Math.min(rect.width - 290, Math.max(12, event.clientX - rect.left + 18))}px`;
        tooltip.style.top = `${Math.min(rect.height - 82, Math.max(12, event.clientY - rect.top + 16))}px`;
    };

    const getCase = async (id, importAction = false, animate = true) => {
        const token = ++state.loadToken;
        root.querySelector('[data-load-error]').hidden = true;
        root.querySelector('[data-inspector]').hidden = true;
        state.selected = null;
        state.reports = [];
        state.people = [];
        state.staff = [];
        state.attachments = [];
        state.updates = [];
        setStage('Loading case summary…');
        try {
            const summary = await request(caseUrl(endpointFor(importAction ? 'import' : 'summary', id), id), {
                method: importAction ? 'POST' : 'GET',
                ...(importAction ? { body: '{}' } : {}),
            });
            if (token !== state.loadToken) return;
            const caseChanged = state.case?.id !== summary.case.id;
            state.case = summary.case;
            subscribeCase(state.case.id);
            renderDiagram(false);
            if (caseChanged) resetView();
            setStage('Loading linked reports, people and case creator…');

            try {
                const primary = await request(caseUrl(endpoints.primary, id));
                if (token !== state.loadToken) return;
                state.reports = primary.reports ?? [];
                state.people = primary.people ?? [];
                state.staff = primary.staff ?? [];
                renderDiagram(animate);
                if (primary.reports_truncated || primary.people_truncated) {
                    toast('The diagram shows a bounded set of the most recent linked records.', 'info');
                }
            } catch (error) {
                if (token !== state.loadToken) return;
                showLoadError('primary relationships', error.message);
                toast(error.message, 'error');
            }

            setStage('Loading attachment metadata and recent case updates…');
            try {
                const secondary = await request(caseUrl(endpoints.secondary, id));
                if (token !== state.loadToken) return;
                state.attachments = secondary.attachments ?? [];
                state.updates = secondary.updates ?? [];
                renderDiagram(animate);
                if (secondary.attachments_truncated) {
                    toast('The diagram shows the 100 most recent authorized attachments.', 'info');
                }
            } catch (error) {
                if (token !== state.loadToken) return;
                showLoadError('secondary information', error.message);
                toast(error.message, 'error');
            }
            if (token === state.loadToken) setStage('', false);
        } catch (error) {
            if (token !== state.loadToken) return;
            state.case = null;
            canvas.classList.remove('has-case');
            root.querySelector('[data-empty]').hidden = false;
            root.querySelector('[data-node-search]').disabled = true;
            setStage('', false);
            showLoadError('case summary', error.message);
            toast(error.message, 'error');
        }
    };

    const refreshDiagram = async () => {
        if (!state.case) return;
        await getCase(state.case.id, false, false);
    };

    const refreshSecondary = async () => {
        if (!state.case) return;
        try {
            const secondary = await request(caseUrl(endpoints.secondary, state.case.id));
            state.attachments = secondary.attachments ?? [];
            state.updates = secondary.updates ?? [];
            renderDiagram();
        } catch (error) {
            showLoadError('secondary information', error.message);
            toast(error.message, 'error');
        }
    };

    const subscribeCase = (caseId) => {
        const key = String(caseId);
        if (state.echoCaseId === key || !window.Echo) return;
        if (state.echoCaseId) window.Echo.leave(`case-analysis.${state.echoCaseId}`);
        state.echoCaseId = key;
        state.echoChannel = window.Echo.private(`case-analysis.${key}`)
            .listen('.case.analysis.updated', refreshSecondary);
    };

    const closeDialog = (dialog) => {
        if (dialog.open) dialog.close();
    };

    const renderSearchResults = (payload) => {
        const results = root.querySelector('[data-search-results]');
        results.replaceChildren();
        if (!payload.items?.length) {
            const empty = document.createElement('p');
            empty.className = 'case-analysis-search-empty';
            empty.textContent = 'No authorized cases match this search.';
            results.append(empty);
        } else {
            payload.items.forEach((item) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'case-analysis-search-result';
                const title = document.createElement('strong');
                title.textContent = item.title;
                const metadata = document.createElement('span');
                metadata.textContent = `${item.public_id} · ${item.status} · ${item.classification}`;
                const date = document.createElement('small');
                date.textContent = item.created_at ? `Opened ${new Date(item.created_at).toLocaleDateString()}` : '';
                button.append(title, metadata, date);
                button.addEventListener('click', () => {
                    closeDialog(root.querySelector('[data-import-dialog]'));
                    getCase(item.id, true);
                }, { signal });
                results.append(button);
            });
        }

        const pagination = root.querySelector('[data-search-pagination]');
        pagination.hidden = payload.last_page <= 1;
        state.searchPage = payload.page;
        state.searchLastPage = payload.last_page;
        root.querySelector('[data-search-page]').textContent = `Page ${payload.page} of ${payload.last_page}`;
        root.querySelector('[data-search-previous]').disabled = payload.page <= 1;
        root.querySelector('[data-search-next]').disabled = payload.page >= payload.last_page;
    };

    const searchCases = async (page = 1) => {
        const input = root.querySelector('[data-case-search]');
        const term = input.value.trim();
        state.searchTerm = term;
        const results = root.querySelector('[data-search-results]');
        if (term.length < 2) {
            results.replaceChildren();
            if (term.length > 0) {
                const message = document.createElement('p');
                message.className = 'case-analysis-search-empty';
                message.textContent = 'Enter at least 2 characters to search.';
                results.append(message);
            }
            root.querySelector('[data-search-pagination]').hidden = true;
            return;
        }
        root.querySelector('[data-search-busy]').hidden = false;
        try {
            const url = new URL(endpoints.search, window.location.origin);
            url.searchParams.set('q', term);
            url.searchParams.set('page', String(page));
            const payload = await request(url.toString());
            if (state.searchTerm === term) renderSearchResults(payload);
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            root.querySelector('[data-search-busy]').hidden = true;
        }
    };

    const renderRecent = (items) => {
        const list = root.querySelector('[data-recent-list]');
        list.replaceChildren();
        if (!items.length) {
            const empty = document.createElement('p');
            empty.className = 'case-analysis-search-empty';
            empty.textContent = 'Cases you import will appear here.';
            list.append(empty);
            return;
        }
        items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'case-analysis-recent-item';
            const title = document.createElement('strong');
            title.textContent = item.title;
            const id = document.createElement('span');
            id.textContent = `${item.public_id} · ${item.status}`;
            const date = document.createElement('small');
            date.textContent = item.last_accessed_at ? new Date(item.last_accessed_at).toLocaleString() : '';
            button.append(title, id, date);
            button.addEventListener('click', () => getCase(item.id, true), { signal });
            list.append(button);
        });
    };

    const loadRecent = async () => {
        try {
            const response = await request(endpoints.recent);
            renderRecent(response.items ?? []);
        } catch (error) {
            toast(error.message, 'error');
        }
    };

    const resetNoteForm = () => {
        state.noteEditing = null;
        root.querySelector('[data-note-form]').reset();
        root.querySelector('[data-note-form-label]').textContent = 'New shared note';
        root.querySelector('[data-note-submit]').textContent = 'Save note';
        root.querySelector('[data-note-cancel]').hidden = true;
    };

    const renderNotes = (notes) => {
        const list = root.querySelector('[data-notes-list]');
        list.replaceChildren();
        if (!notes.length) {
            const empty = document.createElement('p');
            empty.className = 'case-analysis-search-empty';
            empty.textContent = 'No shared notes have been added to this case.';
            list.append(empty);
            return;
        }
        notes.forEach((note) => {
            const article = document.createElement('article');
            article.className = 'case-analysis-note';
            const header = document.createElement('div');
            header.className = 'case-analysis-note__header';
            const author = document.createElement('div');
            const name = document.createElement('strong');
            name.textContent = note.author;
            const role = document.createElement('small');
            role.textContent = note.author_type;
            author.append(name, role);
            const date = document.createElement('time');
            date.dateTime = note.updated_at ?? note.created_at ?? '';
            date.textContent = note.updated_at ? new Date(note.updated_at).toLocaleString() : '';
            header.append(author, date);
            const body = document.createElement('p');
            body.textContent = note.body;
            article.append(header, body);
            if (note.can_edit) {
                const actions = document.createElement('div');
                actions.className = 'case-analysis-note__actions';
                const edit = document.createElement('button');
                edit.type = 'button';
                edit.textContent = 'Edit';
                edit.addEventListener('click', () => {
                    state.noteEditing = { id: note.id, version: note.version };
                    root.querySelector('[data-note-form] [name="body"]').value = note.body;
                    root.querySelector('[data-note-form-label]').textContent = 'Edit shared note';
                    root.querySelector('[data-note-submit]').textContent = 'Save changes';
                    root.querySelector('[data-note-cancel]').hidden = false;
                    root.querySelector('[data-note-form] [name="body"]').focus();
                }, { signal });
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'is-destructive';
                remove.textContent = 'Delete';
                remove.addEventListener('click', async () => {
                    const confirmed = await confirmAction('Delete this shared note?', 'This removes the note for all authorized analysts.');
                    if (!confirmed) return;
                    try {
                        await request(endpointFor('noteDelete', state.case.id, note.id), {
                            method: 'DELETE',
                            body: JSON.stringify({ version: note.version }),
                        });
                        toast('Shared note deleted.', 'success');
                        await loadNotes();
                    } catch (error) {
                        toast(error.message, 'error');
                        if (error.status === 409) await loadNotes();
                    }
                }, { signal });
                actions.append(edit, remove);
                article.append(actions);
            }
            list.append(article);
        });
    };

    const loadNotes = async () => {
        if (!state.case) {
            root.querySelector('[data-notes-list]').replaceChildren();
            root.querySelector('[data-notes-message]').hidden = false;
            root.querySelector('[data-notes-message]').textContent = 'Import a case to view and add its shared notes.';
            return;
        }
        root.querySelector('[data-notes-message]').hidden = true;
        try {
            const response = await request(caseUrl(endpoints.notes, state.case.id));
            renderNotes(response.items ?? []);
        } catch (error) {
            root.querySelector('[data-notes-message]').hidden = false;
            root.querySelector('[data-notes-message]').textContent = error.message;
        }
    };

    const confirmAction = (title, message) => new Promise((resolve) => {
        const dialog = root.querySelector('[data-confirm-dialog]');
        root.querySelector('[data-confirm-title]').textContent = title;
        root.querySelector('[data-confirm-message]').textContent = message;
        const finish = (event) => {
            if (event) event.preventDefault();
            dialog.close();
            resolve(Boolean(dialog.returnValue === 'confirm'));
        };
        dialog.addEventListener('close', finish, { once: true, signal });
        dialog.showModal();
    });

    const setZoom = (nextScale, point = null) => {
        const next = Math.min(1.8, Math.max(0.25, nextScale));
        if (point) {
            const worldX = (point.x - state.transform.x) / state.transform.scale;
            const worldY = (point.y - state.transform.y) / state.transform.scale;
            state.transform.x = point.x - worldX * next;
            state.transform.y = point.y - worldY * next;
        } else {
            const rect = canvas.getBoundingClientRect();
            const centerX = rect.width / 2;
            const centerY = rect.height / 2;
            const worldX = (centerX - state.transform.x) / state.transform.scale;
            const worldY = (centerY - state.transform.y) / state.transform.scale;
            state.transform.x = centerX - worldX * next;
            state.transform.y = centerY - worldY * next;
        }
        state.transform.scale = next;
        applyTransform();
    };

    const fitView = () => {
        if (!state.case) return;
        const points = [...state.positions.values()];
        const minX = Math.min(...points.map((point) => point.x)) - 360;
        const maxX = Math.max(...points.map((point) => point.x)) + 360;
        const minY = Math.min(...points.map((point) => point.y)) - 260;
        const maxY = Math.max(...points.map((point) => point.y)) + 260;
        const rect = canvas.getBoundingClientRect();
        const scale = Math.min(1, (rect.width - 80) / (maxX - minX), (rect.height - 80) / (maxY - minY));
        state.transform.scale = Math.min(1.8, Math.max(0.25, scale));
        state.transform.x = (rect.width - (minX + maxX) * state.transform.scale) / 2;
        state.transform.y = (rect.height - (minY + maxY) * state.transform.scale) / 2;
        applyTransform();
    };

    const resetView = () => {
        if (!state.case) return;
        const rect = canvas.getBoundingClientRect();
        state.transform.scale = 0.72;
        state.transform.x = rect.width / 2 - 2600 * state.transform.scale;
        state.transform.y = rect.height / 2 - 2700 * state.transform.scale;
        applyTransform();
    };

    const setMinimapCenter = (event) => {
        if (!state.case) return;
        const svg = root.querySelector('[data-minimap] svg');
        const point = svg.createSVGPoint();
        point.x = event.clientX;
        point.y = event.clientY;
        const coordinates = point.matrixTransform(svg.getScreenCTM().inverse());
        const rect = canvas.getBoundingClientRect();
        state.transform.x = rect.width / 2 - coordinates.x * state.transform.scale;
        state.transform.y = rect.height / 2 - coordinates.y * state.transform.scale;
        applyTransform();
    };

    const importDialog = root.querySelector('[data-import-dialog]');
    root.querySelectorAll('[data-open-import]').forEach((button) => button.addEventListener('click', () => {
        importDialog.showModal();
        root.querySelector('[data-case-search]').focus();
    }, { signal }));
    root.querySelector('[data-clear-selection]').addEventListener('click', () => {
        root.querySelector('[data-clear-case]').click();
    }, { signal });
    root.querySelector('[data-case-search]').addEventListener('input', () => {
        clearTimeout(state.searchTimer);
        state.searchTimer = setTimeout(() => searchCases(1), 250);
    }, { signal });
    root.querySelector('[data-search-previous]').addEventListener('click', () => searchCases(state.searchPage - 1), { signal });
    root.querySelector('[data-search-next]').addEventListener('click', () => searchCases(state.searchPage + 1), { signal });

    root.querySelector('[data-toggle-recent]').addEventListener('click', () => {
        const panel = root.querySelector('[data-recent-panel]');
        panel.hidden = !panel.hidden;
        root.querySelector('[data-notes-panel]').hidden = true;
        if (!panel.hidden) loadRecent();
    }, { signal });
    root.querySelector('[data-close-recent]').addEventListener('click', () => {
        root.querySelector('[data-recent-panel]').hidden = true;
    }, { signal });
    root.querySelector('[data-toggle-notes]').addEventListener('click', () => {
        const panel = root.querySelector('[data-notes-panel]');
        panel.hidden = !panel.hidden;
        root.querySelector('[data-recent-panel]').hidden = true;
        if (!panel.hidden) loadNotes();
    }, { signal });
    root.querySelector('[data-close-notes]').addEventListener('click', () => {
        root.querySelector('[data-notes-panel]').hidden = true;
    }, { signal });
    root.querySelector('[data-close-inspector]').addEventListener('click', () => {
        root.querySelector('[data-inspector]').hidden = true;
        state.selected = null;
        applySelectionStyles();
    }, { signal });
    root.querySelector('[data-focus-node]').addEventListener('click', () => {
        if (state.selected) focusNode(state.selected);
    }, { signal });
    root.querySelector('[data-note-cancel]').addEventListener('click', resetNoteForm, { signal });

    root.querySelector('[data-note-form]').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!state.case) {
            toast('Import a case before adding a note.', 'error');
            return;
        }
        const body = root.querySelector('[data-note-form] [name="body"]').value.trim();
        if (!body) return;
        const submit = root.querySelector('[data-note-submit]');
        submit.disabled = true;
        try {
            if (state.noteEditing) {
                await request(endpointFor('note', state.case.id, state.noteEditing.id), {
                    method: 'PUT',
                    body: JSON.stringify({ body, version: state.noteEditing.version }),
                });
                toast('Shared note updated.', 'success');
            } else {
                await request(caseUrl(endpoints.noteStore, state.case.id), {
                    method: 'POST',
                    body: JSON.stringify({ body }),
                });
                toast('Shared note added.', 'success');
            }
            resetNoteForm();
            await loadNotes();
        } catch (error) {
            toast(error.message, 'error');
            if (error.status === 409) await loadNotes();
        } finally {
            submit.disabled = false;
        }
    }, { signal });

    root.querySelectorAll('[data-filter]').forEach((input) => input.addEventListener('change', () => {
        if (input.checked) state.filters.add(input.dataset.filter);
        else state.filters.delete(input.dataset.filter);
        renderDiagram();
    }, { signal }));

    root.querySelector('[data-node-search]').addEventListener('input', (event) => {
        state.query = event.target.value.trim().toLocaleLowerCase();
        renderDiagram();
    }, { signal });
    root.querySelector('[data-node-search]').addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.currentTarget.value = '';
            state.query = '';
            renderDiagram();
        }
    }, { signal });
    root.querySelector('[data-toggle-labels]').addEventListener('click', (event) => {
        state.labels = !state.labels;
        event.currentTarget.setAttribute('aria-pressed', String(!state.labels));
        root.classList.toggle('labels-hidden', !state.labels);
        renderDiagram();
    }, { signal });
    root.querySelector('[data-refresh-diagram]').addEventListener('click', refreshDiagram, { signal });
    root.querySelector('[data-clear-case]').addEventListener('click', () => {
        state.loadToken += 1;
        state.case = null;
        state.reports = [];
        state.people = [];
        state.staff = [];
        state.attachments = [];
        state.updates = [];
        state.selected = null;
        state.positions.clear();
        nodesElement.replaceChildren();
        connections.replaceChildren();
        canvas.classList.remove('has-case');
        root.querySelector('[data-empty]').hidden = false;
        root.querySelector('[data-current-case]').innerHTML = '<span class="case-analysis-current__marker"></span><div><small>NO CASE IMPORTED</small><strong>Select an investigation case to begin</strong></div>';
        root.querySelector('[data-node-search]').value = '';
        root.querySelector('[data-node-search]').disabled = true;
        root.querySelector('[data-status-line]').hidden = true;
        root.querySelector('[data-inspector]').hidden = true;
        root.querySelector('[data-clear-case]').disabled = true;
        root.querySelector('[data-refresh-diagram]').disabled = true;
        root.querySelector('[data-notes-panel]').hidden = true;
        root.querySelector('[data-notes-list]').replaceChildren();
        setStage('', false);
        root.querySelector('[data-load-error]').hidden = true;
        resetView();
    }, { signal });
    root.querySelector('[data-retry-stage]').addEventListener('click', async () => {
        const section = state.retry;
        root.querySelector('[data-load-error]').hidden = true;
        if (!state.case) return;
        try {
            if (section === 'primary relationships') {
                const payload = await request(caseUrl(endpoints.primary, state.case.id));
                state.reports = payload.reports ?? [];
                state.people = payload.people ?? [];
                state.staff = payload.staff ?? [];
            } else if (section === 'secondary information') {
                await refreshSecondary();
            } else {
                await refreshDiagram();
                return;
            }
            renderDiagram(true);
            toast(`${section} loaded.`, 'success');
        } catch (error) {
            showLoadError(section, error.message);
        }
    }, { signal });

    root.querySelector('[data-zoom-in]').addEventListener('click', () => setZoom(state.transform.scale * 1.2), { signal });
    root.querySelector('[data-zoom-out]').addEventListener('click', () => setZoom(state.transform.scale / 1.2), { signal });
    root.querySelector('[data-fit-view]').addEventListener('click', fitView, { signal });
    root.querySelector('[data-reset-view]').addEventListener('click', resetView, { signal });
    root.querySelector('[data-fullscreen]').addEventListener('click', async () => {
        try {
            if (!document.fullscreenElement) await root.requestFullscreen();
            else await document.exitFullscreen();
        } catch (error) {
            toast(error.message || 'Fullscreen is unavailable in this browser.', 'error');
        }
    }, { signal });
    root.querySelector('[data-minimap-reset]').addEventListener('click', resetView, { signal });
    root.querySelector('[data-minimap] svg').addEventListener('pointerdown', (event) => {
        event.preventDefault();
        setMinimapCenter(event);
        const move = (moveEvent) => setMinimapCenter(moveEvent);
        const stop = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', stop);
        };
        window.addEventListener('pointermove', move, { signal });
        window.addEventListener('pointerup', stop, { once: true, signal });
    }, { signal });

    canvas.addEventListener('wheel', (event) => {
        event.preventDefault();
        const rect = canvas.getBoundingClientRect();
        setZoom(state.transform.scale * (event.deltaY < 0 ? 1.1 : 1 / 1.1), {
            x: event.clientX - rect.left,
            y: event.clientY - rect.top,
        });
    }, { passive: false, signal });
    canvas.addEventListener('pointerdown', (event) => {
        const target = event.target;
        if (target.closest?.('[data-node-id]') || target.closest?.('[data-connection]')) return;
        if (target !== canvas && target !== world && target !== nodesElement && target !== connections) return;
        canvas.setPointerCapture(event.pointerId);
        state.pan = { pointerId: event.pointerId, x: event.clientX, y: event.clientY, tx: state.transform.x, ty: state.transform.y };
        canvas.classList.add('is-grabbing');
    }, { signal });
    canvas.addEventListener('pointermove', (event) => {
        state.pointer = { x: event.clientX, y: event.clientY };
        const pointerRect = canvas.getBoundingClientRect();
        const worldX = Math.round((event.clientX - pointerRect.left - state.transform.x) / state.transform.scale);
        const worldY = Math.round((event.clientY - pointerRect.top - state.transform.y) / state.transform.scale);
        root.querySelector('[data-coordinate]').textContent = `X: ${worldX} | Y: ${worldY} | Zoom: ${Math.round(state.transform.scale * 100)}%`;
        if (state.pan?.pointerId === event.pointerId) {
            state.transform.x = state.pan.tx + event.clientX - state.pan.x;
            state.transform.y = state.pan.ty + event.clientY - state.pan.y;
            applyTransform();
            return;
        }
        const node = event.target.closest?.('[data-node-id]');
        if (node) {
            const record = nodeRecordById(node.dataset.nodeId);
            if (record) drawTooltip(record, event);
        } else {
            root.querySelector('[data-tooltip]').hidden = true;
        }
    }, { signal });
    const stopPan = (event) => {
        if (state.pan?.pointerId !== event.pointerId) return;
        state.pan = null;
        canvas.classList.remove('is-grabbing');
        if (canvas.hasPointerCapture(event.pointerId)) canvas.releasePointerCapture(event.pointerId);
    };
    canvas.addEventListener('pointerup', stopPan, { signal });
    canvas.addEventListener('pointercancel', stopPan, { signal });
    canvas.addEventListener('click', (event) => {
        const nodeElement = event.target.closest?.('[data-node-id]');
        if (nodeElement) {
            const record = nodeRecordById(nodeElement.dataset.nodeId);
            if (record) openInspector(record);
            return;
        }
        const line = event.target.closest?.('[data-connection]');
        if (line) showRelationship(line);
    }, { signal });
    canvas.addEventListener('pointerleave', () => {
        root.querySelector('[data-tooltip]').hidden = true;
    }, { signal });

    root.querySelector('[data-minimap]').addEventListener('pointerdown', (event) => {
        if (!event.target.closest('svg')) return;
        event.stopPropagation();
    }, { signal });
    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            root.querySelector('[data-inspector]').hidden = true;
        }
        if (event.key === '/' && state.case && !['INPUT', 'TEXTAREA'].includes(document.activeElement?.tagName)) {
            event.preventDefault();
            root.querySelector('[data-node-search]').focus();
        }
    }, { signal });
    window.addEventListener('resize', updateMinimapViewport, { signal });

    const cleanup = () => {
        controller.abort();
        clearTimeout(state.searchTimer);
        clearTimeout(state.toastTimer);
        if (state.echoCaseId) window.Echo?.leave(`case-analysis.${state.echoCaseId}`);
    };
    root.__caseAnalysisDestroy = cleanup;

    if (root.dataset.initialCase) getCase(root.dataset.initialCase, true);
    else {
        root.querySelector('[data-clear-case]').disabled = true;
        root.querySelector('[data-refresh-diagram]').disabled = true;
    }
}

const initializeAll = () => document.querySelectorAll(selectors.root).forEach(initialize);

document.addEventListener('DOMContentLoaded', initializeAll);
document.addEventListener('livewire:navigated', initializeAll);
document.addEventListener('livewire:navigating', () => {
    document.querySelectorAll(selectors.root).forEach((root) => root.__caseAnalysisDestroy?.());
});
