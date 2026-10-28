window.wspace.page.register('modules/defects/assets/defects.js', (pageScope) => {
(() => {
    'use strict';

    const root = document.querySelector('[id="defects-workspace"]');
    if (!root) return;

    const $ = (selector, parent = root) => parent.querySelector(selector);
    const $$ = (selector, parent = root) => [...parent.querySelectorAll(selector)];
    const api = (root.dataset.baseUrl || '') + '/api/defects';
    const form = $('[data-defects-create]');
    const createOverlay = $('[data-defects-create-overlay]');
    const detailOverlay = $('[data-defects-detail-overlay]');
    const statusButtons = $$('[data-defects-tab]');
    const results = $('[data-defects-results]');
    const feedback = $('[data-defects-feedback]');
    const refreshButton = $('[data-defects-refresh]');
    const moreButton = $('[data-defects-more]');
    const loading = $('[data-defects-loading]');
    const csrf = $('input[name="csrf_token"]');
    const area = form.elements.namedItem('area');
    const category = form.elements.namedItem('category');
    const object = form.elements.namedItem('object_id');
    const services = $('[data-defects-services]');
    const referenceForm = $('[data-defects-reference]');
    const closeForm = $('[data-defects-close]');
    const serviceEditForm = $('[data-defects-services-edit]');
    const serviceEditRows = $('[data-defects-edit-services]');
    const serviceEditError = $('[data-defects-edit-error]');
    const serviceEditAllowed = Boolean(serviceEditForm && !serviceEditForm.hidden);
    const displayButtons = Array.from(root.querySelectorAll('[data-defects-display]'));
    const sortSelect = $('[data-defects-sort]');
    const filters = {
        priority: $('[data-defects-priority]'),
        area_id: $('[data-defects-filter-area]'),
        object_id: $('[data-defects-filter-object]'),
        service_id: $('[data-defects-filter-service]'),
        from: $('[data-defects-from]'),
        to: $('[data-defects-to]'),
        registered_from: $('[data-defects-registered-from]'),
        registered_to: $('[data-defects-registered-to]'),
        due_from: $('[data-defects-due-from]'),
        due_to: $('[data-defects-due-to]'),
    };
    const state = {
        status: 'open', cursor: 0, sequence: 0, busy: false,
        selectedId: null, detail: null, detailSequence: 0,
        historyCursor: 0, creationCommand: null, closeCommand: null,
        previousFocus: null, historyLoading: false, serviceEditCommand: null,
        display: window.matchMedia('(max-width: 760px)').matches ? 'cards' : 'table',
    };

    const element = (tag, className = '', value = '') => {
        const item = document.createElement(tag);
        if (className) item.className = className;
        if (value !== '') item.textContent = String(value);
        return item;
    };
    const errorText = (error) => error instanceof Error ? error.message : 'Не удалось выполнить запрос';
    const newCommand = () => {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        return Array.from(bytes, value => value.toString(16).padStart(2, '0')).join('');
    };
    const feedbackMessage = (value, error = false) => {
        feedback.hidden = !value;
        feedback.textContent = value;
        feedback.dataset.error = String(error);
    };
    const showFormError = (selector, value) => {
        const target = $(selector);
        target.textContent = value;
        target.hidden = !value;
    };
    const request = async (path, data, options = {}) => {
        const config = { credentials: 'same-origin', headers: { Accept: 'application/json' }, ...options };
        if (data !== undefined) {
            config.method = 'POST';
            config.headers = {
                ...config.headers,
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf?.value || '',
            };
            config.body = JSON.stringify(data);
        }
        const response = await pageScope.fetch(path, config);
        if (!response.ok && response.status === 401) throw new Error('Войдите в систему повторно');
        let result;
        try { result = await response.json(); }
        catch { throw new Error('Сервер вернул неожиданный ответ'); }
        if (!response.ok || result.status !== 'ok') {
            throw new Error(result.message || 'Операция не выполнена');
        }
        return result;
    };
    const dateText = (value) => {
        if (!value) return 'Не указана';
        const date = new Date(String(value).replace(' ', 'T') + (/[Z+-]\d\d:\d\d$/.test(String(value)) ? '' : 'Z'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' });
    };
    const priorityText = (value) => ({
        urgent: 'Неотложный', first: 'Первоочередной',
        important: 'Важный', normal: 'Обычный',
    }[value] || 'Неизвестный');
    const activeOverlay = () => [createOverlay, detailOverlay].find(node => !node.hidden);
    const setModal = (overlay, opened, opener) => {
        if (opened) {
            state.previousFocus = opener || document.activeElement;
            overlay.hidden = false;
            $('section[role="dialog"]', overlay).focus();
            return;
        }
        overlay.hidden = true;
        if (state.previousFocus?.isConnected) state.previousFocus.focus();
        state.previousFocus = null;
    };

    pageScope.listen(document, 'keydown', (event) => {
        const overlay = activeOverlay();
        if (!overlay) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            if (overlay === detailOverlay) closeDetail();
            else setModal(createOverlay, false);
            return;
        }
        if (event.key !== 'Tab') return;
        const candidates = $$('button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex="0"]', overlay)
            .filter(node => !node.hidden && !node.closest('[hidden]'));
        if (!candidates.length) return;
        const first = candidates[0];
        const last = candidates[candidates.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            last.focus(); event.preventDefault();
        } else if (!event.shiftKey && document.activeElement === last) {
            first.focus(); event.preventDefault();
        }
    });

    const refRows = async (kind, parentId) => {
        const query = new URLSearchParams({ kind, limit: '100' });
        if (parentId) query.set('parent_id', String(parentId));
        return (await request(api + '/references?' + query)).items;
    };
    const fillSelect = (target, items, label) => {
        target.replaceChildren(new Option(label, ''));
        items.forEach(item => target.add(new Option(item.name, String(item.id))));
    };
    const loadAreas = async () => {
        const rows = await refRows('areas');
        fillSelect(area, rows, 'Выберите участок');
        fillSelect(filters.area_id, rows, 'Все участки');
        fillSelect(category, [], 'Выберите категорию');
        fillSelect(object, [], 'Выберите объект');
    };
    const loadServices = async () => {
        const rows = await refRows('services');
        fillSelect(filters.service_id, rows, 'Все службы');
        services.replaceChildren();
        rows.forEach(item => {
            const label = element('label');
            const input = document.createElement('input');
            input.type = 'checkbox';
            input.value = String(item.id);
            input.name = 'service_ids';
            label.append(input, document.createTextNode(item.name));
            services.append(label);
        });
    };

    const queryFilters = (includeStatus = true) => {
        const obj = includeStatus ? { status: state.status } : {};
        Object.entries(filters).forEach(([key, input]) => {
            if (input.value) obj[key] = input.value;
        });
        return obj;
    };
    const showSummary = (report) => {
        const priorities = Array.isArray(report.by_priority) ? report.by_priority : [];
        const count = (type) => priorities.filter(item => item.status === type)
            .reduce((total, item) => total + Number(item.total || 0), 0);
        $('[data-defects-count-open]').textContent = String(count('open'));
        $('[data-defects-count-closed]').textContent = String(count('closed'));
        $('[data-defects-count-overdue]').textContent = String(report.overdue || 0);
    };
    const summaryLoad = async (sequence) => {
        const query = new URLSearchParams({ ...queryFilters(false), status: 'all', area_limit: '1' });
        const { report } = await request(api + '/report?' + query);
        if (sequence === state.sequence) showSummary(report);
    };
    const isOverdue = (item) => item.status === 'open'
        && new Date(String(item.due_at).replace(' ', 'T') + 'Z').getTime() < Date.now();
    const renderRow = (item) => {
        const row = element('button', 'defects-row');
        // Цвет зависит от фактического срока; статус закрытия важнее подсветки.
        if (item.status === 'open') {
            const remaining = Date.parse(String(item.due_at).replace(' ', 'T') + 'Z') - Date.now();
            const registered = Date.parse(String(item.created_at).replace(' ', 'T') + 'Z');
            if (Number.isFinite(remaining) && remaining < 0) row.classList.add('defects-row--overdue');
            else if (Number.isFinite(remaining) && remaining <= 3 * 86400000) row.classList.add('defects-row--urgent');
            else if (Number.isFinite(remaining) && remaining <= 10 * 86400000) row.classList.add('defects-row--soon');
            else if (Number.isFinite(registered) && registered <= Date.now()
                && Date.now() - registered < 86400000) row.classList.add('defects-row--new');
        }
        row.type = 'button';
        row.dataset.defectId = String(item.id);
        const main = element('span', 'defects-row__main');
        main.append(element('span', 'defects-row__title', '№' + item.id + ' · ' + (item.object_name || 'Объект')));
        main.append(element('span', 'defects-row__meta', (item.area_name || 'Участок неизвестен')
            + ' · ' + (item.category_name || 'Без категории')));
        const aside = element('span', 'defects-row__aside');
        const tag = element('span', 'defects-tag', priorityText(item.priority));
        tag.dataset.priority = String(item.priority || '');
        aside.append(tag, element('span', isOverdue(item) ? 'defects-overdue' : '', 
            (isOverdue(item) ? 'Просрочен · ' : item.status === 'closed' ? 'Закрыт · ' : 'До · ') + dateText(item.due_at)));
        row.append(main, aside);
        row.addEventListener('click', () => void openDetail(item.id, row));
        return row;
    };
    // Табличное представление использует те же страницы и права доступа.
    const renderTableRow = (item) => {
        const row = element('tr', 'defects-row defects-table__row');
        row.dataset.defectId = String(item.id);
        row.tabIndex = 0;
        const fields = [
            '№' + item.id,
            item.area_name || '—',
            item.object_name || '—',
            dateText(item.due_at),
            item.description || '—',
            String(item.service_names || '').split(',').join(', ') || 'Не назначены',
            priorityText(item.priority),
            item.status === 'closed' ? 'Закрыт' : isOverdue(item) ? 'Просрочен' : 'Открыт',
        ];
        fields.forEach(value => row.append(element('td', '', value)));
        row.addEventListener('click', () => void openDetail(item.id, row));
        row.addEventListener('keydown', event => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            void openDetail(item.id, row);
        });
        return row;
    };
    const tableBody = () => {
        const existing = $('.defects-table tbody', results);
        if (existing) return existing;
        const table = element('table', 'defects-table');
        const head = element('thead');
        const heading = element('tr');
        ['ID', 'РЭС', 'Объект', 'Плановый срок', 'Описание', 'Службы', 'Приоритет', 'Состояние']
            .forEach(label => heading.append(element('th', '', label)));
        head.append(heading);
        const body = element('tbody');
        table.append(head, body);
        results.append(table);
        return body;
    };
    const syncDisplayButtons = () => displayButtons.forEach(button =>
        button.setAttribute('aria-pressed', String(button.dataset.defectsDisplay === state.display)));
    syncDisplayButtons();
    displayButtons.forEach(button => button.addEventListener('click', () => {
        if (state.display === button.dataset.defectsDisplay) return;
        state.display = button.dataset.defectsDisplay;
        syncDisplayButtons();
        void loadRegistry();
    }));
    sortSelect.addEventListener('change', () => void loadRegistry());

    const loadRegistry = async (reset = true) => {
        if (state.busy && !reset) return;
        const sequence = reset ? ++state.sequence : state.sequence;
        const cursor = reset ? 0 : state.cursor;
        if (reset) {
            results.replaceChildren();
            moreButton.hidden = true;
            state.cursor = 0;
        }
        state.busy = true;
        loading.hidden = false;
        refreshButton.disabled = true;
        moreButton.disabled = true;
        results.setAttribute('aria-busy', 'true');
        try {
            const query = new URLSearchParams({ ...queryFilters(), limit: '40', after_id: String(cursor) });
            if (sortSelect.value) query.set('sort', sortSelect.value);
            const data = await request(api + '/?' + query);
            if (sequence !== state.sequence) return;
            if (reset) results.replaceChildren();
            const target = state.display === 'table' ? tableBody() : results;
            (data.items || []).forEach(item =>
                target.append(state.display === 'table' ? renderTableRow(item) : renderRow(item)));
            state.cursor = Number(data.next_cursor || 0);
            moreButton.hidden = data.items.length < 40 || !state.cursor;
            if (!results.querySelector('[data-defect-id]')) {
                results.append(element('p', 'defects-empty', 'По выбранным условиям записей нет'));
            }
            if (reset) await summaryLoad(sequence);
        } catch (error) {
            if (sequence === state.sequence) feedbackMessage(errorText(error), true);
        } finally {
            if (sequence === state.sequence) {
                state.busy = false;
                loading.hidden = true;
                refreshButton.disabled = false;
                moreButton.disabled = false;
                results.removeAttribute('aria-busy');
            }
        }
    };
    const setStatus = (value) => {
        state.status = value;
        statusButtons.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.defectsTab === value)));
        void loadRegistry();
    };
    statusButtons.forEach(button => button.addEventListener('click', () => setStatus(button.dataset.defectsTab)));
    Object.values(filters).forEach(control => control.addEventListener('change', () => void loadRegistry()));
    $('[data-defects-reset]').addEventListener('click', () => {
        Object.values(filters).forEach(control => { control.value = ''; });
        void loadRegistry();
    });
    refreshButton.addEventListener('click', () => void loadRegistry());
    moreButton.addEventListener('click', () => void loadRegistry(false));

    const openCreate = (opener) => {
        showFormError('[data-defects-create-error]', '');
        setModal(createOverlay, true, opener);
    };
    $('[data-defects-create-open]').addEventListener('click', event => openCreate(event.currentTarget));
    $$('[data-defects-create-cancel]').forEach(button =>
        button.addEventListener('click', () => setModal(createOverlay, false)));
    // Зависимые списки очищаются сразу, до ответа AJAX.
    // Номер запроса защищает их от запоздавших ответов при быстром выборе.
    let categorySequence = 0;
    let objectSequence = 0;
    area.addEventListener('change', async () => {
        const sequence = ++categorySequence;
        ++objectSequence;
        const areaId = area.value;
        fillSelect(category, [], 'Загрузка категорий…');
        fillSelect(object, [], 'Выберите объект');
        if (!areaId) {
            fillSelect(category, [], 'Выберите категорию');
            return;
        }
        try {
            const rows = await refRows('categories', areaId);
            if (sequence !== categorySequence || area.value !== areaId) return;
            fillSelect(category, rows, 'Выберите категорию');
        } catch (error) {
            if (sequence === categorySequence) {
                fillSelect(category, [], 'Выберите категорию');
                showFormError('[data-defects-create-error]', errorText(error));
            }
        }
    });
    category.addEventListener('change', async () => {
        const sequence = ++objectSequence;
        const categoryId = category.value;
        fillSelect(object, [], 'Загрузка объектов…');
        if (!categoryId) {
            fillSelect(object, [], 'Выберите объект');
            return;
        }
        try {
            const rows = await refRows('objects', categoryId);
            if (sequence !== objectSequence || category.value !== categoryId) return;
            fillSelect(object, rows, 'Выберите объект');
        } catch (error) {
            if (sequence === objectSequence) {
                fillSelect(object, [], 'Выберите объект');
                showFormError('[data-defects-create-error]', errorText(error));
            }
        }
    });
    form.addEventListener('reset', () => {
        state.creationCommand = null;
        showFormError('[data-defects-create-error]', '');
        ++categorySequence;
        ++objectSequence;
        fillSelect(category, [], 'Выберите категорию');
        fillSelect(object, [], 'Выберите объект');
    });
    form.addEventListener('input', () => { state.creationCommand = null; });
    form.addEventListener('change', () => { state.creationCommand = null; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const checked = $$('input[name="service_ids"]:checked', services).map(node => Number(node.value));
        if (!checked.length) {
            showFormError('[data-defects-create-error]', 'Выберите хотя бы одну ответственную службу');
            return;
        }
        const detected = form.elements.namedItem('detected').value;
        const when = new Date(detected);
        if (Number.isNaN(when.getTime())) {
            showFormError('[data-defects-create-error]', 'Укажите корректную дату обнаружения');
            return;
        }
        const button = $('[type="submit"]', form);
        button.disabled = true;
        showFormError('[data-defects-create-error]', '');
        state.creationCommand ||= newCommand();
        try {
            const response = await request(api + '/', {
                object_id: Number(object.value),
                description: form.elements.namedItem('description').value,
                priority: form.elements.namedItem('priority').value,
                discovered_at: when.toISOString(),
                registered_by_name: form.elements.namedItem('registered_by_name').value,
                service_ids: checked, command_id: state.creationCommand,
            });
            state.creationCommand = null;
            form.reset();
            ++categorySequence;
            ++objectSequence;
            fillSelect(category, [], 'Выберите категорию');
            fillSelect(object, [], 'Выберите объект');
            setModal(createOverlay, false);
            feedbackMessage('Дефект №' + response.defect.id + ' зарегистрирован');
            await loadRegistry();
        } catch (error) { showFormError('[data-defects-create-error]', errorText(error)); }
        finally { button.disabled = false; }
    });

    const detailTitle = $('[data-defects-detail-title]');
    const detailFields = $('[data-defects-detail-fields]');
    const detailLoading = $('[data-defects-detail-loading]');
    const detailContent = $('[data-defects-detail-content]');
    const historyList = $('[data-defects-history-list]');
    const historyMore = $('[data-defects-history-more]');
    const closeError = $('[data-defects-close-error]');
    const addField = (label, value) => {
        detailFields.append(element('dt', '', label), element('dd', '', value == null ? '—' : String(value)));
    };
    const historyLabel = (event) => {
        if (event.event_type === 'updated' && event.details?.action === 'responsible_services') return 'Изменены службы и исполнители';
        if (event.event_type === 'created') return 'Зарегистрирован дефект';
        if (event.event_type === 'closed') return 'Дефект закрыт';
        return 'Изменение';
    };
    const appendHistory = (events) => {
        events.forEach(event => {
            const li = element('li');
            li.append(element('strong', '', historyLabel(event)));
            li.append(element('small', '', dateText(event.created_at)
                + ' · пользователь №' + (event.actor_user_id ?? 'не указан')));
            const details = event.details || {};
            const description = event.event_type === 'closed' ? details.completed_work
                : event.event_type === 'created' ? details.description : '';
            if (description) li.append(element('p', '', description));
            if (event.event_type === 'closed') {
                if (details.completed_by_name) li.append(element('p', '', 'Исполнитель: ' + details.completed_by_name));
                if (details.closed_at) li.append(element('p', '', 'Фактическое закрытие: ' + dateText(details.closed_at)));
                if (details.close_comment) li.append(element('p', '', 'Комментарий: ' + details.close_comment));
            }
            if (details.action === 'responsible_services') {
                const list = (details.after || []).map(item => 'служба №' + item.service_id + ': ' + (item.executor_name || 'исполнитель не назначен'));
                li.append(element('p', '', 'Новые назначения: ' + list.join('; ')));
            }
            historyList.append(li);
        });
    };
    const loadHistory = async (id, sequence, reset = false) => {
        if (state.historyLoading && !reset) return;
        state.historyLoading = true;
        historyMore.disabled = true;
        try {
            const cursor = reset ? 0 : state.historyCursor;
            const query = new URLSearchParams({ limit: '40', after_id: String(cursor) });
            const { history } = await request(api + '/' + id + '/history?' + query);
            if (state.detailSequence !== sequence) return;
            if (reset) historyList.replaceChildren();
            appendHistory(history.events);
            if (!historyList.children.length) {
                historyList.append(element('li', '', 'История изменений пока отсутствует'));
            }
            state.historyCursor = Number(history.next_cursor || 0);
            historyMore.hidden = !state.historyCursor;
        } catch (error) {
            if (state.detailSequence === sequence) feedbackMessage(errorText(error), true);
        } finally {
            if (state.detailSequence === sequence) {
                state.historyLoading = false;
                historyMore.disabled = false;
            }
        }
    };
    const renderServiceEditor = async (defect, sequence) => {
        if (!serviceEditAllowed) return;
        serviceEditForm.hidden = defect.status !== 'open';
        serviceEditRows.replaceChildren();
        serviceEditError.hidden = true;
        state.serviceEditCommand = null;
        if (serviceEditForm.hidden) return;

        // Дозагружаем весь справочник, иначе службы за первой сотней недоступны.
        const rows = [];
        let cursor = 0;
        for (let page = 0; page < 100; page += 1) {
            const query = new URLSearchParams({
                kind: 'services', limit: '100', after_id: String(cursor),
            });
            const batch = (await request(api + '/references?' + query)).items || [];
            rows.push(...batch);
            if (batch.length < 100) break;
            const lastId = Number(batch[batch.length - 1].id);
            if (!Number.isSafeInteger(lastId) || lastId <= cursor || page === 99) {
                throw new Error('Не удалось загрузить полный список служб');
            }
            cursor = lastId;
        }
        if (sequence !== state.detailSequence) return;
        const current = new Map((defect.services || []).map(item => [Number(item.id), item]));
        const all = new Map(rows.map(item => [Number(item.id), item]));
        (defect.services || []).forEach(item => all.set(Number(item.id), item));

        all.forEach((item, id) => {
            const entry = element('div', 'defects-services-editor__entry');
            entry.dataset.serviceId = String(id);
            const chooser = element('label');
            const enabled = document.createElement('input');
            enabled.type = 'checkbox';
            enabled.checked = current.has(id);
            chooser.append(enabled, document.createTextNode(item.name));
            const executorLabel = element('label', '', 'ФИО исполнителя');
            const executor = document.createElement('input');
            executor.type = 'text';
            executor.maxLength = 160;
            executor.placeholder = 'Пока не назначен';
            executor.value = String(current.get(id)?.executor_name || '');
            executor.disabled = !enabled.checked;
            enabled.addEventListener('change', () => {
                executor.disabled = !enabled.checked;
                state.serviceEditCommand = null;
            });
            executorLabel.append(executor);
            entry.append(chooser, executorLabel);
            serviceEditRows.append(entry);
        });
    };

    const openDetail = async (id, opener) => {
        const sequence = ++state.detailSequence;
        state.selectedId = Number(id);
        state.closeCommand = null;
        state.serviceEditCommand = null;
        state.historyCursor = 0;
        detailTitle.textContent = 'Дефект №' + id;
        detailLoading.textContent = 'Загрузка карточки…';
        detailLoading.hidden = false;
        detailContent.hidden = true;
        setModal(detailOverlay, true, opener);
        try {
            const { defect } = await request(api + '/' + id);
            if (sequence !== state.detailSequence) return;
            state.detail = defect;
            detailFields.replaceChildren();
            addField('Объект', defect.object_name || ('№' + defect.object_id));
            addField('Категория', defect.category_name);
            addField('Участок / РЭС', defect.area_name);
            addField('Описание', defect.description);
            addField('Приоритет', priorityText(defect.priority));
            addField('Обнаружен', dateText(defect.discovered_at));
            addField('Зарегистрирован', dateText(defect.created_at));
            addField('Срок устранения', dateText(defect.due_at));
            addField('Зарегистрировал', defect.registered_by_name);
            addField('Состояние', defect.status === 'closed' ? 'Закрыт' : 'Открыт');
            addField('Ответственные службы', (defect.services || []).map(item => item.name).join(', '));
            if (defect.completed_work) addField('Выполненные работы', defect.completed_work);
            closeForm.hidden = defect.status !== 'open' || isOverdue(defect);
            if (isOverdue(defect)) {
                addField('Ограничение', 'Срок истёк. Штатное закрытие недоступно; требуется решение ответственного лица.');
            }
            closeForm.reset();
            closeError.hidden = true;
            await renderServiceEditor(defect, sequence);
            detailLoading.hidden = true;
            detailContent.hidden = false;
            await loadHistory(id, sequence, true);
        } catch (error) {
            if (sequence === state.detailSequence) {
                detailLoading.textContent = errorText(error);
                feedbackMessage(errorText(error), true);
            }
        }
    };
    const closeDetail = () => {
        ++state.detailSequence;
        state.selectedId = null;
        state.detail = null;
        setModal(detailOverlay, false);
    };
    $$('[data-defects-detail-close]').forEach(button => button.addEventListener('click', closeDetail));
    historyMore.addEventListener('click', () => {
        if (state.selectedId) void loadHistory(state.selectedId, state.detailSequence);
    });
    if (serviceEditAllowed) {
        serviceEditForm.addEventListener('input', () => { state.serviceEditCommand = null; });
        $('[data-defects-services-refresh]').addEventListener('click', () => {
            if (state.selectedId) void openDetail(state.selectedId);
        });
        serviceEditForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (!state.detail || !state.selectedId || !serviceEditForm.reportValidity()) return;
            const assignments = $('[data-service-id]', serviceEditRows)
                .filter(entry => $('input[type="checkbox"]', entry).checked)
                .map(entry => ({
                    service_id: Number(entry.dataset.serviceId),
                    executor_name: $('input[type="text"]', entry).value.trim(),
                }));
            if (assignments.length < 1) {
                serviceEditError.textContent = 'Выберите хотя бы одну ответственную службу';
                serviceEditError.hidden = false;
                return;
            }
            const button = $('[type="submit"]', serviceEditForm);
            button.disabled = true;
            serviceEditError.hidden = true;
            state.serviceEditCommand ||= newCommand();
            const id = state.selectedId;
            try {
                await request(api + '/' + id + '/services', {
                    version: Number(state.detail.version),
                    command_id: state.serviceEditCommand,
                    assignments,
                });
                state.serviceEditCommand = null;
                await openDetail(id);
                await loadRegistry();
                feedbackMessage('Назначения по дефекту №' + id + ' сохранены');
            } catch (error) {
                serviceEditError.textContent = errorText(error) + '. Обновите карточку при конфликте версий.';
                serviceEditError.hidden = false;
            } finally {
                button.disabled = false;
            }
        });
    }
    closeForm.addEventListener('input', () => { state.closeCommand = null; });
    closeForm.addEventListener('submit', async event => {
        event.preventDefault();
        if (!closeForm.reportValidity() || !state.detail || !state.selectedId) return;
        const button = $('[type="submit"]', closeForm);
        button.disabled = true;
        closeError.hidden = true;
        const id = state.selectedId;
        state.closeCommand ||= newCommand();
        try {
            await request(api + '/' + id + '/close', {
                completed_work: closeForm.elements.namedItem('completed_work').value,
                closed_at: new Date(closeForm.elements.namedItem('closed_at').value).toISOString(),
                completed_by_name: closeForm.elements.namedItem('completed_by_name').value,
                close_comment: closeForm.elements.namedItem('close_comment').value,
                version: Number(state.detail.version), command_id: state.closeCommand,
            });
            state.closeCommand = null;
            closeDetail();
            feedbackMessage('Дефект №' + id + ' закрыт');
            await loadRegistry();
        } catch (error) {
            closeError.textContent = errorText(error)
                + (errorText(error).includes('изменилась') ? '. Обновите карточку перед повтором.' : '');
            closeError.hidden = false;
        } finally { button.disabled = false; }
    });

    let refreshManaged = async () => {};
    const manageRoot = $('[data-defects-reference-manager]');
    if (manageRoot && !manageRoot.closest('details')?.hidden) {
        const adminKind = $('[data-defects-manage-kind]', manageRoot);
        const showArchived = $('[data-defects-manage-archived]', manageRoot);
        const adminResults = $('[data-defects-manage-results]', manageRoot);
        const adminLoading = $('[data-defects-manage-loading]', manageRoot);
        const adminMore = $('[data-defects-manage-more]', manageRoot);
        const adminRefresh = $('[data-defects-manage-refresh]', manageRoot);
        const adminState = { cursor: 0, sequence: 0, loading: false, initialized: false };

        const mutate = async (row, item, action, data = {}) => {
            const buttons = $$('button', row);
            buttons.forEach(button => { button.disabled = true; });
            try {
                const { reference } = await request(api + '/references/'
                    + encodeURIComponent(adminKind.value) + '/' + item.id + '/' + action,
                    { version: item.version, ...data });
                feedbackMessage(reference.changed
                    ? 'Справочник обновлён, изменение сохранено в истории'
                    : 'Изменения не требуются');
                await Promise.all([refreshManaged(), loadAreas(), loadServices()]);
            } catch (error) {
                feedbackMessage(errorText(error), true);
                buttons.forEach(button => { button.disabled = false; });
                if (errorText(error).includes('изменена')) await refreshManaged();
            }
        };

        const renderManaged = item => {
            const row = element('div', 'defects-reference-row');
            const title = element('div', 'defects-reference-row__title');
            title.append(element('strong', '', item.name));
            title.append(element('small', '', '№' + item.id + ' · версия ' + item.version
                + (item.parent_id ? ' · родитель №' + item.parent_id : '')));
            const actions = element('div', 'defects-reference-row__actions');
            row.append(title, actions);

            if (item.is_archived) {
                const restore = element('button', 'defects-button defects-button--secondary ui-button ui-button--secondary', 'Восстановить');
                restore.type = 'button';
                restore.addEventListener('click', () => void mutate(row, item, 'restore'));
                actions.append(restore);
                return row;
            }

            const edit = element('button', 'defects-button defects-button--secondary ui-button ui-button--secondary', 'Переименовать');
            edit.type = 'button';
            edit.addEventListener('click', () => {
                if (row.querySelector('form')) return;
                const editor = element('form', 'defects-reference-editor');
                const label = element('label', '', 'Новое название');
                const input = document.createElement('input');
                input.name = 'name';
                input.value = item.name;
                input.maxLength = adminKind.value === 'objects' ? 180 : 160;
                input.required = true;
                const save = element('button', 'defects-button defects-button--primary ui-button ui-button--primary', 'Сохранить');
                save.type = 'submit';
                const cancel = element('button', 'defects-button defects-button--secondary ui-button ui-button--secondary', 'Отмена');
                cancel.type = 'button';
                cancel.addEventListener('click', () => {
                    editor.remove();
                    actions.hidden = false;
                    edit.focus();
                });
                editor.addEventListener('submit', event => {
                    event.preventDefault();
                    if (editor.reportValidity()) void mutate(row, item, 'rename', { name: input.value });
                });
                label.append(input);
                editor.append(label, save, cancel);
                actions.hidden = true;
                row.append(editor);
                input.focus();
            });

            const archive = element('button', 'defects-button defects-button--secondary ui-button ui-button--secondary', 'В архив');
            archive.type = 'button';
            archive.addEventListener('click', () => {
                if (actions.querySelector('[data-confirm-archive]')) return;
                archive.hidden = true;
                const warning = element('span', 'defects-reference-confirm', 'Подтвердить архивирование?');
                warning.dataset.confirmArchive = 'true';
                const yes = element('button', 'defects-button defects-button--secondary ui-button ui-button--secondary', 'Подтвердить');
                yes.type = 'button';
                yes.addEventListener('click', () => void mutate(row, item, 'archive'));
                const no = element('button', 'defects-button defects-button--secondary ui-button ui-button--secondary', 'Отмена');
                no.type = 'button';
                no.addEventListener('click', () => {
                    warning.remove(); yes.remove(); no.remove(); archive.hidden = false;
                });
                actions.append(warning, yes, no);
            });
            actions.append(edit, archive);
            return row;
        };

        const load = async (reset = true) => {
            if (adminState.loading && !reset) return;
            const sequence = reset ? ++adminState.sequence : adminState.sequence;
            const cursor = reset ? 0 : adminState.cursor;
            if (reset) {
                adminResults.replaceChildren();
                adminMore.hidden = true;
                adminState.cursor = 0;
            }
            adminState.loading = true;
            adminLoading.hidden = false;
            adminRefresh.disabled = true;
            adminMore.disabled = true;
            try {
                const query = new URLSearchParams({
                    kind: adminKind.value, archived: showArchived.checked ? '1' : '0',
                    after_id: String(cursor), limit: '40',
                });
                const { references } = await request(api + '/references/manage?' + query);
                if (sequence !== adminState.sequence) return;
                if (reset) adminResults.replaceChildren();
                references.items.forEach(item => adminResults.append(renderManaged(item)));
                adminState.cursor = Number(references.next_cursor || 0);
                adminMore.hidden = !adminState.cursor;
                if (!adminResults.children.length) {
                    adminResults.append(element('p', 'defects-empty', 'В этом списке записей нет'));
                }
            } catch (error) {
                if (sequence === adminState.sequence) feedbackMessage(errorText(error), true);
            } finally {
                if (sequence === adminState.sequence) {
                    adminLoading.hidden = true;
                    adminState.loading = false;
                    adminRefresh.disabled = false;
                    adminMore.disabled = false;
                }
            }
        };
        refreshManaged = () => load(true);
        adminKind.addEventListener('change', refreshManaged);
        showArchived.addEventListener('change', refreshManaged);
        adminRefresh.addEventListener('click', refreshManaged);
        adminMore.addEventListener('click', () => void load(false));
        const details = manageRoot.closest('details');
        details?.addEventListener('toggle', () => {
            if (details.open && !adminState.initialized) {
                adminState.initialized = true;
                void refreshManaged();
            }
        });
        if (details?.open) {
            adminState.initialized = true;
            void refreshManaged();
        }
    }

    const referenceHistoryForm = $('[data-defects-reference-history]');
    if (referenceHistoryForm) {
        referenceHistoryForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (!referenceHistoryForm.reportValidity()) return;
            const kind = referenceHistoryForm.elements.namedItem('kind').value;
            const id = referenceHistoryForm.elements.namedItem('record_id').value;
            const list = $('[data-defects-reference-history-results]', referenceHistoryForm);
            list.replaceChildren();
            try {
                const { history } = await request(api + '/references/'
                    + encodeURIComponent(kind) + '/' + encodeURIComponent(id) + '/history?limit=100');
                history.events.forEach(record => {
                    const li = element('li');
                    li.append(element('strong', '', ({created:'Создание',updated:'Переименование',archived:'Архивирование',restored:'Восстановление'}[record.event_type] || 'Изменение')));
                    li.append(element('small', '', dateText(record.created_at)
                        + ' · пользователь №' + (record.actor_user_id ?? 'не указан')));
                    if (record.details?.name) li.append(element('p', '', record.details.name));
                    if (record.details?.name_before) li.append(element('p', '',
                        record.details.name_before + ' → ' + (record.details.name_after || record.details.name_before)));
                    list.append(li);
                });
                if (!history.events.length) list.append(element('li', '', 'Истории пока нет'));
                if (history.next_cursor) list.append(element('li', '', 'Показаны первые 100 событий'));
            } catch (error) { feedbackMessage(errorText(error), true); }
        });
    }

    const importForm = $('[data-defects-import]');
    if (importForm) {
        const file = importForm.elements.namedItem('file');
        const previewButton = $('[data-defects-import-preview]', importForm);
        const applyButton = $('[data-defects-import-apply]', importForm);
        const output = $('[data-defects-import-result]', importForm);
        let hash = null;
        file.addEventListener('change', () => {
            hash = null; applyButton.disabled = true; output.textContent = '';
        });
        const upload = async (action, expectedHash) => {
            if (!file.files.length) throw new Error('Выберите файл');
            const body = new FormData();
            body.append('file', file.files[0]);
            if (expectedHash) body.append('expected_hash', expectedHash);
            const response = await pageScope.fetch(api + '/import/' + action, {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-CSRF-Token': csrf?.value || '' }, body,
            });
            const result = await response.json();
            if (!response.ok || result.status !== 'ok') throw new Error(result.message || 'Ошибка импорта');
            return result;
        };
        previewButton.addEventListener('click', async () => {
            previewButton.disabled = true; applyButton.disabled = true; hash = null;
            try {
                const { preview } = await upload('preview');
                output.textContent = 'Записей: ' + preview.total + '\n'
                    + preview.rows.map(item => item.line + ': ' + item.type + ' — ' + item.fields.join('; ')).join('\n')
                    + (preview.errors.length ? '\nОшибки:\n' + preview.errors.join('\n') : '');
                if (!preview.errors.length) {
                    hash = preview.hash; applyButton.disabled = false;
                }
            } catch (error) { feedbackMessage(errorText(error), true); }
            finally { previewButton.disabled = false; }
        });
        applyButton.addEventListener('click', async () => {
            if (!hash) return;
            applyButton.disabled = true;
            try {
                const { import: result } = await upload('apply', hash);
                output.textContent = 'Создано: ' + result.created + ' · Уже было: ' + result.existing;
                hash = null;
                await Promise.all([loadAreas(), loadServices(), refreshManaged()]);
                feedbackMessage('Импорт справочников завершён');
            } catch (error) { feedbackMessage(errorText(error), true); applyButton.disabled = false; }
        });
    }

    if (referenceForm) {
        const kind = referenceForm.elements.namedItem('kind');
        const parent = referenceForm.elements.namedItem('parent_id');
        const categoryArea = referenceForm.elements.namedItem('category_area');
        const parentAreaLabel = $('[data-defects-parent-area]', referenceForm);
        const updateParents = async () => {
            const needed = kind.value === 'categories' ? 'areas' :
                kind.value === 'objects' ? 'categories' : null;
            parent.disabled = !needed;
            parentAreaLabel.hidden = kind.value !== 'objects';
            categoryArea.disabled = kind.value !== 'objects';
            if (kind.value === 'objects') {
                const selectedArea = categoryArea.value;
                fillSelect(categoryArea, await refRows('areas'), 'Выберите участок');
                if ([...categoryArea.options].some(option => option.value === selectedArea)) categoryArea.value = selectedArea;
            }
            const rows = !needed || (needed === 'categories' && !categoryArea.value)
                ? [] : await refRows(needed, needed === 'categories' ? categoryArea.value : null);
            fillSelect(parent, rows, needed ? 'Выберите родителя' : 'Не требуется');
        };
        kind.addEventListener('change', () => void updateParents().catch(error => feedbackMessage(errorText(error), true)));
        categoryArea.addEventListener('change', async () => {
            if (kind.value !== 'objects') return;
            try {
                fillSelect(parent, categoryArea.value ? await refRows('categories', categoryArea.value) : [], 'Выберите категорию');
            } catch (error) { feedbackMessage(errorText(error), true); }
        });
        referenceForm.addEventListener('submit', async event => {
            event.preventDefault();
            if (!referenceForm.reportValidity()) return;
            const button = $('[type="submit"]', referenceForm);
            button.disabled = true;
            try {
                await request(api + '/references', {
                    kind: kind.value, name: referenceForm.elements.namedItem('name').value,
                    ...(parent.disabled ? {} : { parent_id: Number(parent.value) }),
                });
                referenceForm.elements.namedItem('name').value = '';
                await Promise.all([loadAreas(), loadServices(), refreshManaged()]);
                await updateParents();
                feedbackMessage('Справочник дополнен');
            } catch (error) { feedbackMessage(errorText(error), true); }
            finally { button.disabled = false; }
        });
        void updateParents();
    }

    // Страница не перезагружается при переходах внутри Notes.
    Promise.all([loadAreas(), loadServices()]).catch(error => feedbackMessage(errorText(error), true));
    void loadRegistry();
})();
});
