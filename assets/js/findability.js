(function bootstrapFindability() {
    document.addEventListener('DOMContentLoaded', () => {
        const body = document.body;
        if (!body.dataset.listTotalPages) return;

        const state = {
            q: body.dataset.listQ || '',
            page: Math.max(1, Number.parseInt(body.dataset.listPage || '1', 10) || 1),
            limit: Number.parseInt(body.dataset.listLimit || '20', 10) || 20,
            total: Math.max(0, Number.parseInt(body.dataset.listTotal || '0', 10) || 0),
            totalPages: Math.max(1, Number.parseInt(body.dataset.listTotalPages || '1', 10) || 1),
            sort: body.dataset.listSort || '',
            direction: body.dataset.listDirection || 'desc',
            filter: body.dataset.listFilter || ''
        };

        const path = window.location.pathname;
        const appPath = window.wspace?.basePath || '';
        const relative = appPath && path.startsWith(appPath) ? path.slice(appPath.length) || '/' : path;
        const kind = relative.startsWith('/notes') ? 'notes'
            : relative.startsWith('/tasks') ? 'tasks'
            : '';
        if (!kind) return;

        function urlWith(overrides = {}) {
            const url = new URL(window.location.href);
            Object.entries(overrides).forEach(([key, value]) => {
                if (value === '' || value === null || value === undefined) url.searchParams.delete(key);
                else url.searchParams.set(key, String(value));
            });
            return `${url.pathname}${url.search}`;
        }

        const form = document.createElement('form');
        form.method = 'get';
        form.action = path;
        form.className = 'list-findability';
        form.setAttribute('role', 'search');

        const searchLabel = document.createElement('label');
        searchLabel.className = 'list-findability__search';
        const searchText = document.createElement('span');
        searchText.textContent = 'Поиск';
        const input = document.createElement('input');
        input.type = 'search';
        input.name = 'q';
        input.value = state.q;
        input.maxLength = 100;
        input.placeholder = kind === 'admin' ? 'Имя, логин или email' : kind === 'tasks' ? 'Название или описание' : 'Название заметки';
        searchLabel.append(searchText, input);
        form.appendChild(searchLabel);

        const limitLabel = document.createElement('label');
        limitLabel.className = 'list-findability__limit';
        const limitText = document.createElement('span');
        limitText.textContent = 'На странице';
        const limit = document.createElement('select');
        limit.name = 'limit';
        [10, 20, 50].forEach((value) => {
            const option = document.createElement('option');
            option.value = String(value);
            option.textContent = String(value);
            option.selected = value === state.limit;
            limit.appendChild(option);
        });
        limitLabel.append(limitText, limit);
        form.appendChild(limitLabel);

        for (const [name, value] of [['sort', state.sort], ['direction', state.direction]]) {
            for (const [name, value] of [['sort', state.sort], ['direction', state.direction]]) {
                if (!value) continue;
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = name;
                hidden.value = value;
                form.appendChild(hidden);
            }
        }

        if (state.filter) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'filter';
            hidden.value = state.filter;
            form.appendChild(hidden);
        }
        const pageReset = document.createElement('input');
        pageReset.type = 'hidden';
        pageReset.name = 'page';
        pageReset.value = '1';
        form.appendChild(pageReset);

        const submit = document.createElement('button');
        submit.type = 'submit';
        submit.textContent = 'Найти';
        submit.className = 'list-findability__submit';
        form.appendChild(submit);

        if (state.q) {
            const clear = document.createElement('a');
            clear.className = 'list-findability__clear';
            clear.href = urlWith({ q: '', page: 1 });
            clear.textContent = 'Сбросить';
            form.appendChild(clear);
        }

        const host = kind === 'notes'
            ? document.querySelector('.notes__content')
            : document.querySelector('.tasks__controls');
        const slot = document.querySelector('[data-findability-slot]');
        if (slot) slot.append(form);
        else if (host) host.before(form);

        // Keep legacy filter/sort controls while preserving the new query state.
        document.querySelectorAll('.tasks__filters a').forEach((link) => {
            const url = new URL(link.href, window.location.href);
            if (state.q) url.searchParams.set('q', state.q);
            url.searchParams.set('limit', String(state.limit));
            if (state.sort) url.searchParams.set('sort', state.sort);
            if (state.direction) url.searchParams.set('direction', state.direction);
            url.searchParams.set('page', '1');
            link.href = `${url.pathname}${url.search}`;
        });
        const taskSort = document.querySelector('.tasks__sort-form');
        if (taskSort) {
            [['q', state.q], ['limit', state.limit]].forEach(([name, value]) => {
                let hidden = taskSort.querySelector(`input[name="${name}"]`);
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = name;
                    taskSort.appendChild(hidden);
                }
                hidden.value = String(value);
            });
        }
        document.querySelectorAll('.notes__list_head a').forEach((link) => {
            const url = new URL(link.href, window.location.href);
            if (state.q) url.searchParams.set('q', state.q);
            url.searchParams.set('limit', String(state.limit));
            url.searchParams.set('page', '1');
            link.href = `${url.pathname}${url.search}`;
        });

        const pagination = document.createElement('nav');
        pagination.className = 'list-pagination';
        pagination.setAttribute('aria-label', 'Пагинация');
        const summary = document.createElement('span');
        summary.textContent = `Страница ${state.page} из ${state.totalPages} · найдено ${state.total}`;
        const prev = document.createElement('a');
        prev.textContent = '← Назад';
        prev.href = urlWith({ page: Math.max(1, state.page - 1) });
        if (state.page <= 1) prev.setAttribute('aria-disabled', 'true');
        const next = document.createElement('a');
        next.textContent = 'Вперёд →';
        next.href = urlWith({ page: Math.min(state.totalPages, state.page + 1) });
        if (state.page >= state.totalPages) next.setAttribute('aria-disabled', 'true');
        pagination.append(prev, summary, next);

        const tail = kind === 'notes'
            ? document.querySelector('.notes__content')
            : document.querySelector('.tasks__list');
        if (tail) tail.after(pagination);
    });
})();
