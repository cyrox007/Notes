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

        void load(true);
    });
});
