window.wspace.page.register('modules/work-permits/assets/work-permits-amendments.js', (pageScope) => {
    pageScope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        const panel = root?.querySelector('[data-wp-amendments]');
        if (!panel) return;

        const api = (root.dataset.baseUrl || '') + '/api/work-permits';
        const csrf = root.querySelector('[name="csrf_token"]');
        const feedbackNode = root.querySelector('[data-wp-feedback]');
        const crewForm = panel.querySelector('[data-wp-crew-change]');
        const extensionForm = panel.querySelector('[data-wp-extension]');
        const history = panel.querySelector('[data-wp-amendments-history]');
        const memberSelect = panel.querySelector('[data-wp-amendment-members]');
        const issuerSelect = panel.querySelector('[data-wp-extension-issuer]');
        const state = { document: null, command: null, busy: false, sequence: 0 };

        const feedback = (message, error = false) => {
            feedbackNode.hidden = !message;
            feedbackNode.textContent = message;
            feedbackNode.dataset.state = error ? 'error' : 'success';
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
                throw new Error(result?.message || 'Не удалось изменить наряд');
            }
            return result;
        };
        const command = () => {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            return [...bytes].map(byte => byte.toString(16).padStart(2, '0')).join('');
        };
        const utc = (value) => {
            if (!value) throw new Error('Укажите дату и время');
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) throw new Error('Указана неверная дата');
            return date.toISOString().slice(0, 19).replace('T', ' ');
        };
        const text = (parent, content) => {
            const paragraph = document.createElement('p');
            paragraph.textContent = content;
            parent.append(paragraph);
        };
        const allEmployees = async org => {
            const employees = [];
            let cursor = '';
            for (let page = 0; page < 5; page += 1) {
                const params = new URLSearchParams({ limit: '100', organization_id: String(org) });
                if (cursor) params.set('before_id', cursor);
                const result = await request('/references/employees?' + params);
                employees.push(...(result.items || []));
                if (!result.next_cursor) return employees;
                cursor = String(result.next_cursor);
            }
            throw new Error('Справочник работников превышает 500 записей — уточните выбор');
        };
        const render = async doc => {
            const records = doc.lifecycle || {};
            history.replaceChildren();
            const issuance = records.issuance;
            text(history, 'Текущий срок: ' + issuance.planned_start + ' — ' + issuance.planned_finish
                + ' UTC · выдавший: ' + issuance.issuer_name);
            for (const entry of records.extensions || []) {
                text(history, 'Продление: ' + entry.previous_finish + ' → ' + entry.new_finish
                    + ' UTC; ' + entry.reason + '; выдал: ' + entry.issuer_name);
            }
            for (const entry of records.crew_revisions || []) {
                const members = (entry.members || []).filter(person => person.role === 'member');
                text(history, 'Изменение бригады: ' + entry.changed_at + ' UTC; ' + entry.reason
                    + ' · члены: ' + members.map(person => person.employee_name).join(', '));
            }
            const periods = records.periods || [];
            const last = periods[periods.length - 1];
            const possible = !last || !!last.ended_at;
            crewForm.hidden = !possible;
            extensionForm.hidden = !possible;
            if (!possible) {
                text(history, 'Сначала зафиксируйте перерыв или окончание рабочего дня. Пока работы идут, изменение состава и срока заблокировано.');
                return;
            }
            const employees = await allEmployees(doc.organization_id);
            memberSelect.replaceChildren();
            const selected = new Set((doc.crew || []).filter(x => x.role === 'member')
                .map(person => String(person.employee_id)));
            for (const person of employees) {
                const option = new Option(person.name + ' (' + (person.electrical_group || 'группа не указана') + ')',
                    String(person.id));
                option.selected = selected.has(String(person.id));
                memberSelect.add(option);
                selected.delete(String(person.id));
            }
            for (const missing of selected) {
                const old = (doc.crew || []).find(x => x.role === 'member'
                    && String(x.employee_id) === missing);
                const option = new Option((old?.employee_name || 'Работник') + ' (в архиве, замените)',
                    missing);
                option.selected = true;
                memberSelect.add(option);
            }
            issuerSelect.replaceChildren(new Option('Выберите работника, выдавшего наряд', ''));
            for (const person of employees) {
                issuerSelect.add(new Option(person.name, String(person.id)));
            }
            issuerSelect.value = String(issuance.issuer_employee_id);
            if (issuerSelect.value !== String(issuance.issuer_employee_id)) {
                text(history, 'Работник, выдавший наряд, отсутствует в действующем справочнике. Продление пока недоступно.');
            }
        };
        const open = async id => {
            if (state.busy) return;
            state.busy = true;
            const sequence = ++state.sequence;
            feedback('Загружается актуальная версия наряда…');
            try {
                const doc = (await request('/' + id)).document;
                if (doc.kind !== 'permit' || doc.state !== 'issued') {
                    throw new Error('Изменения доступны только для выданного наряда');
                }
                if (sequence !== state.sequence) return;
                state.document = doc;
                state.command = null;
                crewForm.reset();
                extensionForm.reset();
                await render(doc);
                if (sequence !== state.sequence) return;
                panel.querySelector('[data-wp-amendments-title]').textContent =
                    'Состав и продление наряда №' + doc.id + ' · версия ' + doc.version;
                panel.hidden = false;
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
                feedback('');
            } catch (error) {
                feedback(error.message, true);
            } finally {
                state.busy = false;
            }
        };
        pageScope.listen(root, 'wp:amendments-open', event => void open(event.detail.id));
        pageScope.listen(panel.querySelector('[data-wp-amendments-close]'), 'click', () => {
            state.sequence += 1;
            panel.hidden = true;
            state.document = null;
            state.command = null;
        });
        pageScope.listen(panel, 'input', () => { state.command = null; });
        pageScope.listen(panel, 'change', () => { state.command = null; });
        const submit = async kind => {
            if (state.busy || !state.document) return;
            const form = kind === 'crew' ? crewForm : extensionForm;
            const button = form.querySelector('[type="submit"]');
            state.busy = true;
            button.disabled = true;
            form.setAttribute('aria-busy', 'true');
            try {
                if (!state.command) state.command = command();
                const input = new FormData(form);
                const payload = {
                    command_id: state.command,
                    version: Number(state.document.version),
                    reason: input.get('reason'),
                };
                if (kind === 'crew') {
                    payload.changed_at = utc(input.get('changed_at'));
                    payload.member_ids = input.getAll('member_ids');
                } else {
                    payload.extended_at = utc(input.get('extended_at'));
                    payload.new_finish = utc(input.get('new_finish'));
                    payload.issuer_employee_id = input.get('issuer_employee_id');
                }
                const id = Number(state.document.id);
                await request('/' + id + (kind === 'crew' ? '/change-crew' : '/extend'), payload);
                state.command = null;
                state.document = null;
                state.busy = false;
                root.dispatchEvent(new CustomEvent('wp:lifecycle-recorded'));
                await open(id);
                feedback('Изменение зарегистрировано в истории наряда.');
            } catch (error) {
                feedback(error.message, true);
            } finally {
                state.busy = false;
                button.disabled = false;
                form.removeAttribute('aria-busy');
            }
        };
        pageScope.listen(crewForm, 'submit', event => {
            event.preventDefault();
            void submit('crew');
        });
        pageScope.listen(extensionForm, 'submit', event => {
            event.preventDefault();
            void submit('extension');
        });
    });
});
