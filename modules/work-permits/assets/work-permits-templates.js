window.wspace.page.register('modules/work-permits/assets/work-permits-templates.js', (pageScope) => {
    pageScope.onInit(() => {
        const root = document.querySelector('[data-work-permits]');
        if (!root) return;

        const api = (root.dataset.baseUrl || '') + '/api/work-permits';
        const csrf = root.querySelector('[name="csrf_token"]');
        const newForm = root.querySelector('[data-wp-form]');
        const editForm = root.querySelector('[data-wp-edit-form]');
        const editPanel = root.querySelector('[data-wp-editor]');
        const editOrg = root.querySelector('[data-wp-edit-org]');
        const notify = (message, error = false) => {
            const element = root.querySelector('[data-wp-feedback]');
            element.hidden = !message;
            element.textContent = message;
            element.dataset.state = error ? 'error' : 'success';
        };

        const request = async (path, payload) => {
            const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
            if (payload !== undefined) {
                options.method = 'POST';
                options.headers['Content-Type'] = 'application/json';
                options.headers['X-CSRF-Token'] = csrf?.value || '';
                options.body = JSON.stringify(payload);
            }
            const response = await pageScope.fetch(api + path, options);
            const data = await response.json().catch(() => null);
            if (!response.ok || data?.status !== 'ok') {
                throw new Error(data?.message || 'Не удалось выполнить операцию с шаблоном');
            }
            return data;
        };

        const collect = async (route, limit = 5) => {
            let cursor = '';
            const items = [];
            for (let page = 0; page < limit; page += 1) {
                const query = new URLSearchParams({ limit: '100' });
                if (cursor) query.set('before_id', String(cursor));
                const result = await request(route + (route.includes('?') ? '&' : '?') + query);
                items.push(...(result.items || []));
                if (!result.next_cursor) return items;
                cursor = result.next_cursor;
            }
            throw new Error('Найдено больше 500 записей. Уточните состав справочника.');
        };

        const templates = (kind, org, archived = false, cursor = '') => {
            const query = new URLSearchParams({ kind, organization_id: String(org), limit: '100' });
            if (archived) query.set('archived', '1');
            if (cursor) query.set('before_id', String(cursor));
            return request('/templates?' + query);
        };

        const putChoices = (select, items, placeholder) => {
            const current = select.value;
            select.replaceChildren(new Option(placeholder, ''));
            for (const item of items) select.add(new Option(item.name, String(item.id)));
            if ([...select.options].some(option => option.value === current)) select.value = current;
        };

        const pickerSequence = new WeakMap();
        const refreshPicker = async (container, org) => {
            if (!container) return;
            const sequence = (pickerSequence.get(container) || 0) + 1;
            pickerSequence.set(container, sequence);
            const choice = container.querySelector('select');
            choice.disabled = !org;
            putChoices(choice, [], org ? 'Загрузка шаблонов…' : 'Выберите организацию');
            if (!org) return;
            const kind = container.hasAttribute('data-wp-crew-picker') ? 'crew' : 'job';
            const result = await templates(kind, org);
            if (pickerSequence.get(container) !== sequence) return;
            putChoices(choice, result.items || [], 'Без шаблона');
        };

        const pickers = [...root.querySelectorAll('[data-wp-job-picker], [data-wp-crew-picker]')];
        const refreshFor = async (form) => {
            const org = form.elements.namedItem('organization_id')?.value || '';
            for (const picker of pickers.filter(x => x.closest('form') === form)) {
                await refreshPicker(picker, org);
            }
        };

        const detail = async (id, org) => {
            const query = new URLSearchParams({ organization_id: String(org) });
            return (await request('/templates/' + id + '?' + query)).template;
        };

        for (const picker of pickers) {
            const form = picker.closest('form');
            const select = picker.querySelector('select');
            const orgSelect = form.elements.namedItem('organization_id');
            if (orgSelect) {
                pageScope.listen(orgSelect, 'change', () => {
                    void refreshFor(form).catch(error => notify(error.message, true));
                });
            }
            const button = picker.querySelector('button');
            pageScope.listen(button, 'click', async () => {
                const org = orgSelect?.value;
                const templateId = select.value;
                if (!org || !templateId) return;
                button.disabled = true;
                try {
                    const item = await detail(templateId, org);
                    if (item.kind === 'job') {
                        const field = form.elements.namedItem('work_description');
                        field.value = item.work_description;
                        field.dispatchEvent(new Event('input', { bubbles: true }));
                        notify('Задание подставлено из шаблона. Измените текст при необходимости.');
                    } else {
                        if (editPanel.hidden) throw new Error('Сначала откройте черновик');
                        const roles = item.roles || {};
                        const members = (item.member_ids || []).map(String);
                        const selectors = ['issuer','responsible_manager','admitter','producer','observer']
                            .map(role => [role, editForm.elements.namedItem('role_' + role)]);
                        const known = (select, value) => !value
                            || [...select.options].some(option => option.value === String(value));
                        if (selectors.some(([role, element]) => !known(element, roles[role]))
                            || members.some(id => !known(editForm.elements.namedItem('member_ids'), id))) {
                            throw new Error('Некоторые сотрудники больше не доступны в справочнике');
                        }
                        for (const [role, element] of selectors) element.value = String(roles[role] || '');
                        for (const option of editForm.elements.namedItem('member_ids').options) {
                            option.selected = members.includes(option.value);
                        }
                        editForm.dispatchEvent(new Event('input', { bubbles: true }));
                        notify('Состав бригады подставлен. Его можно изменить перед сохранением.');
                    }
                } catch (error) {
                    notify(error.message, true);
                } finally {
                    button.disabled = false;
                }
            });
        }

        pageScope.listen(root, 'wp:draft-opened', () => {
            void refreshFor(editForm).catch(error => notify(error.message, true));
        });

        const admin = root.querySelector('[data-wp-template-admin]');
        if (!admin) {
            void refreshFor(newForm).catch(error => notify(error.message, true));
            return;
        }

        const form = admin.querySelector('[data-wp-template-form]');
        const adminOrg = admin.querySelector('[data-wp-template-org]');
        const kind = admin.querySelector('[data-wp-template-kind]');
        const archived = admin.querySelector('[data-wp-template-archived]');
        const listing = admin.querySelector('[data-wp-template-list]');
        const more = admin.querySelector('[data-wp-template-more]');
        const descriptionField = admin.querySelector('[data-wp-template-description]');
        const crewHint = admin.querySelector('[data-wp-template-crew-hint]');
        let cursor = null;
        let editing = false;
        let generation = 0;

        const showKind = () => {
            const crew = kind.value === 'crew';
            descriptionField.hidden = crew;
            crewHint.hidden = !crew;
        };

        const bodyFor = () => {
            const data = Object.fromEntries(new FormData(form));
            if (data.kind === 'crew') {
                if (editPanel.hidden || editOrg.value !== data.organization_id) {
                    throw new Error('Откройте черновик той же организации и заполните бригаду');
                }
                const roles = {};
                for (const role of ['issuer','responsible_manager','admitter','producer','observer']) {
                    roles[role] = editForm.elements.namedItem('role_' + role)?.value || '';
                }
                data.roles = roles;
                data.member_ids = [...editForm.elements.namedItem('member_ids').selectedOptions]
                    .map(option => option.value);
                delete data.work_description;
            }
            return data;
        };

        const rowView = (item) => {
            const row = document.createElement('article');
            row.className = 'wp-journal__row wp-journal__template-row';
            const heading = document.createElement('strong');
            heading.textContent = '№' + item.id + ' · версия ' + item.version
                + (Number(item.is_archived) ? ' · архив' : '');
            const input = document.createElement('input');
            input.value = item.name;
            input.maxLength = 160;
            input.setAttribute('aria-label', 'Название шаблона №' + item.id);
            const actions = document.createElement('div');
            actions.className = 'wp-journal__actions';

            const act = (label, action) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.textContent = label;
                pageScope.listen(button, 'click', async () => {
                    if (editing) return;
                    editing = true;
                    button.disabled = true;
                    try {
                        const payload = { version: Number(item.version), action };
                        if (action === 'rename') payload.name = input.value;
                        if (action === 'replace') {
                            const data = bodyFor();
                            Object.assign(payload, data);
                        }
                        await request('/templates/' + item.id + '/change', payload);
                        notify('Шаблон обновлён.');
                        await loadAdmin(true);
                        await refreshFor(newForm);
                        if (!editPanel.hidden) await refreshFor(editForm);
                    } catch (error) {
                        notify(error.message, true);
                    } finally {
                        editing = false;
                        button.disabled = false;
                    }
                });
                actions.append(button);
            };
            act('Переименовать', 'rename');
            if (!Number(item.is_archived)) {
                act('Перезаписать из формы', 'replace');
            }
            act(Number(item.is_archived) ? 'Восстановить' : 'В архив',
                Number(item.is_archived) ? 'restore' : 'archive');
            row.append(heading, input, actions);
            return row;
        };

        const loadAdmin = async (reset = false) => {
            const org = adminOrg.value;
            const current = ++generation;
            if (reset) {
                cursor = null;
                listing.replaceChildren();
                more.hidden = true;
            }
            if (!org) {
                const empty = document.createElement('p');
                empty.textContent = 'Выберите организацию для управления шаблонами.';
                listing.replaceChildren(empty);
                return;
            }
            const result = await templates(kind.value, org, archived.checked, cursor || '');
            if (current !== generation) return;
            for (const item of result.items || []) listing.append(rowView(item));
            cursor = result.next_cursor || null;
            more.hidden = !cursor;
            if (!listing.children.length) {
                const empty = document.createElement('p');
                empty.textContent = 'Шаблонов пока нет.';
                listing.append(empty);
            }
        };

        pageScope.listen(kind, 'change', () => {
            showKind();
            void loadAdmin(true).catch(error => notify(error.message, true));
        });
        pageScope.listen(adminOrg, 'change', () => {
            void loadAdmin(true).catch(error => notify(error.message, true));
        });
        pageScope.listen(archived, 'change', () => {
            void loadAdmin(true).catch(error => notify(error.message, true));
        });
        pageScope.listen(more, 'click', () => {
            void loadAdmin(false).catch(error => notify(error.message, true));
        });
        pageScope.listen(admin.querySelector('[data-wp-copy-job]'), 'click', () => {
            const source = !editPanel.hidden && editOrg.value === adminOrg.value
                ? editForm : newForm;
            const text = source.elements.namedItem('work_description')?.value || '';
            form.elements.namedItem('work_description').value = text;
            notify(text ? 'Текст скопирован в новый шаблон.' : 'В открытой форме нет задания.', !text);
        });
        pageScope.listen(form, 'submit', async event => {
            event.preventDefault();
            if (editing) return;
            editing = true;
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            try {
                await request('/templates', bodyFor());
                form.elements.namedItem('name').value = '';
                notify('Шаблон сохранён.');
                await loadAdmin(true);
                await refreshFor(newForm);
                if (!editPanel.hidden) await refreshFor(editForm);
            } catch (error) {
                notify(error.message, true);
            } finally {
                editing = false;
                button.disabled = false;
            }
        });

        showKind();
        void collect('/references/organizations').then(items => {
            putChoices(adminOrg, items, 'Выберите организацию');
            return loadAdmin(true);
        }).catch(error => notify(error.message, true));
        void refreshFor(newForm).catch(error => notify(error.message, true));
    });
});
