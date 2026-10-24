window.wspace.page.register('modules/work-permits/assets/work-permits-closure.js', (pageScope) => {
    pageScope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        const panel = root?.querySelector('[data-wp-closure]');
        if (!panel) return;

        const base = (root.dataset.baseUrl || '') + '/api/work-permits';
        const csrf = root.querySelector('[name="csrf_token"]');
        const feedbackBox = root.querySelector('[data-wp-feedback]');
        const completeForm = panel.querySelector('[data-wp-complete-form]');
        const closeForm = panel.querySelector('[data-wp-final-close-form]');
        const history = panel.querySelector('[data-wp-closure-history]');
        const state = { document: null, command: null, action: null, busy: false, sequence: 0 };

        const feedback = (message, error = false) => {
            feedbackBox.hidden = !message;
            feedbackBox.textContent = message;
            feedbackBox.dataset.state = error ? 'error' : 'success';
        };
        const request = async (path, data) => {
            const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (data !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrf?.value || '';
                options.body = JSON.stringify(data);
            }
            const response = await pageScope.fetch(base + path, options);
            const result = await response.json().catch(() => null);
            if (!response.ok || result?.status !== 'ok') {
                throw new Error(result?.message || 'Не удалось изменить документ');
            }
            return result;
        };
        const identifier = () => {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        };
        const notice = text => {
            const line = document.createElement('p');
            line.textContent = text;
            history.append(line);
        };
        const staff = async organization => {
            let cursor = '';
            const list = [];
            for (let page = 0; page < 5; page += 1) {
                const query = new URLSearchParams({ organization_id: String(organization), limit: '100' });
                if (cursor) query.set('before_id', cursor);
                const result = await request('/references/employees?' + query);
                list.push(...(result.items || []));
                if (!result.next_cursor) return list;
                cursor = String(result.next_cursor);
            }
            throw new Error('Справочник больше 500 работников — уточните выбор');
        };
        const choose = (select, people, selected) => {
            select.replaceChildren(new Option('Выберите работника', ''));
            for (const person of people) {
                select.add(new Option(person.name + ' (' + (person.electrical_group || 'без группы') + ')',
                    String(person.id)));
            }
            select.value = String(selected || '');
        };
        const open = async id => {
            if (state.busy) return;
            state.busy = true;
            const sequence = ++state.sequence;
            feedback('Загружаем состояние документа…');
            try {
                const document = (await request('/' + id)).document;
                if (document.state !== 'issued') {
                    throw new Error('Закрытие доступно только для выданного документа');
                }
                if (sequence !== state.sequence) return;
                state.document = document;
                window.WorkPermitTime.context(panel, document);
                state.command = null;
                completeForm.reset();
                closeForm.reset();
                history.replaceChildren();
                const lifecycle = document.lifecycle || {};
                if (lifecycle.completion) {
                    notice('Работы завершены: ' + window.WorkPermitTime.display(lifecycle.completion.completed_at, document)
                        + ' · производитель: ' + lifecycle.completion.producer_name);
                }
                if (lifecycle.closure) {
                    notice('Документ закрыт: ' + window.WorkPermitTime.display(lifecycle.closure.closed_at, document));
                }
                const action = document.kind === 'order' ? 'close_order'
                    : lifecycle.completion ? 'close_permit' : 'complete';
                state.action = action;
                const people = await staff(document.organization_id);
                if (sequence !== state.sequence) return;
                choose(completeForm.elements.namedItem('producer_employee_id'), people,
                    lifecycle.issuance?.producer_employee_id);
                choose(closeForm.elements.namedItem('closer_employee_id'), people,
                    document.kind === 'order' ? lifecycle.issuance?.producer_employee_id
                        : lifecycle.issuance?.issuer_employee_id);
                completeForm.hidden = action !== 'complete';
                closeForm.hidden = action === 'complete';
                panel.querySelector('[data-wp-final-close-label]').textContent =
                    action === 'close_order' ? 'Закрыть распоряжение' : 'Закрыть наряд-допуск';
                panel.querySelector('[data-wp-closure-title]').textContent =
                    (document.kind === 'order' ? 'Распоряжение №' : 'Наряд №')
                    + document.id + ' · версия ' + document.version;
                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                feedback('');
            } catch (error) {
                feedback(error.message, true);
            } finally {
                state.busy = false;
            }
        };
        pageScope.listen(root, 'wp:closure-open', event => void open(event.detail.id));
        pageScope.listen(panel.querySelector('[data-wp-closure-cancel]'), 'click', () => {
            panel.hidden = true;
            state.sequence += 1;
            state.document = null;
            state.command = null;
        });
        pageScope.listen(panel, 'input', () => { state.command = null; });
        pageScope.listen(panel, 'change', () => { state.command = null; });

        const submit = async action => {
            if (state.busy || !state.document || state.action !== action) return;
            state.busy = true;
            const form = action === 'complete' ? completeForm : closeForm;
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            form.setAttribute('aria-busy', 'true');
            try {
                if (!state.command) state.command = identifier();
                const values = Object.fromEntries(new FormData(form));
                const body = {
                    version: Number(state.document.version), command_id: state.command,
                    note: values.note,
                };
                if (action === 'complete') {
                    body.completed_at = window.WorkPermitTime.toUtc(completeForm.elements.namedItem('completed_at'), state.document);
                    body.producer_employee_id = values.producer_employee_id;
                    body.workplace_safe = values.workplace_safe === 'on';
                    body.crew_withdrawn = values.crew_withdrawn === 'on';
                } else {
                    body.closed_at = window.WorkPermitTime.toUtc(closeForm.elements.namedItem('closed_at'), state.document);
                    body.closer_employee_id = values.closer_employee_id;
                    body.workplace_accepted = values.workplace_accepted === 'on';
                    body.documents_handed_over = values.documents_handed_over === 'on';
                }
                const id = Number(state.document.id);
                const suffix = action === 'complete' ? 'complete'
                    : action === 'close_order' ? 'close-order' : 'close-permit';
                await request('/' + id + '/' + suffix, body);
                state.command = null;
                state.document = null;
                state.busy = false;
                root.dispatchEvent(new CustomEvent('wp:lifecycle-recorded'));
                if (action !== 'complete') {
                    panel.hidden = true;
                    feedback('Документ окончательно закрыт. Он доступен в списке закрытых.');
                } else {
                    await open(id);
                    feedback('Окончание работ зарегистрировано. Теперь можно закрыть наряд.');
                }
            } catch (error) {
                feedback(error.message, true);
            } finally {
                state.busy = false;
                button.disabled = false;
                form.removeAttribute('aria-busy');
            }
        };
        pageScope.listen(completeForm, 'submit', event => {
            event.preventDefault();
            void submit('complete');
        });
        pageScope.listen(closeForm, 'submit', event => {
            event.preventDefault();
            void submit(state.action);
        });
    });
});
