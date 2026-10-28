window.wspace.page.register('modules/work-permits/assets/work-permits-lifecycle.js', (pageScope) => {
    pageScope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        const panel = root?.querySelector('[data-wp-lifecycle]');
        if (!panel) return;

        const api = (root.dataset.baseUrl || '') + '/api/work-permits';
        const issueForm = panel.querySelector('[data-wp-issue-form]');
        const admitForm = panel.querySelector('[data-wp-admission-form]');
        const title = panel.querySelector('[data-wp-lifecycle-title]');
        const list = panel.querySelector('[data-wp-measure-list]');
        const csrf = root.querySelector('[name="csrf_token"]');
        const feedback = root.querySelector('[data-wp-feedback]');
        const state = { document: null, action: null, command: null, busy: false };

        const alert = (text, error = false) => {
            feedback.hidden = !text;
            feedback.textContent = text;
            feedback.dataset.state = error ? 'error' : 'success';
        };
        const request = async (suffix, payload) => {
            const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (payload !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrf?.value || '';
                options.body = JSON.stringify(payload);
            }
            const response = await pageScope.fetch(api + suffix, options);
            const result = await response.json().catch(() => null);
            if (!response.ok || result?.status !== 'ok') {
                throw new Error(result?.message || 'Операция с документом не выполнена');
            }
            return result;
        };
        // Шаблоны только подставляют текст, его можно изменить перед выдачей.
        const issuePickers = [...issueForm.querySelectorAll('[data-wp-issue-template]')];
        let issueTemplatesGeneration = 0;
        const loadIssueTemplates = async (organization) => {
            const generation = ++issueTemplatesGeneration;
            await Promise.all(issuePickers.map(async picker => {
                const kind = picker.dataset.wpIssueTemplate;
                const select = picker.querySelector('[data-wp-issue-choice]');
                const button = picker.querySelector('[data-wp-issue-apply]');
                select.disabled = true;
                button.disabled = true;
                select.replaceChildren(new Option('Загрузка шаблонов…', ''));
                const params = new URLSearchParams({
                    organization_id: String(organization), kind, limit: '100',
                });
                const result = await request('/templates?' + params);
                if (generation !== issueTemplatesGeneration) return;
                select.replaceChildren(new Option('Без шаблона', ''));
                for (const template of result.items || []) {
                    select.add(new Option(template.name, String(template.id)));
                }
                select.disabled = false;
                button.disabled = false;
            }));
        };
        for (const picker of issuePickers) {
            const select = picker.querySelector('[data-wp-issue-choice]');
            const button = picker.querySelector('[data-wp-issue-apply]');
            pageScope.listen(button, 'click', async () => {
                const organization = state.document?.organization_id;
                const templateId = select.value;
                if (!organization || !templateId || state.action !== 'issue') return;
                button.disabled = true;
                try {
                    const params = new URLSearchParams({ organization_id: String(organization) });
                    const template = (await request('/templates/' + Number(templateId) + '?' + params)).template;
                    const kind = picker.dataset.wpIssueTemplate;
                    if (template.kind !== kind) throw new Error('Вид шаблона не совпадает');
                    const fieldName = kind === 'conditions' ? 'work_conditions' : 'special_instructions';
                    const field = issueForm.elements.namedItem(fieldName);
                    field.value = template.work_description || '';
                    field.dispatchEvent(new Event('input', { bubbles: true }));
                    alert('Текст подставлен. Проверьте его и уточните для конкретного объекта.');
                } catch (error) {
                    alert(error.message, true);
                } finally {
                    button.disabled = false;
                }
            });
        }

        const manager = root.querySelector('[data-wp-management]');
        const managerList = manager?.querySelector('[data-wp-management-list]');
        const managerState = manager?.querySelector('[data-wp-management-state]');
        const managerMore = manager?.querySelector('[data-wp-management-more]');
        let managerCursor = null;
        let managerLoading = false;
        const loadManager = async (reset = false) => {
            if (!manager || managerLoading) return;
            managerLoading = true;
            managerList.setAttribute('aria-busy', 'true');
            managerMore.disabled = true;
            try {
                const query = new URLSearchParams({ state: managerState.value, limit: '20' });
                if (!reset && managerCursor) query.set('before_id', String(managerCursor));
                const result = await request('/management?' + query);
                if (reset) managerList.replaceChildren();
                for (const item of result.items || []) {
                    const row = document.createElement('article');
                    row.className = 'wp-journal__row';
                    const title = document.createElement('strong');
                    title.textContent = (item.kind === 'permit' ? 'Наряд №' : 'Распоряжение №')
                        + item.id + ' — ' + item.facility;
                    const author = document.createElement('small');
                    author.textContent = 'Создатель №' + item.created_by_user_id + ' · версия ' + item.version;
                    const action = item.state === 'draft' ? 'issue'
                        : item.state === 'issued' && item.kind === 'permit'
                            && !Number(item.has_first_admission) ? 'admit' : null;
                    row.append(title, author);
                    if (action) {
                        const button = document.createElement('button');
            button.className = 'ui-button ui-button--secondary';
                        button.type = 'button';
                        button.textContent = action === 'issue' ? 'Выдать' : 'Оформить первый допуск';
                        pageScope.listen(button, 'click', () => void open({
                            id: Number(item.id), action,
                        }));
                        row.append(button);
                    }
                    if (item.state === 'issued' && item.kind === 'permit') {
                        const periods = document.createElement('button');
            periods.className = 'ui-button ui-button--secondary';
                        periods.type = 'button';
                        periods.textContent = 'Рабочие смены и перерывы';
                        pageScope.listen(periods, 'click', () => root.dispatchEvent(
                            new CustomEvent('wp:daily-open', { detail: { id: Number(item.id) } })
                        ));
                        row.append(periods);
                    }
                    if (item.state === 'issued' && item.kind === 'permit') {
                        const safetyButton = document.createElement('button');
            safetyButton.className = 'ui-button ui-button--secondary';
                        safetyButton.type = 'button';
                        safetyButton.textContent = 'Разрешения и инструктажи';
                        pageScope.listen(safetyButton, 'click', () => root.dispatchEvent(
                            new CustomEvent('wp:safety-open', { detail: { id: Number(item.id) } })
                        ));
                        row.append(safetyButton);
                    }
                    if (item.state === 'issued' && item.kind === 'permit') {
                        const button = document.createElement('button');
            button.className = 'ui-button ui-button--secondary';
                        button.type = 'button';
                        button.textContent = 'Состав бригады и продление';
                        pageScope.listen(button, 'click', () => root.dispatchEvent(
                            new CustomEvent('wp:amendments-open', { detail: { id: Number(item.id) } })
                        ));
                        row.append(button);
                    }
                    if (item.state === 'issued') {
                        const finishButton = document.createElement('button');
            finishButton.className = 'ui-button ui-button--secondary';
                        finishButton.type = 'button';
                        const stage = item.kind === 'order' ? 'close_order'
                            : Number(item.has_completion) ? 'close_permit' : 'complete';
                        finishButton.textContent = stage === 'complete'
                            ? 'Окончание работ' : 'Закрыть документ';
                        pageScope.listen(finishButton, 'click', () => root.dispatchEvent(
                            new CustomEvent('wp:closure-open', { detail: { id: Number(item.id) } })
                        ));
                        row.append(finishButton);
                    }
                    if (item.kind === 'permit' && item.state !== 'draft') {
                        const link = document.createElement('a');
                        link.textContent = 'Скачать PDF';
                        link.href = api + '/' + Number(item.id) + '/pdf';
                        link.setAttribute('download', 'naryad-' + Number(item.id) + '.pdf');
                        row.append(link);
                        const preview = document.createElement('a');
                        preview.textContent = 'Макет бланка для сверки';
                        preview.href = api + '/' + Number(item.id) + '/pdf?layout=form';
                        row.append(preview);
                    }
                    managerList.append(row);
                }
                managerCursor = result.next_cursor || null;
                managerMore.hidden = !managerCursor;
                if (!managerList.children.length) {
                    const empty = document.createElement('p');
                    empty.textContent = 'Документов в выбранном состоянии нет.';
                    managerList.append(empty);
                }
            } catch (error) {
                if (!managerList.querySelector('article')) {
                    const message = document.createElement('p');
                    message.textContent = 'Не удалось загрузить журнал. Обновите страницу.';
                    managerList.replaceChildren(message);
                }
                alert(error.message, true);
            } finally {
                managerLoading = false;
                managerList.removeAttribute('aria-busy');
                managerMore.disabled = false;
            }
        };
        if (manager) {
            pageScope.listen(managerState, 'change', () => void loadManager(true));
            pageScope.listen(managerMore, 'click', () => void loadManager(false));
            pageScope.listen(root, 'wp:lifecycle-recorded', () => void loadManager(true));
            void loadManager(true);
        }

        const command = () => {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
        };
        const addMeasure = () => {
            if (list.children.length >= 20) {
                alert('Не более двадцати мероприятий', true);
                return;
            }
            const row = document.createElement('fieldset');
            row.className = 'wp-journal__measure';
            for (const [name, label, length, value] of [
                ['equipment', 'Оборудование / участок', 240, ''],
                ['isolation', 'Что отключить и где заземлить (либо «Не требуется»)', 1000, ''],
                ['isolated_parts', 'Что изолировать или оградить (либо «Не требуется»)', 1000, ''],
                ['remaining_live', 'Что осталось под напряжением', 500,
                    'Оборудование и токоведущие части, оставшиеся в работе под напряжением.'],
            ]) {
                const wrapper = document.createElement('label');
                wrapper.textContent = label;
                const input = document.createElement('textarea');
                input.name = name;
                input.maxLength = length;
                input.rows = 2;
                input.value = value;
                input.required = true;
                wrapper.append(input);
                row.append(wrapper);
            }
            const remove = document.createElement('button');
            remove.className = 'ui-button ui-button--secondary';
            remove.type = 'button';
            remove.textContent = 'Удалить мероприятие';
            pageScope.listen(remove, 'click', () => {
                if (list.children.length > 1) row.remove();
            });
            row.append(remove);
            list.append(row);
        };
        const fillEmployees = async (org) => {
            const employees = [];
            let cursor = '';
            for (let i = 0; i < 5; i += 1) {
                const params = new URLSearchParams({ organization_id: String(org), limit: '100' });
                if (cursor) params.set('before_id', cursor);
                const result = await request('/references/employees?' + params);
                employees.push(...(result.items || []));
                if (!result.next_cursor) break;
                cursor = String(result.next_cursor);
                if (i === 4) throw new Error('В справочнике слишком много работников — уточните выбор');
            }
            for (const select of admitForm.querySelectorAll('select')) {
                select.replaceChildren(new Option('Выберите работника', ''));
                for (const employee of employees) {
                    select.add(new Option(employee.name + ' (' + (employee.electrical_group || 'группа не указана') + ')',
                        String(employee.id)));
                }
            }
        };
        const open = async ({ id, action }) => {
            if (state.busy) return;
            state.busy = true;
            alert('Проверяем актуальную карточку…');
            try {
                const document = (await request('/' + id)).document;
                if (action === 'issue' && document.state !== 'draft') throw new Error('Документ уже выдан');
                if (action === 'admit' && (document.state !== 'issued'
                    || document.kind !== 'permit' || document.lifecycle?.first_admission)) {
                    throw new Error('Первый допуск недоступен для этого документа');
                }
                state.document = document;
                window.WorkPermitTime.context(panel, document);
                state.action = action;
                state.command = null;
                issueForm.reset();
                admitForm.reset();
                list.replaceChildren();
                if (action === 'issue') {
                    addMeasure();
                    void loadIssueTemplates(document.organization_id).catch(error => alert(error.message, true));
                    title.textContent = 'Выдача ' + (document.kind === 'permit' ? 'наряда-допуска' : 'распоряжения')
                        + ' №' + document.id;
                } else {
                    title.textContent = 'Первичный допуск по наряду №' + document.id;
                    await fillEmployees(document.organization_id);
                    const crewAdmitter = (document.crew || []).find(item => item.role === 'admitter');
                    const issuer = (document.crew || []).find(item => item.role === 'issuer');
                    const producer = document.lifecycle?.issuance?.producer_employee_id;
                    if (crewAdmitter) admitForm.elements.namedItem('admitted_employee_id').value =
                        String(crewAdmitter.employee_id);
                    if (issuer) admitForm.elements.namedItem('briefed_by_employee_id').value =
                        String(issuer.employee_id);
                    if (producer) admitForm.elements.namedItem('briefing_recipient_id').value =
                        String(producer);
                }
                issueForm.hidden = action !== 'issue';
                admitForm.hidden = action !== 'admit';
                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                alert('');
            } catch (error) {
                alert(error.message, true);
            } finally {
                state.busy = false;
            }
        };
        pageScope.listen(root, 'wp:lifecycle-open', event => void open(event.detail));
        pageScope.listen(panel, 'input', () => { state.command = null; });
        pageScope.listen(panel, 'change', () => { state.command = null; });
        for (const button of panel.querySelectorAll('[data-wp-lifecycle-cancel]')) {
            pageScope.listen(button, 'click', () => {
                panel.hidden = true;
                state.document = null;
                state.command = null;
            });
        }
        pageScope.listen(panel.querySelector('[data-wp-add-measure]'), 'click', addMeasure);
        const submit = async (action) => {
            if (state.busy || !state.document || state.action !== action) return;
            const form = action === 'issue' ? issueForm : admitForm;
            const submitButton = form.querySelector('[type="submit"]');
            state.busy = true;
            submitButton.disabled = true;
            form.setAttribute('aria-busy', 'true');
            try {
                if (!state.command) state.command = command();
                const data = Object.fromEntries(new FormData(form));
                const payload = {
                    version: Number(state.document.version),
                    command_id: state.command,
                };
                if (action === 'issue') {
                    payload.planned_start = window.WorkPermitTime.toUtc(issueForm.elements.namedItem('planned_start'), state.document);
                    payload.planned_finish = window.WorkPermitTime.toUtc(issueForm.elements.namedItem('planned_finish'), state.document);
                    payload.special_instructions = data.special_instructions;
                    payload.work_conditions = data.work_conditions;
                    payload.time_zone = window.WorkPermitTime.zoneFor(state.document);
                    payload.measures = [...list.children].map(row => Object.fromEntries(
                        [...row.querySelectorAll('textarea')].map(field => [field.name, field.value])
                    ));
                } else {
                    payload.started_at = window.WorkPermitTime.toUtc(admitForm.elements.namedItem('started_at'), state.document);
                    payload.admitted_employee_id = data.admitted_employee_id;
                    payload.briefed_by_employee_id = data.briefed_by_employee_id;
                    payload.briefing_recipient_id = data.briefing_recipient_id;
                    payload.workplace = data.workplace;
                    payload.workplace_prepared = data.workplace_prepared === 'on';
                    payload.briefing_confirmed = data.briefing_confirmed === 'on';
                }
                await request('/' + state.document.id + (action === 'issue' ? '/issue' : '/first-admission'), payload);
                panel.hidden = true;
                state.document = null;
                state.command = null;
                alert(action === 'issue' ? 'Выдача записана в журнал.' : 'Первичный допуск записан в журнал.');
                root.dispatchEvent(new CustomEvent('wp:lifecycle-recorded'));
            } catch (error) {
                alert(error.message, true);
            } finally {
                state.busy = false;
                submitButton.disabled = false;
                form.removeAttribute('aria-busy');
            }
        };
        pageScope.listen(issueForm, 'submit', event => {
            event.preventDefault();
            void submit('issue');
        });
        pageScope.listen(admitForm, 'submit', event => {
            event.preventDefault();
            void submit('admit');
        });
    });
});
