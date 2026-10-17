window.wspace.page.register('modules/calendar/assets/calendar.js', (pageScope) => {
(() => {
    'use strict';

    const root = document.querySelector('[data-calendar-root]');
    if (!root) return;

    const refs = {
        surface: root.querySelector('[data-calendar-surface]'),
        loading: root.querySelector('[data-calendar-loading]'),
        feedback: root.querySelector('[data-calendar-feedback]'),
        period: root.querySelector('[data-calendar-period]'),
        prev: root.querySelector('[data-calendar-prev]'),
        next: root.querySelector('[data-calendar-next]'),
        today: root.querySelector('[data-calendar-today]'),
        viewButtons: [...root.querySelectorAll('[data-calendar-view]')],
        create: document.querySelector('[data-calendar-create]'),
        editor: document.querySelector('[data-calendar-editor]')
    };

    const editor = refs.editor;
    const form = editor.querySelector('[data-calendar-form]');
    const fields = Object.fromEntries([...editor.querySelectorAll('[data-field]')].map((node) => [node.dataset.field, node]));
    const reminderInputs = [...editor.querySelectorAll('[data-reminder-offset]')];
    const weekdayInputs = [...editor.querySelectorAll('[data-weekday]')];
    const recurrenceBox = editor.querySelector('[data-calendar-recurrence]');
    const weekdaysBox = editor.querySelector('[data-calendar-weekdays]');
    const editorTitle = editor.querySelector('[data-calendar-editor-title]');
    const deleteButton = editor.querySelector('[data-calendar-delete]');
    const formFeedback = editor.querySelector('[data-calendar-form-feedback]');

    const state = {
        view: localStorage.getItem('workspace.calendar.view') || 'month',
        cursor: startOfDay(new Date()),
        events: [],
        range: null,
        loading: false,
        editorOpen: false
    };
    if (!['month', 'week', 'day'].includes(state.view)) state.view = 'month';

    init();

    function init() {
        refs.viewButtons.forEach((button) => {
            const active = button.dataset.calendarView === state.view;
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
            button.addEventListener('click', () => setView(button.dataset.calendarView));
        });
        refs.prev.addEventListener('click', () => move(-1));
        refs.next.addEventListener('click', () => move(1));
        refs.today.addEventListener('click', () => { state.cursor = startOfDay(new Date()); void refresh(); });
        refs.create.addEventListener('click', () => openCreate(state.cursor));
        editor.querySelectorAll('[data-calendar-close]').forEach((button) => button.addEventListener('click', closeEditor));
        fields.frequency.addEventListener('change', syncRecurrenceFields);
        form.addEventListener('submit', saveEvent);
        deleteButton.addEventListener('click', deleteEvent);
        pageScope.listen(document, 'keydown', (event) => {
            if (event.key === 'Escape' && state.editorOpen) closeEditor();
        });
        void refresh().then(openLinkedEvent);
    }

    function setView(view) {
        if (!['month', 'week', 'day'].includes(view)) return;
        state.view = view;
        localStorage.setItem('workspace.calendar.view', view);
        refs.viewButtons.forEach((button) => button.setAttribute('aria-pressed', button.dataset.calendarView === view ? 'true' : 'false'));
        void refresh();
    }

    function move(direction) {
        const next = new Date(state.cursor);
        if (state.view === 'month') next.setMonth(next.getMonth() + direction);
        else if (state.view === 'week') next.setDate(next.getDate() + (7 * direction));
        else next.setDate(next.getDate() + direction);
        state.cursor = startOfDay(next);
        void refresh();
    }

    async function refresh() {
        const range = visibleRange();
        state.range = range;
        refs.period.textContent = periodTitle(range);
        setLoading(true);
        hideFeedback(refs.feedback);
        try {
            const url = new URL(apiRoot(), window.location.origin);
            url.searchParams.set('from', range.start.toISOString());
            url.searchParams.set('to', range.end.toISOString());
            const payload = await requestJson(url.href);
            state.events = Array.isArray(payload.events) ? payload.events : [];
            render();
        } catch (error) {
            showFeedback(refs.feedback, message(error, 'Не удалось загрузить календарь.'));
            refs.surface.replaceChildren(emptyState('Не удалось открыть расписание.'));
        } finally {
            setLoading(false);
        }
    }

    function visibleRange() {
        if (state.view === 'day') {
            return { start: startOfDay(state.cursor), end: endOfDay(state.cursor) };
        }
        if (state.view === 'week') {
            const start = startOfWeek(state.cursor);
            const end = new Date(start);
            end.setDate(end.getDate() + 6);
            return { start, end: endOfDay(end) };
        }

        const first = new Date(state.cursor.getFullYear(), state.cursor.getMonth(), 1);
        const start = startOfWeek(first);
        const end = new Date(start);
        end.setDate(end.getDate() + 41);
        return { start, end: endOfDay(end) };
    }

    function periodTitle(range) {
        if (state.view === 'month') {
            return state.cursor.toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
        }
        if (state.view === 'day') {
            return state.cursor.toLocaleDateString('ru-RU', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        }
        const start = range.start.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
        const end = range.end.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });
        return `${start} — ${end}`;
    }

    function render() {
        refs.surface.replaceChildren();
        if (state.view === 'month') renderMonth();
        else renderAgenda();
    }

    function renderMonth() {
        const grid = node('div', 'calendar-month');
        ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'].forEach((label) => grid.appendChild(node('div', 'calendar-month__weekday', label)));
        const cursor = new Date(state.range.start);
        for (let index = 0; index < 42; index += 1) {
            const date = new Date(cursor);
            date.setDate(cursor.getDate() + index);
            grid.appendChild(monthCell(date));
        }
        refs.surface.appendChild(grid);
    }

    function monthCell(date) {
        const cell = node('div', 'calendar-day-cell');
        if (date.getMonth() !== state.cursor.getMonth()) cell.classList.add('is-outside');
        if (sameDate(date, new Date())) cell.classList.add('is-today');

        const number = node('div', 'calendar-day-cell__number', String(date.getDate()));
        cell.appendChild(number);
        const add = document.createElement('button');
        add.type = 'button';
        add.className = 'calendar-day-cell__add';
        add.setAttribute('aria-label', `Добавить событие ${formatDay(date)}`);
        add.addEventListener('click', () => openCreate(date));
        cell.appendChild(add);

        const list = node('div', 'calendar-day-cell__events');
        eventsForDate(date).slice(0, 4).forEach((event) => list.appendChild(eventChip(event)));
        const extra = eventsForDate(date).length - 4;
        if (extra > 0) list.appendChild(node('small', '', `Ещё ${extra}`));
        cell.appendChild(list);
        return cell;
    }

    function eventChip(event) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'calendar-event-chip';
        if (!event.all_day) button.appendChild(node('small', '', eventTime(event)));
        button.appendChild(node('strong', '', safe(event.title) || 'Событие'));
        button.addEventListener('click', (click) => {
            click.stopPropagation();
            void openEdit(event.uid);
        });
        return button;
    }

    function renderAgenda() {
        const wrap = node('div', 'calendar-agenda');
        const days = [];
        const start = new Date(state.range.start);
        const count = state.view === 'week' ? 7 : 1;
        for (let index = 0; index < count; index += 1) {
            const date = new Date(start);
            date.setDate(start.getDate() + index);
            days.push(date);
        }

        const hasEvents = days.some((date) => eventsForDate(date).length > 0);
        if (!hasEvents) {
            refs.surface.appendChild(emptyState('На этот период событий нет. Нажмите «Новое событие», чтобы добавить первое.'));
            return;
        }

        days.forEach((date) => {
            const section = node('section', 'calendar-agenda__day');
            const dateBox = node('div', 'calendar-agenda__date');
            dateBox.appendChild(node('strong', '', date.toLocaleDateString('ru-RU', { weekday: 'long' })));
            dateBox.appendChild(node('small', '', date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' })));
            section.appendChild(dateBox);
            const list = node('div', 'calendar-agenda__events');
            const events = eventsForDate(date);
            if (events.length === 0) list.appendChild(node('span', 'calendar-empty-day', 'Событий нет'));
            events.forEach((event) => list.appendChild(agendaCard(event)));
            section.appendChild(list);
            wrap.appendChild(section);
        });
        refs.surface.appendChild(wrap);
    }

    function agendaCard(event) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'calendar-agenda-card';
        button.appendChild(node('div', 'calendar-agenda-card__time', event.all_day ? 'Весь день' : eventTime(event)));
        const body = node('div', 'calendar-agenda-card__body');
        body.appendChild(node('strong', '', safe(event.title) || 'Событие'));
        const details = [safe(event.location), event.recurring ? 'Повторяется' : '', safe(event.timezone)].filter(Boolean).join(' · ');
        if (details) body.appendChild(node('span', '', details));
        button.appendChild(body);
        button.addEventListener('click', () => void openEdit(event.uid));
        return button;
    }

    function eventsForDate(date) {
        const key = dateKey(date);
        return state.events.filter((event) => safe(event.local_start).slice(0, 10) === key)
            .sort((a, b) => safe(a.local_start).localeCompare(safe(b.local_start)));
    }

    function openCreate(date) {
        resetForm();
        const start = roundedStart(date);
        const end = new Date(start.getTime() + 60 * 60 * 1000);
        fields.starts_at.value = inputDateTime(start);
        fields.ends_at.value = inputDateTime(end);
        selectTimezone(browserTimezone());
        fields.frequency.value = 'none';
        syncRecurrenceFields();
        editorTitle.textContent = 'Новое событие';
        deleteButton.hidden = true;
        openEditor();
        fields.title.focus();
    }

    async function openEdit(uid) {
        openEditor();
        form.classList.add('is-saving');
        hideFeedback(formFeedback);
        try {
            const payload = await requestJson(`${apiRoot()}/${encodeURIComponent(uid)}`);
            fillForm(payload.event || {});
            editorTitle.textContent = 'Изменить событие';
            deleteButton.hidden = false;
        } catch (error) {
            showFeedback(formFeedback, message(error, 'Не удалось открыть событие.'));
        } finally {
            form.classList.remove('is-saving');
        }
    }

    async function openLinkedEvent() {
        const uid = new URL(window.location.href).searchParams.get('event');
        if (uid && /^[0-9a-f-]{36}$/i.test(uid)) await openEdit(uid);
    }

    function fillForm(event) {
        resetForm();
        fields.uid.value = safe(event.uid);
        fields.title.value = safe(event.title);
        fields.description.value = safe(event.description);
        fields.location.value = safe(event.location);
        fields.all_day.checked = event.all_day === true;
        selectTimezone(safe(event.timezone) || browserTimezone());
        fields.starts_at.value = safe(event.local_start).slice(0, 16);
        fields.ends_at.value = safe(event.local_end).slice(0, 16);
        const recurrence = event.recurrence && typeof event.recurrence === 'object' ? event.recurrence : {};
        fields.frequency.value = safe(recurrence.frequency) || 'none';
        fields.interval.value = positiveInt(recurrence.interval) || 1;
        fields.until.value = safe(recurrence.until);
        const selectedDays = new Set(Array.isArray(recurrence.weekdays) ? recurrence.weekdays : []);
        weekdayInputs.forEach((input) => { input.checked = selectedDays.has(input.value); });
        const offsets = new Set(Array.isArray(event.reminder_offsets) ? event.reminder_offsets.map(Number) : []);
        reminderInputs.forEach((input) => { input.checked = offsets.has(Number(input.value)); });
        syncRecurrenceFields();
    }

    function resetForm() {
        form.reset();
        fields.uid.value = '';
        fields.interval.value = '1';
        selectTimezone(browserTimezone());
        hideFeedback(formFeedback);
    }

    function syncRecurrenceFields() {
        const recurring = fields.frequency.value !== 'none';
        recurrenceBox.hidden = !recurring;
        weekdaysBox.hidden = fields.frequency.value !== 'weekly';
    }

    async function saveEvent(event) {
        event.preventDefault();
        hideFeedback(formFeedback);
        const payload = formPayload();
        if (!payload.title || !payload.starts_at || !payload.timezone) {
            showFeedback(formFeedback, 'Заполните название, начало и часовой пояс.');
            return;
        }

        const uid = fields.uid.value.trim();
        const method = uid ? 'PUT' : 'POST';
        const url = uid ? `${apiRoot()}/${encodeURIComponent(uid)}` : apiRoot();
        setFormBusy(true);
        try {
            const response = await requestJson(url, {
                method,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(payload)
            });
            closeEditor();
            if (response.event?.local_start) state.cursor = dateFromLocal(response.event.local_start);
            await refresh();
        } catch (error) {
            showFeedback(formFeedback, message(error, 'Не удалось сохранить событие.'));
        } finally {
            setFormBusy(false);
        }
    }

    async function deleteEvent() {
        const uid = fields.uid.value.trim();
        if (!uid || !window.confirm('Удалить это событие и будущие напоминания?')) return;
        setFormBusy(true);
        try {
            await requestJson(`${apiRoot()}/${encodeURIComponent(uid)}`, { method: 'DELETE', headers: { 'Accept': 'application/json' } });
            closeEditor();
            await refresh();
        } catch (error) {
            showFeedback(formFeedback, message(error, 'Не удалось удалить событие.'));
        } finally {
            setFormBusy(false);
        }
    }

    function formPayload() {
        const frequency = fields.frequency.value;
        const recurrence = frequency === 'none' ? null : {
            frequency,
            interval: positiveInt(fields.interval.value) || 1,
            weekdays: frequency === 'weekly' ? weekdayInputs.filter((input) => input.checked).map((input) => input.value) : [],
            until: fields.until.value || null
        };
        return {
            title: fields.title.value.trim(),
            description: fields.description.value.trim(),
            location: fields.location.value.trim(),
            all_day: fields.all_day.checked,
            timezone: fields.timezone.value.trim(),
            starts_at: fields.starts_at.value,
            ends_at: fields.ends_at.value || null,
            recurrence,
            reminder_offsets: reminderInputs.filter((input) => input.checked).map((input) => Number(input.value))
        };
    }

    function openEditor() {
        window.wspace.modalMotion.open(refs.editor);
        state.editorOpen = true;
        document.documentElement.classList.add('calendar-editor-open');
    }

    function closeEditor() {
        state.editorOpen = false;
        window.wspace.modalMotion.close(refs.editor, () => {
            refs.editor.hidden = true;
            if (!document.querySelector('.calendar-editor:not([hidden])')) document.documentElement.classList.remove('calendar-editor-open');
            hideFeedback(formFeedback);
        });
    }

    function setFormBusy(busy) {
        form.classList.toggle('is-saving', busy);
        form.querySelectorAll('button,input,select,textarea').forEach((control) => { control.disabled = busy; });
    }

    function setLoading(loading) {
        state.loading = loading;
        refs.loading.hidden = !loading;
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
            const error = new Error(payload?.message || `HTTP ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    function apiRoot() {
        const path = '/api/calendar/events';
        return typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
    }

    function startOfWeek(value) {
        const date = startOfDay(value);
        const day = date.getDay() || 7;
        date.setDate(date.getDate() - day + 1);
        return date;
    }

    function startOfDay(value) {
        return new Date(value.getFullYear(), value.getMonth(), value.getDate(), 0, 0, 0, 0);
    }

    function endOfDay(value) {
        return new Date(value.getFullYear(), value.getMonth(), value.getDate(), 23, 59, 59, 999);
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

    function inputDateTime(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
    }

    function dateFromLocal(value) {
        const match = safe(value).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return match ? new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3])) : startOfDay(new Date());
    }

    function eventTime(event) {
        const value = safe(event.local_start);
        return value.length >= 16 ? value.slice(11, 16) : '';
    }

    function dateKey(date) {
        const pad = (value) => String(value).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
    }

    function formatDay(date) {
        return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
    }

    function sameDate(a, b) {
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function selectTimezone(value) {
        const select = fields.timezone;
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

    function emptyState(text) {
        const wrap = node('div', 'calendar-empty');
        const box = node('div');
        const icon = document.createElement('i');
        icon.className = 'fa fa-calendar-o';
        icon.setAttribute('aria-hidden', 'true');
        box.appendChild(icon);
        box.appendChild(node('p', '', text));
        wrap.appendChild(box);
        return wrap;
    }

    function node(tag, className = '', text = '') {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text !== '') element.textContent = text;
        return element;
    }

    function showFeedback(element, text) {
        element.hidden = false;
        element.textContent = text;
    }

    function hideFeedback(element) {
        element.hidden = true;
        element.textContent = '';
    }

    function message(error, fallback) {
        return error instanceof Error && error.message ? error.message : fallback;
    }

    function safe(value) {
        return typeof value === 'string' ? value : value == null ? '' : String(value);
    }

    function positiveInt(value) {
        const parsed = Number.parseInt(String(value), 10);
        return Number.isInteger(parsed) && parsed > 0 ? parsed : 0;
    }
})();
});
