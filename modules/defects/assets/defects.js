(() => {
    'use strict';

    const root = document.querySelector('[data-base-url][id="defects-workspace"]');
    const form = document.querySelector('[data-defects-create]');
    if (!root || !form) return;

    const prefix = root.dataset.baseUrl || '';
    const api = prefix + '/api/defects';
    const feedback = root.querySelector('[data-defects-feedback]');
    const results = root.querySelector('[data-defects-results]');
    const status = root.querySelector('[data-defects-status]');
    const more = root.querySelector('[data-defects-more]');
    const refresh = root.querySelector('[data-defects-refresh]');
    const summary = root.querySelector('[data-defects-summary]');
    const filterArea = root.querySelector('[data-defects-filter-area]');
    const filterObject = root.querySelector('[data-defects-filter-object]');
    const filterService = root.querySelector('[data-defects-filter-service]');
    const filterPriority = root.querySelector('[data-defects-priority]');
    const filterFrom = root.querySelector('[data-defects-from]');
    const filterTo = root.querySelector('[data-defects-to]');
    const csrf = root.querySelector('input[name="csrf_token"]');
    const area = form.elements.namedItem('area');
    const category = form.elements.namedItem('category');
    const object = form.elements.namedItem('object_id');
    const services = form.elements.namedItem('service_ids');
    const referenceForm = document.querySelector('[data-defects-reference]');
    let cursor = 0;

    const message = (value, error = false) => {
        feedback.textContent = value;
        feedback.setAttribute('role', error ? 'alert' : 'status');
    };

    const request = async (path, data) => {
        const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (data !== undefined) {
            options.method = 'POST';
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = csrf?.value || '';
            options.body = JSON.stringify(data);
        }
        const response = await fetch(path, options);
        const result = await response.json();
        if (!response.ok || result.status !== 'ok') {
            throw new Error(result.message || 'Не удалось обработать запрос');
        }
        return result;
    };

    const refill = (select, items, placeholder) => {
        select.replaceChildren(new Option(placeholder, ''));
        items.forEach(item => select.add(new Option(item.name, String(item.id))));
    };

    const references = async (kind, parentId) => {
        const query = new URLSearchParams({ kind, limit: '100' });
        if (parentId) query.set('parent_id', parentId);
        return (await request(api + '/references?' + query)).items;
    };

    const loadAreas = async () => {
        const items = await references('areas');
        refill(area, items, 'Выберите участок');
        refill(filterArea, items, 'Все участки');
        refill(category, [], 'Выберите категорию');
        refill(object, [], 'Выберите объект');
    };

    area.addEventListener('change', async () => {
        try {
            refill(category, area.value ? await references('categories', area.value) : [], 'Выберите категорию');
            refill(object, [], 'Выберите объект');
        } catch (error) { message(error.message, true); }
    });

    category.addEventListener('change', async () => {
        try {
            refill(object, category.value ? await references('objects', category.value) : [], 'Выберите объект');
        } catch (error) { message(error.message, true); }
    });

    const renderItem = item => {
        const article = document.createElement('article');
        article.className = 'calendar-shell';
        const title = document.createElement('h3');
        title.textContent = '#' + item.id + ' · ' + item.object_name + ' · ' + item.priority;
        const detail = document.createElement('p');
        detail.textContent = item.description;
        const due = document.createElement('p');
        due.textContent = 'Срок: ' + item.due_at + ' · ' + (item.status === 'closed' ? 'Закрыт' : 'Открыт');
        article.append(title, detail, due);
        if (item.status === 'open') {
            const close = document.createElement('button');
            close.type = 'button';
            close.textContent = 'Закрыть';
            close.addEventListener('click', async () => {
                const work = window.prompt('Какие работы выполнены?');
                if (!work?.trim()) return;
                close.disabled = true;
                try {
                    await request(api + '/' + item.id + '/close', {
                        completed_work: work, version: Number(item.version),
                        command_id: crypto.randomUUID(),
                    });
                    message('Дефект закрыт');
                    await loadRegistry(true);
                } catch (error) {
                    message(error.message, true);
                    close.disabled = false;
                }
            });
            article.append(close);
        }
        return article;
    };

    const reportFilters = () => {
        const values = { status: status.value };
        if (filterPriority.value) values.priority = filterPriority.value;
        if (filterArea.value) values.area_id = filterArea.value;
        if (filterObject.value) values.object_id = filterObject.value;
        if (filterService.value) values.service_id = filterService.value;
        if (filterFrom.value) values.from = filterFrom.value;
        if (filterTo.value) values.to = filterTo.value;
        return values;
    };

    const loadSummary = async () => {
        const query = new URLSearchParams(reportFilters());
        const { report } = await request(api + '/report?' + query);
        summary.replaceChildren();
        const main = document.createElement('p');
        main.textContent = 'Всего: ' + report.total + ' · Просрочено: ' + report.overdue;
        summary.append(main);
        const details = document.createElement('p');
        details.textContent = report.by_priority.map(row =>
            row.priority + ' / ' + row.status + ': ' + row.total
        ).join(' · ') || 'Нет записей';
        summary.append(details);
        const areas = document.createElement('p');
        areas.textContent = report.by_area.map(row =>
            row.area_name + ': ' + row.total
        ).join(' · ') || 'Нет участков';
        summary.append(areas);
    };

    const loadRegistry = async (reset = false) => {
        if (reset) {
            cursor = 0;
            results.replaceChildren();
        }
        refresh.disabled = true;
        try {
            const query = new URLSearchParams({ ...reportFilters(), limit: '40', after_id: String(cursor) });
            const data = await request(api + '/?' + query);
            data.items.forEach(item => results.append(renderItem(item)));
            cursor = data.next_cursor || 0;
            more.hidden = data.items.length < 40;
            if (reset && !data.items.length) message('По выбранному фильтру записей нет');
            if (reset) await loadSummary();
        } catch (error) { message(error.message, true); }
        finally { refresh.disabled = false; }
    };

    refresh.addEventListener('click', () => loadRegistry(true));
    [status, filterPriority, filterArea, filterObject, filterService, filterFrom, filterTo]
        .forEach(control => control.addEventListener('change', () => loadRegistry(true)));
    more.addEventListener('click', () => loadRegistry(false));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = form.querySelector('[type="submit"]');
        const detected = form.elements.namedItem('detected').value;
        const selectedServices = [...services.selectedOptions].map(option => Number(option.value));
        if (!detected || !selectedServices.length) {
            message('Укажите дату обнаружения и ответственные службы', true);
            return;
        }
        submit.disabled = true;
        try {
            const result = await request(api + '/', {
                object_id: Number(object.value),
                description: form.elements.namedItem('description').value,
                priority: form.elements.namedItem('priority').value,
                discovered_at: new Date(detected).toISOString(),
                registered_by_name: form.elements.namedItem('registered_by_name').value,
                service_ids: selectedServices,
                command_id: crypto.randomUUID(),
            });
            message('Дефект #' + result.defect.id + ' зарегистрирован');
            await loadRegistry(true);
            form.elements.namedItem('description').value = '';
        } catch (error) { message(error.message, true); }
        finally { submit.disabled = false; }
    });

    if (referenceForm) {
        const kind = referenceForm.elements.namedItem('kind');
        const parent = referenceForm.elements.namedItem('parent_id');
        const updateParents = async () => {
            const needed = kind.value === 'categories' ? 'areas' :
                kind.value === 'objects' ? 'categories' : null;
            parent.disabled = !needed;
            refill(parent, needed ? await references(needed, needed === 'categories' ? area.value : null) : [], 'Выберите родительскую запись');
        };
        kind.addEventListener('change', () => updateParents().catch(error => message(error.message, true)));
        referenceForm.addEventListener('submit', async event => {
            event.preventDefault();
            const button = referenceForm.querySelector('[type="submit"]');
            button.disabled = true;
            try {
                await request(api + '/references', {
                    kind: kind.value, name: referenceForm.elements.namedItem('name').value,
                    ...(parent.disabled ? {} : { parent_id: Number(parent.value) }),
                });
                message('Запись справочника добавлена');
                referenceForm.elements.namedItem('name').value = '';
                await Promise.all([loadAreas(), references('services').then(rows => {
            refill(services, rows, 'Выберите службы');
            refill(filterService, rows, 'Все службы');
        })]);
                await updateParents();
            } catch (error) { message(error.message, true); }
            finally { button.disabled = false; }
        });
        updateParents().catch(error => message(error.message, true));
    }

    Promise.all([
        loadAreas(), references('services').then(rows => {
            refill(services, rows, 'Выберите службы');
            refill(filterService, rows, 'Все службы');
        }),
        loadRegistry(true),
    ]).catch(error => message(error.message, true));
})();
