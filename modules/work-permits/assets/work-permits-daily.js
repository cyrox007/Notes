window.wspace.page.register('modules/work-permits/assets/work-permits-daily.js', (pageScope) => {
    pageScope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        const panel = root?.querySelector('[data-wp-daily-panel]');
        if (!panel) return;

        const base = (root.dataset.baseUrl || '') + '/api/work-permits';
        const csrf = root.querySelector('[name="csrf_token"]');
        const alertBox = root.querySelector('[data-wp-feedback]');
        const rows = panel.querySelector('[data-wp-period-list]');
        const startForm = panel.querySelector('[data-wp-period-start]');
        const stopForm = panel.querySelector('[data-wp-period-stop]');
        const state = { document: null, action: null, command: null, busy: false, sequence: 0 };

        const feedback = (message, error = false) => {
            alertBox.hidden = !message;
            alertBox.textContent = message;
            alertBox.dataset.state = error ? 'error' : 'success';
        };
        const request = async (path, body) => {
            const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (body !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrf?.value || '';
                options.body = JSON.stringify(body);
            }
            const response = await pageScope.fetch(base + path, options);
            const result = await response.json().catch(() => null);
            if (!response.ok || result?.status !== 'ok') {
                throw new Error(result?.message || 'Не удалось записать рабочий период');
            }
            return result;
        };
        const command = () => {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        };
        const line = (parent, label) => {
            const node = document.createElement('p');
            node.textContent = label;
            parent.append(node);
        };
        const choices = (select, employees, placeholder, selected) => {
            select.replaceChildren(new Option(placeholder, ''));
            for (const person of employees) {
                select.add(new Option(person.name + ' (' + (person.electrical_group || 'группа не указана') + ')',
                    String(person.id)));
            }
            select.value = selected ? String(selected) : '';
        };
        const employees = async org => {
            const all = [];
            let cursor = '';
            for (let page = 0; page < 5; page += 1) {
                const query = new URLSearchParams({ organization_id: String(org), limit: '100' });
                if (cursor) query.set('before_id', cursor);
                const response = await request('/references/employees?' + query);
                all.push(...(response.items || []));
                if (!response.next_cursor) return all;
                cursor = String(response.next_cursor);
            }
            throw new Error('Невозможно показать больше 500 сотрудников без уточнения справочника');
        };
        const render = async detail => {
            const periods = detail.lifecycle?.periods || [];
            rows.replaceChildren();
            for (const [index, period] of periods.entries()) {
                const item = document.createElement('article');
                item.className = 'wp-journal__row';
                const title = document.createElement('strong');
                title.textContent = 'Период №' + (index + 1)
                    + (period.start_kind === 'first' ? ' — первый допуск'
                        : period.start_kind === 'resume' ? ' — после перерыва' : ' — дневной допуск');
                item.append(title);
                line(item, 'Начало: ' + window.WorkPermitTime.display(period.started_at, detail)
                    + ' · допускающий: ' + period.admitter_name);
                line(item, 'Рабочее место: ' + (period.workplace || 'Не указано в исходной записи'));
                line(item, 'Инструктаж провёл: ' + period.instructor_name
                    + ' · получил: ' + period.recipient_name);
                line(item, period.ended_at
                    ? 'Остановка: ' + window.WorkPermitTime.display(period.ended_at, detail)
                        + ' · ' + (period.end_kind === 'break' ? 'перерыв' : 'конец рабочего дня')
                    : 'Работы продолжаются');
                if (period.end_note) line(item, 'Примечание: ' + period.end_note);
                rows.append(item);
            }
            startForm.hidden = true;
            stopForm.hidden = true;
            const last = periods[periods.length - 1];
            if (!last) {
                line(rows, 'Сначала оформите первичный допуск к выданному наряду.');
                return;
            }
            if (!last.ended_at) {
                state.action = 'stop';
                stopForm.hidden = false;
                return;
            }
            state.action = last.end_kind === 'break' ? 'resume' : 'daily_admission';
            startForm.hidden = false;
            startForm.elements.namedItem('workplace').value = last.workplace || '';
            startForm.querySelector('[data-wp-period-start-title]').textContent =
                state.action === 'resume' ? 'Возобновление после перерыва' : 'Допуск в следующий рабочий день';
            const all = await employees(detail.organization_id);
            const selected = {
                admitted_employee_id: last.admitter_employee_id,
                briefed_by_employee_id: last.instructor_employee_id,
                briefing_recipient_id: detail.lifecycle.issuance.producer_employee_id,
            };
            for (const select of startForm.querySelectorAll('select')) {
                choices(select, all, 'Выберите работника', selected[select.name]);
            }
        };
        const open = async id => {
            if (state.busy) return;
            state.busy = true;
            const sequence = ++state.sequence;
            feedback('Загружается история рабочих периодов…');
            try {
                const detail = (await request('/' + id)).document;
                if (sequence !== state.sequence) return;
                if (detail.kind !== 'permit' || detail.state !== 'issued') {
                    throw new Error('Дневные допуски доступны только для выданного наряда');
                }
                state.document = detail;
                window.WorkPermitTime.context(panel, detail);
                state.command = null;
                startForm.reset();
                stopForm.reset();
                await render(detail);
                if (sequence !== state.sequence) return;
                panel.querySelector('[data-wp-daily-title]').textContent =
                    'Допуски и перерывы по наряду №' + detail.id + ' · версия ' + detail.version;
                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                feedback('');
            } catch (error) {
                feedback(error.message, true);
            } finally {
                state.busy = false;
            }
        };
        pageScope.listen(root, 'wp:daily-open', event => void open(event.detail.id));
        pageScope.listen(panel.querySelector('[data-wp-period-close]'), 'click', () => {
            panel.hidden = true;
            state.document = null;
            state.command = null;
            state.sequence += 1;
        });
        pageScope.listen(panel, 'input', () => { state.command = null; });
        pageScope.listen(panel, 'change', () => { state.command = null; });
        const submit = async (form, action) => {
            if (state.busy || !state.document) return;
            state.busy = true;
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            form.setAttribute('aria-busy', 'true');
            try {
                if (!state.command) state.command = command();
                const input = Object.fromEntries(new FormData(form));
                const payload = {
                    version: Number(state.document.version),
                    command_id: state.command,
                };
                let route = action;
                if (action === 'stop') {
                    route = input.end_kind;
                    payload.ended_at = window.WorkPermitTime.toUtc(stopForm.elements.namedItem('ended_at'), state.document);
                    payload.note = input.note;
                    payload.work_stopped = input.work_stopped === 'on';
                } else {
                    payload.started_at = window.WorkPermitTime.toUtc(startForm.elements.namedItem('started_at'), state.document);
                    payload.admitted_employee_id = input.admitted_employee_id;
                    payload.briefed_by_employee_id = input.briefed_by_employee_id;
                    payload.briefing_recipient_id = input.briefing_recipient_id;
                    payload.workplace = input.workplace;
                    payload.workplace_prepared = input.workplace_prepared === 'on';
                    payload.briefing_confirmed = input.briefing_confirmed === 'on';
                }
                await request('/' + state.document.id + '/work-period/' + route, payload);
                const id = Number(state.document.id);
                state.command = null;
                state.document = null;
                state.busy = false;
                root.dispatchEvent(new CustomEvent('wp:lifecycle-recorded'));
                await open(id);
                feedback('Событие рабочей смены сохранено.');
            } catch (error) {
                feedback(error.message, true);
            } finally {
                state.busy = false;
                button.disabled = false;
                form.removeAttribute('aria-busy');
            }
        };
        pageScope.listen(startForm, 'submit', event => {
            event.preventDefault();
            void submit(startForm, state.action);
        });
        pageScope.listen(stopForm, 'submit', event => {
            event.preventDefault();
            void submit(stopForm, 'stop');
        });
    });
});
