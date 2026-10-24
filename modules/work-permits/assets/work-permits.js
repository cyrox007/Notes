window.wspace.page.register('modules/work-permits/assets/work-permits.js', (pageScope) => {
    pageScope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        if (!root) return;

        const api = (root.dataset.baseUrl || '') + '/api/work-permits';
        const form = root.querySelector('[data-wp-form]');
        const feedback = root.querySelector('[data-wp-feedback]');
        const list = root.querySelector('[data-wp-list]');
        const more = root.querySelector('[data-wp-more]');
        const save = root.querySelector('[data-wp-submit]');
        const csrf = root.querySelector('[name="csrf_token"]');
        const state = { cursor: null, busy: false, command: null, sequence: 0 };

        const node = (name, text) => {
            const element = document.createElement(name);
            element.textContent = String(text);
            return element;
        };

        const notify = (message, error = false) => {
            feedback.hidden = !message;
            feedback.textContent = message;
            feedback.dataset.state = error ? 'error' : 'success';
        };

        const request = async (suffix = '/', body) => {
            const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (body !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrf?.value || '';
                options.body = JSON.stringify(body);
            }
            const response = await pageScope.fetch(api + suffix, options);
            const result = await response.json().catch(() => null);
            if (!response.ok || result?.status !== 'ok') {
                throw new Error(result?.message || 'Не удалось обратиться к журналу документов');
            }
            return result;
        };

        const render = (item) => {
            const section = node('article', '');
            section.className = 'wp-journal__row';
            const kind = item.kind === 'permit' ? 'Наряд-допуск' : 'Распоряжение';
            const number = node('strong', kind + ' №' + item.id);
            const facility = node('span', item.facility);
            const state = node('small', 'Черновик · ' + item.created_at);
            const work = node('p', item.work_description);
            section.append(number, facility, state, work);
            return section;
        };

        const load = async (reset = false) => {
            if (state.busy) return;
            const generation = ++state.sequence;
            state.busy = true;
            more.disabled = true;
            list.setAttribute('aria-busy', 'true');
            try {
                const query = new URLSearchParams({ limit: '20' });
                if (!reset && state.cursor) query.set('before_id', String(state.cursor));
                const result = await request('/?' + query);
                if (generation !== state.sequence) return;
                if (reset) list.replaceChildren();
                for (const item of result.items || []) list.appendChild(render(item));
                state.cursor = result.next_cursor || null;
                more.hidden = !state.cursor;
                if (!list.children.length) list.appendChild(node('p', 'За последний месяц черновиков нет.'));
            } catch (error) {
                notify(error instanceof Error ? error.message : 'Ошибка загрузки журнала', true);
            } finally {
                state.busy = false;
                more.disabled = false;
                list.removeAttribute('aria-busy');
            }
        };

        pageScope.listen(more, 'click', () => void load(false));
        pageScope.listen(form, 'input', () => { state.command = null; });
        pageScope.listen(form, 'submit', async (event) => {
            event.preventDefault();
            if (state.busy) return;
            state.busy = true;
            save.disabled = true;
            form.setAttribute('aria-busy', 'true');
            notify('Сохраняем черновик…');
            try {
                if (!state.command) {
                    const bytes = new Uint8Array(16);
                    crypto.getRandomValues(bytes);
                    state.command = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
                }
                const data = Object.fromEntries(new FormData(form));
                const result = await request('/', { ...data, command_id: state.command });
                state.command = null;
                form.reset();
                notify('Черновик №' + result.document.number + ' сохранён.');
                state.busy = false;
                await load(true);
            } catch (error) {
                notify(error instanceof Error ? error.message : 'Не удалось сохранить черновик', true);
            } finally {
                state.busy = false;
                save.disabled = false;
                form.removeAttribute('aria-busy');
            }
        });


        const organization = root.querySelector('[data-wp-organization]');
        const department = root.querySelector('[data-wp-department]');
        const facility = root.querySelector('[data-wp-facility]');
        const admin = root.querySelector('[data-wp-admin]');
        const referenceForm = root.querySelector('[data-wp-reference-form]');
        const referenceList = root.querySelector('[data-wp-reference-list]');
        const referenceMore = root.querySelector('[data-wp-reference-more]');
        const referenceKind = root.querySelector('[data-wp-kind]');
        const adminOrg = root.querySelector('[data-wp-admin-org]');
        const adminDept = root.querySelector('[data-wp-admin-dept]');
        const showArchive = root.querySelector('[data-wp-show-archive]');
        const refState = { cursor: null, editing: false };

        const referenceRows = async (kind, orgId = '', archived = false, before = '') => {
            const query = new URLSearchParams({ limit: '100' });
            if (orgId) query.set('organization_id', orgId);
            if (archived) query.set('archived', '1');
            if (before) query.set('before_id', String(before));
            return request('/references/' + encodeURIComponent(kind) + '?' + query);
        };

        const choices = (select, rows, empty) => {
            const old = select.value;
            select.replaceChildren(new Option(empty, ''));
            for (const row of rows) select.add(new Option(row.name, String(row.id)));
            if ([...select.options].some(option => option.value === old)) select.value = old;
        };

        const loadOrgChoices = async () => {
            const rows = (await referenceRows('organizations')).items || [];
            choices(organization, rows, 'Выберите организацию');
            if (adminOrg) choices(adminOrg, rows, 'Выберите организацию');
            await loadDependentChoices();
        };

        const loadDependentChoices = async () => {
            const orgId = organization.value;
            department.disabled = !orgId;
            facility.disabled = !orgId;
            if (!orgId) {
                choices(department, [], 'Не выбрано');
                choices(facility, [], 'Выберите организацию');
                return;
            }
            const [departments, facilities] = await Promise.all([
                referenceRows('departments', orgId),
                referenceRows('facilities', orgId),
            ]);
            if (organization.value !== orgId) return;
            choices(department, departments.items || [], 'Не выбрано');
            choices(facility, facilities.items || [], 'Выберите объект');
        };

        pageScope.listen(organization, 'change', () => {
            choices(department, [], 'Не выбрано');
            choices(facility, [], 'Загрузка объектов…');
            void loadDependentChoices().catch(error => notify(error.message, true));
        });

        const loadAdminDept = async () => {
            if (!adminDept) return;
            const orgId = adminOrg?.value || '';
            const rows = orgId ? (await referenceRows('departments', orgId)).items : [];
            choices(adminDept, rows || [], 'Без подразделения');
        };

        const adminFields = async () => {
            if (!admin) return;
            const kind = referenceKind.value;
            root.querySelector('[data-wp-parent-field]').hidden = kind === 'organizations';
            root.querySelector('[data-wp-employee-field]').hidden = kind !== 'employees';
            root.querySelector('[data-wp-group-field]').hidden = kind !== 'employees';
            await loadAdminDept();
            await loadReferenceList(true);
        };

        const referenceRow = (item) => {
            const line = document.createElement('div');
            line.className = 'wp-journal__row wp-journal__reference';
            const heading = node('strong', '№' + item.id + ' · версия ' + item.version
                + (Number(item.is_archived) ? ' · архив' : ''));
            const field = document.createElement('input');
            field.value = item.name;
            field.maxLength = 180;
            field.setAttribute('aria-label', 'Название записи №' + item.id);
            const rename = node('button', 'Переименовать');
            rename.type = 'button';
            const toggle = node('button', Number(item.is_archived) ? 'Восстановить' : 'В архив');
            toggle.type = 'button';
            const change = async (action) => {
                if (refState.editing) return;
                refState.editing = true;
                rename.disabled = true;
                toggle.disabled = true;
                try {
                    const payload = { action, version: Number(item.version) };
                    if (action === 'rename') payload.name = field.value;
                    await request('/references/' + referenceKind.value + '/' + item.id + '/change', payload);
                    notify('Справочник обновлён.');
                    await loadReferenceList(true);
                    await loadOrgChoices();
                } catch (error) {
                    notify(error.message, true);
                } finally {
                    refState.editing = false;
                    rename.disabled = false;
                    toggle.disabled = false;
                }
            };
            pageScope.listen(rename, 'click', () => void change('rename'));
            pageScope.listen(toggle, 'click', () => void change(Number(item.is_archived) ? 'restore' : 'archive'));
            line.append(heading, field, rename, toggle);
            return line;
        };

        const loadReferenceList = async (reset = false) => {
            if (!admin) return;
            const kind = referenceKind.value;
            const cursor = reset ? '' : refState.cursor;
            const result = await referenceRows(kind, '', showArchive.checked, cursor);
            if (kind !== referenceKind.value) return;
            if (reset) referenceList.replaceChildren();
            for (const item of result.items || []) referenceList.append(referenceRow(item));
            refState.cursor = result.next_cursor || null;
            referenceMore.hidden = !refState.cursor;
            if (!referenceList.children.length) {
                referenceList.append(node('p', 'Справочник пока пуст.'));
            }
        };

        if (admin) {
            pageScope.listen(referenceKind, 'change', () => void adminFields().catch(e => notify(e.message, true)));
            pageScope.listen(adminOrg, 'change', () => void loadAdminDept().catch(e => notify(e.message, true)));
            pageScope.listen(showArchive, 'change', () => void loadReferenceList(true).catch(e => notify(e.message, true)));
            pageScope.listen(referenceMore, 'click', () => void loadReferenceList(false).catch(e => notify(e.message, true)));
            pageScope.listen(referenceForm, 'submit', async (event) => {
                event.preventDefault();
                if (refState.editing) return;
                refState.editing = true;
                const button = referenceForm.querySelector('[type="submit"]');
                button.disabled = true;
                try {
                    const payload = Object.fromEntries(new FormData(referenceForm));
                    const kind = payload.kind;
                    if (kind === 'organizations') delete payload.organization_id;
                    if (kind !== 'employees') {
                        delete payload.department_id;
                        delete payload.electrical_group;
                    }
                    await request('/references/' + kind, payload);
                    referenceForm.elements.namedItem('name').value = '';
                    notify('Запись добавлена.');
                    await loadReferenceList(true);
                    await loadOrgChoices();
                    if (kind === 'departments') await loadAdminDept();
                } catch (error) {
                    notify(error.message, true);
                } finally {
                    refState.editing = false;
                    button.disabled = false;
                }
            });
        }

        void loadOrgChoices().then(() => {
            if (admin) return adminFields();
        }).catch(error => notify(error.message, true));

        void load(true);
    });
});
