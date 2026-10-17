window.wspace.page.register('modules/calendar/assets/calendar-planning.js', (pageScope) => {
(() => {
    'use strict';

    const root = document.querySelector('[data-calendar-planning-root]');
    if (!root) return;

    const refs = {
        title: root.querySelector('[data-calendar-planning-title]'),
        date: root.querySelector('[data-planning-date]'),
        prev: root.querySelector('[data-planning-prev]'),
        next: root.querySelector('[data-planning-next]'),
        today: root.querySelector('[data-planning-today]'),
        list: root.querySelector('[data-planning-list]'),
        loading: root.querySelector('[data-planning-loading]'),
        feedback: root.querySelector('[data-planning-feedback]'),
        createBlock: document.querySelector('[data-calendar-block-create]'),
        blockEditor: document.querySelector('[data-calendar-block-editor]'),
        linksEditor: document.querySelector('[data-calendar-links-editor]')
    };

    const blockForm = refs.blockEditor.querySelector('[data-block-form]');
    const blockFields = Object.fromEntries(
        [...refs.blockEditor.querySelectorAll('[data-block-field]')]
            .map((node) => [node.dataset.blockField, node])
    );
    const blockTitle = refs.blockEditor.querySelector('[data-block-title]');
    const blockDelete = refs.blockEditor.querySelector('[data-block-delete]');
    const blockFeedback = refs.blockEditor.querySelector('[data-block-feedback]');

    const linksCurrent = refs.linksEditor.querySelector('[data-links-current]');
    const linksType = refs.linksEditor.querySelector('[data-links-type]');
    const linksQuery = refs.linksEditor.querySelector('[data-links-query]');
    const linksTarget = refs.linksEditor.querySelector('[data-links-target]');
    const linksFeedback = refs.linksEditor.querySelector('[data-links-feedback]');
    const linksSave = refs.linksEditor.querySelector('[data-links-save]');

    const state = {
        date: startOfDay(new Date()),
        items: [],
        source: null,
        links: []
    };

    init();

    function init() {
        refs.date.value = dateInput(state.date);
        refs.prev.addEventListener('click', () => moveDay(-1));
        refs.next.addEventListener('click', () => moveDay(1));
        refs.today.addEventListener('click', () => setDate(new Date()));
        refs.date.addEventListener('change', () => {
            const next = parseDateInput(refs.date.value);
            if (next) setDate(next);
        });
        refs.createBlock.addEventListener('click', openBlockCreate);
        refs.blockEditor.querySelectorAll('[data-block-close]').forEach((button) => button.addEventListener('click', closeBlockEditor));
        blockForm.addEventListener('submit', saveBlock);
        blockDelete.addEventListener('click', deleteBlock);

        document.querySelectorAll('[data-calendar-links-open]').forEach((button) => {
            button.addEventListener('click', () => openLinksForButton(button));
        });
        refs.linksEditor.querySelectorAll('[data-links-close]').forEach((button) => button.addEventListener('click', closeLinksEditor));
        refs.linksEditor.querySelector('[data-links-find]').addEventListener('click', () => void loadTargets());
        refs.linksEditor.querySelector('[data-links-add]').addEventListener('click', addSelectedLink);
        linksType.addEventListener('change', () => void loadTargets());
        linksQuery.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                void loadTargets();
            }
        });
        linksSave.addEventListener('click', saveLinks);

        pageScope.listen(document, 'keydown', (event) => {
            if (event.key !== 'Escape') return;
            if (!refs.linksEditor.hidden) closeLinksEditor();
            else if (!refs.blockEditor.hidden) closeBlockEditor();
        });
        void refreshAgenda();
    }

    function moveDay(delta) {
        const next = new Date(state.date);
        next.setDate(next.getDate() + delta);
        setDate(next);
    }

    function setDate(value) {
        state.date = startOfDay(value);
        refs.date.value = dateInput(state.date);
        void refreshAgenda();
    }

    async function refreshAgenda() {
        const from = startOfDay(state.date);
        const to = endOfDay(state.date);
        refs.title.textContent = state.date.toLocaleDateString('ru-RU', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric'
        });
        refs.loading.hidden = false;
        hideFeedback(refs.feedback);
        try {
            const url = new URL(api('/agenda'), window.location.origin);
            url.searchParams.set('from', from.toISOString());
            url.searchParams.set('to', to.toISOString());
            const payload = await requestJson(url.href);
            state.items = Array.isArray(payload.items) ? payload.items : [];
            renderAgenda();
        } catch (error) {
            showFeedback(refs.feedback, message(error, 'Не удалось загрузить план дня.'));
            refs.list.replaceChildren(emptyState('План дня временно недоступен.'));
        } finally {
            refs.loading.hidden = true;
        }
    }

    function renderAgenda() {
        refs.list.replaceChildren();
        if (state.items.length === 0) {
            refs.list.appendChild(emptyState('На выбранный день пока ничего не запланировано.'));
            return;
        }
        state.items.forEach((item) => refs.list.appendChild(planningCard(item)));
    }

    function planningCard(item) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'calendar-planning-item';
        button.appendChild(node('div', 'calendar-planning-item__time', itemTime(item)));

        const body = node('div', 'calendar-planning-item__body');
        body.appendChild(node('strong', '', safe(item.title) || 'Без названия'));
        const details = [safe(item.location), safe(item.timezone)].filter(Boolean).join(' · ');
        if (details) body.appendChild(node('span', '', details));
        button.appendChild(body);
        button.appendChild(node('span', 'calendar-planning-kind', item.kind === 'time_block' ? 'Блок времени' : 'Событие'));

        button.addEventListener('click', () => {
            if (item.kind === 'time_block') {
                void openBlockEdit(safe(item.uid));
                return;
            }
            const url = new URL(window.location.href);
            url.searchParams.set('event', safe(item.uid));
            void pageScope.visit(url.toString());
        });
        return button;
    }

    function openBlockCreate() {
        resetBlockForm();
        const start = roundedStart(state.date);
        const end = new Date(start.getTime() + 60 * 60 * 1000);
        blockFields.starts_at.value = dateTimeInput(start);
        blockFields.ends_at.value = dateTimeInput(end);
        selectTimezone(browserTimezone());
        blockTitle.textContent = 'Новый временной блок';
        blockDelete.hidden = true;
        openBlockEditor();
        blockFields.title.focus();
    }

    async function openBlockEdit(uid) {
        if (!uid) return;
        openBlockEditor();
        setBlockBusy(true);
        hideFeedback(blockFeedback);
        try {
            const payload = await requestJson(`${api('/time-blocks')}/${encodeURIComponent(uid)}`);
            fillBlockForm(payload.block || {});
            blockTitle.textContent = 'Изменить временной блок';
            blockDelete.hidden = false;
        } catch (error) {
            showFeedback(blockFeedback, message(error, 'Не удалось открыть временной блок.'));
        } finally {
            setBlockBusy(false);
        }
    }

    function fillBlockForm(block) {
        resetBlockForm();
        blockFields.uid.value = safe(block.uid);
        blockFields.title.value = safe(block.title);
        blockFields.description.value = safe(block.description);
        blockFields.location.value = safe(block.location);
        selectTimezone(safe(block.timezone) || browserTimezone());
        blockFields.starts_at.value = safe(block.local_start).slice(0, 16);
        blockFields.ends_at.value = safe(block.local_end).slice(0, 16);
    }

    function resetBlockForm() {
        blockForm.reset();
        blockFields.uid.value = '';
        selectTimezone(browserTimezone());
        hideFeedback(blockFeedback);
    }

    async function saveBlock(event) {
        event.preventDefault();
        hideFeedback(blockFeedback);
        const payload = {
            title: blockFields.title.value.trim(),
            description: blockFields.description.value.trim(),
            location: blockFields.location.value.trim(),
            timezone: blockFields.timezone.value.trim(),
            starts_at: blockFields.starts_at.value,
            ends_at: blockFields.ends_at.value
        };
        if (!payload.title || !payload.starts_at || !payload.ends_at || !payload.timezone) {
            showFeedback(blockFeedback, 'Заполните название, начало, окончание и часовой пояс.');
            return;
        }

        const uid = blockFields.uid.value.trim();
        const url = uid ? `${api('/time-blocks')}/${encodeURIComponent(uid)}` : api('/time-blocks');
        setBlockBusy(true);
        try {
            const response = await requestJson(url, {
                method: uid ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            if (response.block?.local_start) {
                const start = parseLocalDate(response.block.local_start);
                if (start) state.date = startOfDay(start);
            }
            closeBlockEditor();
            refs.date.value = dateInput(state.date);
            await refreshAgenda();
        } catch (error) {
            showFeedback(blockFeedback, message(error, 'Не удалось сохранить временной блок.'));
        } finally {
            setBlockBusy(false);
        }
    }

    async function deleteBlock() {
        const uid = blockFields.uid.value.trim();
        if (!uid || !window.confirm('Удалить временной блок?')) return;
        setBlockBusy(true);
        try {
            await requestJson(`${api('/time-blocks')}/${encodeURIComponent(uid)}`, { method: 'DELETE' });
            closeBlockEditor();
            await refreshAgenda();
        } catch (error) {
            showFeedback(blockFeedback, message(error, 'Не удалось удалить временной блок.'));
        } finally {
            setBlockBusy(false);
        }
    }

    function openBlockEditor() {
        window.wspace.modalMotion.open(refs.blockEditor);
        document.documentElement.classList.add('calendar-editor-open');
    }

    function closeBlockEditor() {
        window.wspace.modalMotion.close(refs.blockEditor, () => {
            refs.blockEditor.hidden = true;
            hideFeedback(blockFeedback);
            if (refs.linksEditor.hidden && document.querySelector('[data-calendar-editor]')?.hidden !== false) {
                document.documentElement.classList.remove('calendar-editor-open');
            }
        });
    }

    function setBlockBusy(busy) {
        blockForm.classList.toggle('is-saving', busy);
        blockForm.querySelectorAll('button,input,textarea').forEach((control) => { control.disabled = busy; });
    }

    async function openLinksForButton(button) {
        const sourceType = safe(button.dataset.sourceType);
        const sourceUid = sourceType === 'event'
            ? safe(document.querySelector('[data-calendar-editor] [data-field="uid"]')?.value).trim()
            : blockFields.uid.value.trim();
        if (!sourceUid) {
            const targetFeedback = sourceType === 'event'
                ? document.querySelector('[data-calendar-form-feedback]')
                : blockFeedback;
            showFeedback(targetFeedback, 'Сначала сохраните объект, затем добавьте связи.');
            return;
        }

        state.source = { type: sourceType, uid: sourceUid };
        state.links = [];
        window.wspace.modalMotion.open(refs.linksEditor);
        document.documentElement.classList.add('calendar-editor-open');
        hideFeedback(linksFeedback);
        linksCurrent.replaceChildren(node('span', '', 'Загружаем связи…'));
        try {
            const url = new URL(api('/links'), window.location.origin);
            url.searchParams.set('source_type', sourceType);
            url.searchParams.set('source_uid', sourceUid);
            const payload = await requestJson(url.href);
            state.links = Array.isArray(payload.links) ? payload.links : [];
            renderLinks();
            await loadTargets();
        } catch (error) {
            showFeedback(linksFeedback, message(error, 'Не удалось загрузить связи.'));
            renderLinks();
        }
    }

    function closeLinksEditor() {
        window.wspace.modalMotion.close(refs.linksEditor, () => {
            refs.linksEditor.hidden = true;
            state.source = null;
            state.links = [];
            linksQuery.value = '';
            linksTarget.replaceChildren(option('', 'Выберите объект'));
            hideFeedback(linksFeedback);
            if (refs.blockEditor.hidden && document.querySelector('[data-calendar-editor]')?.hidden !== false) {
                document.documentElement.classList.remove('calendar-editor-open');
            }
        });
    }

    async function loadTargets() {
        hideFeedback(linksFeedback);
        linksTarget.disabled = true;
        linksTarget.replaceChildren(option('', 'Загрузка…'));
        try {
            const url = new URL(api('/link-targets'), window.location.origin);
            url.searchParams.set('type', linksType.value);
            url.searchParams.set('q', linksQuery.value.trim());
            url.searchParams.set('limit', '30');
            const payload = await requestJson(url.href);
            linksTarget.replaceChildren(option('', payload.available === false ? 'Модуль или доступ отключён' : 'Выберите объект'));
            if (payload.available !== false && Array.isArray(payload.targets)) {
                payload.targets.forEach((target) => {
                    const item = option(safe(target.uid), targetLabel(target));
                    item.dataset.title = safe(target.title);
                    item.dataset.subtitle = safe(target.subtitle);
                    item.dataset.href = safe(target.href);
                    linksTarget.appendChild(item);
                });
            }
        } catch (error) {
            linksTarget.replaceChildren(option('', 'Не удалось загрузить список'));
            showFeedback(linksFeedback, message(error, 'Не удалось получить доступные объекты.'));
        } finally {
            linksTarget.disabled = false;
        }
    }

    function addSelectedLink() {
        const selected = linksTarget.selectedOptions[0];
        if (!selected || !selected.value) {
            showFeedback(linksFeedback, 'Выберите объект для связи.');
            return;
        }
        const type = linksType.value;
        if (state.links.some((link) => safe(link.type) === type && safe(link.uid) === selected.value)) {
            showFeedback(linksFeedback, 'Такая связь уже добавлена.');
            return;
        }
        state.links.push({
            type,
            uid: selected.value,
            label: typeLabel(type),
            available: true,
            title: selected.dataset.title || selected.textContent || typeLabel(type),
            subtitle: selected.dataset.subtitle || '',
            href: selected.dataset.href || ''
        });
        hideFeedback(linksFeedback);
        renderLinks();
    }

    function renderLinks() {
        linksCurrent.replaceChildren();
        if (state.links.length === 0) {
            linksCurrent.appendChild(node('span', '', 'Связей пока нет.'));
            return;
        }
        state.links.forEach((link, index) => {
            const chip = node('div', 'calendar-link-chip');
            if (link.available === false) chip.classList.add('is-unavailable');
            chip.appendChild(node('span', '', `${safe(link.label) || typeLabel(link.type)}: ${safe(link.title) || 'Недоступный объект'}`));
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.setAttribute('aria-label', 'Удалить связь');
            remove.textContent = '×';
            remove.addEventListener('click', () => {
                state.links.splice(index, 1);
                renderLinks();
            });
            chip.appendChild(remove);
            linksCurrent.appendChild(chip);
        });
    }

    async function saveLinks() {
        if (!state.source) return;
        linksSave.disabled = true;
        hideFeedback(linksFeedback);
        try {
            const payload = await requestJson(api('/links'), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    source_type: state.source.type,
                    source_uid: state.source.uid,
                    links: state.links.map((link) => ({ type: safe(link.type), uid: safe(link.uid) }))
                })
            });
            state.links = Array.isArray(payload.links) ? payload.links : [];
            renderLinks();
            showFeedback(linksFeedback, 'Связи сохранены.');
        } catch (error) {
            showFeedback(linksFeedback, message(error, 'Не удалось сохранить связи.'));
        } finally {
            linksSave.disabled = false;
        }
    }

    async function requestJson(url, options = {}) {
        const response = await pageScope.fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', ...(options.headers || {}) },
            ...options
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || !payload || typeof payload !== 'object') {
            throw new Error(payload?.message || `HTTP ${response.status}`);
        }
        return payload;
    }

    function api(suffix) {
        const path = `/api/calendar/planning${suffix}`;
        return typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
    }

    function itemTime(item) {
        if (item.all_day === true) return 'Весь день';
        const start = safe(item.local_start).slice(11, 16);
        const end = safe(item.local_end).slice(11, 16);
        return end ? `${start}–${end}` : start;
    }

    function roundedStart(date) {
        const now = new Date();
        if (sameDate(date, now)) {
            const result = new Date(now);
            result.setSeconds(0, 0);
            result.setMinutes(Math.ceil(result.getMinutes() / 15) * 15);
            return result;
        }
        return new Date(date.getFullYear(), date.getMonth(), date.getDate(), 9, 0, 0, 0);
    }

    function startOfDay(value) {
        return new Date(value.getFullYear(), value.getMonth(), value.getDate(), 0, 0, 0, 0);
    }

    function endOfDay(value) {
        return new Date(value.getFullYear(), value.getMonth(), value.getDate(), 23, 59, 59, 999);
    }

    function dateInput(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    }

    function dateTimeInput(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return `${dateInput(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function parseDateInput(value) {
        const match = safe(value).match(/^(\d{4})-(\d{2})-(\d{2})$/);
        if (!match) return null;
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function parseLocalDate(value) {
        const match = safe(value).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
        if (!match) return null;
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    function selectTimezone(value) {
        const select = blockFields.timezone;
        // Keep previously accepted aliases/UTC offsets and newer browser zones
        // when the server timezone database does not list them yet.
        if (![...select.options].some(option => option.value === value)) {
            select.add(new Option(value, value));
        }
        select.value = value;
    }

    function browserTimezone() {
        try { return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC'; }
        catch (_) { return 'UTC'; }
    }

    function sameDate(left, right) {
        return left.getFullYear() === right.getFullYear()
            && left.getMonth() === right.getMonth()
            && left.getDate() === right.getDate();
    }

    function targetLabel(target) {
        const title = safe(target.title) || 'Без названия';
        const subtitle = safe(target.subtitle);
        return subtitle ? `${title} — ${subtitle}` : title;
    }

    function typeLabel(type) {
        return { task: 'Задача', note: 'Заметка', file: 'Файл' }[safe(type)] || 'Объект';
    }

    function emptyState(text) {
        const wrap = node('div', 'calendar-empty');
        const box = node('div');
        const icon = document.createElement('i');
        icon.className = 'fa fa-calendar-check-o';
        icon.setAttribute('aria-hidden', 'true');
        box.appendChild(icon);
        box.appendChild(node('p', '', text));
        wrap.appendChild(box);
        return wrap;
    }

    function option(value, text) {
        const item = document.createElement('option');
        item.value = value;
        item.textContent = text;
        return item;
    }

    function node(tag, className = '', text = '') {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== '') element.textContent = text;
        return element;
    }

    function showFeedback(element, text) {
        if (!element) return;
        element.hidden = false;
        element.textContent = text;
    }

    function hideFeedback(element) {
        if (!element) return;
        element.hidden = true;
        element.textContent = '';
    }

    function message(error, fallback) {
        return error instanceof Error && error.message ? error.message : fallback;
    }

    function safe(value) {
        return typeof value === 'string' ? value : value == null ? '' : String(value);
    }
})();
});
