window.wspace.page.register('modules/work-permits/assets/work-permits-safety.js', scope => {
    scope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        const panel = root?.querySelector('[data-wp-safety-records]');
        if (!panel) return;
        const api = (root.dataset.baseUrl || '') + '/api/work-permits';
        const feedback = root.querySelector('[data-wp-feedback]');
        const csrf = root.querySelector('[name=csrf_token]');
        const permission = panel.querySelector('[data-wp-preparation-permission]');
        const briefing = panel.querySelector('[data-wp-target-briefing]');
        const history = panel.querySelector('[data-wp-safety-history]');
        let current = null;
        let busy = false;
        let seq = 0;
        const commands = new WeakMap();
        const message = (text, failure = false) => {
            feedback.hidden = !text;
            feedback.textContent = text;
            feedback.dataset.state = failure ? 'error' : 'success';
        };
        const request = async (path, body) => {
            const config = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (body !== undefined) {
                config.method = 'POST';
                config.headers['Content-Type'] = 'application/json';
                config.headers['X-CSRF-Token'] = csrf?.value || '';
                config.body = JSON.stringify(body);
            }
            const response = await scope.fetch(api + path, config);
            const data = await response.json().catch(() => null);
            if (!response.ok || data?.status !== 'ok') {
                throw new Error(data?.message || 'Не удалось записать событие');
            }
            return data;
        };
        const employeeList = async organization => {
            const result = [];
            let cursor = '';
            for (let index = 0; index < 5; index++) {
                const query = new URLSearchParams({ organization_id: String(organization), limit: '100' });
                if (cursor) query.set('before_id', cursor);
                const page = await request('/references/employees?' + query);
                result.push(...(page.items || []));
                if (!page.next_cursor) return result;
                cursor = String(page.next_cursor);
            }
            throw new Error('Превышен лимит справочника работников');
        };
        const choose = (form, name, people, selected) => {
            const select = form.elements.namedItem(name);
            select.replaceChildren(new Option('Выберите работника', ''));
            for (const worker of people) {
                select.add(new Option(worker.name + ' (гр. ' + (worker.electrical_group || '—') + ')',
                    String(worker.id)));
            }
            select.value = String(selected || '');
        };
        const row = text => {
            const element = document.createElement('p');
            element.textContent = text;
            history.append(element);
        };
        const load = async id => {
            if (busy) return;
            busy = true;
            const generation = ++seq;
            message('Загрузка записей безопасности…');
            try {
                const doc = (await request('/' + Number(id))).document;
                if (doc.kind !== 'permit' || doc.state !== 'issued') {
                    throw new Error('Действие доступно только для выданного наряда');
                }
                const people = await employeeList(doc.organization_id);
                if (generation !== seq) return;
                current = doc;
                window.WorkPermitTime.context(panel, doc);
                permission.reset();
                briefing.reset();
                history.replaceChildren();
                for (const item of doc.lifecycle?.preparation_permissions || []) {
                    row('Разрешение: ' + window.WorkPermitTime.display(item.granted_at, doc)
                        + ' · ' + item.grantor_name + ' → ' + item.receiver_name
                        + ' · ' + item.workplace);
                }
                for (const item of doc.lifecycle?.target_briefings || []) {
                    row('Инструктаж (' + item.stage + '): '
                        + window.WorkPermitTime.display(item.briefed_at, doc)
                        + ' · ' + item.instructor_name + ' → ' + item.recipient_name
                        + ' · ' + item.topic);
                }
                if (!history.children.length) row('Новых записей ещё нет.');
                const crew = doc.crew || [];
                const role = name => crew.find(p => p.role === name)?.employee_id;
                choose(permission, 'grantor_employee_id', people, role('producer'));
                choose(permission, 'receiver_employee_id', people,
                    role('admitter') || role('producer'));
                choose(briefing, 'instructor_employee_id', people, role('issuer'));
                choose(briefing, 'recipient_employee_id', people, role('producer'));
                permission.hidden = !!doc.lifecycle?.first_admission;
                briefing.hidden = !!doc.lifecycle?.completion;
                panel.querySelector('[data-wp-safety-title]').textContent =
                    'Наряд №' + doc.id + ' · версия ' + doc.version;
                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                message('');
            } catch (error) {
                message(error.message, true);
            } finally {
                busy = false;
            }
        };
        scope.listen(root, 'wp:safety-open', event => void load(event.detail.id));
        scope.listen(panel.querySelector('[data-wp-safety-close]'), 'click', () => {
            panel.hidden = true;
            ++seq;
            current = null;
            message('');
        });
        const newCommand = () => {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        };
        for (const [form, path, dateField, confirmation] of [
            [permission, 'preparation-permission', 'granted_at', 'permission_confirmed'],
            [briefing, 'target-briefing', 'briefed_at', 'briefing_confirmed'],
        ]) {
            scope.listen(form, 'input', () => commands.delete(form));
            scope.listen(form, 'change', () => commands.delete(form));
            scope.listen(form, 'submit', async event => {
                event.preventDefault();
                if (busy || !current) return;
                busy = true;
                const button = form.querySelector('[type=submit]');
                button.disabled = true;
                form.setAttribute('aria-busy', 'true');
                try {
                    if (!commands.has(form)) commands.set(form, newCommand());
                    const payload = Object.fromEntries(new FormData(form));
                    payload.version = Number(current.version);
                    payload.command_id = commands.get(form);
                    payload[dateField] = window.WorkPermitTime.toUtc(
                        form.elements.namedItem(dateField), current);
                    payload[confirmation] = payload[confirmation] === 'on';
                    const id = Number(current.id);
                    await request('/' + id + '/' + path, payload);
                    commands.delete(form);
                    busy = false;
                    root.dispatchEvent(new CustomEvent('wp:lifecycle-recorded'));
                    await load(id);
                    message('Событие зарегистрировано. Для каждого получателя нужна отдельная запись.');
                } catch (error) {
                    message(error.message, true);
                } finally {
                    busy = false;
                    button.disabled = false;
                    form.removeAttribute('aria-busy');
                }
            });
        }
    });
});
