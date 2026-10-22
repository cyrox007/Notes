(() => {
    'use strict';

    const page = document.querySelector('[data-tasks-api-keys]');
    if (!page) return;

    const form = page.querySelector('[data-token-create]');
    const status = page.querySelector('[data-token-status]');
    const list = page.querySelector('[data-token-list]');
    const secretPanel = page.querySelector('[data-token-secret]');
    const secretValue = page.querySelector('[data-token-secret-value]');
    const refresh = page.querySelector('[data-token-refresh]');
    const copy = page.querySelector('[data-token-copy]');
    const hide = page.querySelector('[data-token-hide]');
    const auditStatus = page.querySelector('[data-audit-status]');
    const auditList = page.querySelector('[data-audit-list]');
    const auditRefresh = page.querySelector('[data-audit-refresh]');
    const csrf = form.querySelector('[name="csrf_token"]');
    const scopeNames = {
        'tasks.read': 'Только просмотр',
        'tasks.write': 'Просмотр и изменение'
    };
    let busy = false;

    function setStatus(message, error = false) {
        status.textContent = message;
        status.classList.toggle('tasks-keys__error', error);
    }

    function clearSecret() {
        secretValue.value = '';
        secretPanel.hidden = true;
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            ...options
        });
        const type = response.headers.get('content-type') || '';
        if (!type.includes('application/json')) {
            throw new Error('Сессия завершилась или сервер вернул неожиданный ответ. Обновите страницу.');
        }
        const body = await response.json();
        if (!response.ok) {
            throw new Error(body.message || body.error || 'Операция не выполнена');
        }
        return body;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(value.replace(' ', 'T') + 'Z');
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString('ru-RU');
    }

    function makeElement(tag, className, value) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (value !== undefined) node.textContent = value;
        return node;
    }

    function showItems(items) {
        list.replaceChildren();
        if (!Array.isArray(items) || items.length === 0) {
            list.append(makeElement('p', 'tasks-keys__hint', 'Подключений пока нет.'));
            return;
        }

        const active = [];
        const history = [];
        for (const item of items) {
            const revoked = item.revoked_at !== null && item.revoked_at !== undefined;
            const expiry = Date.parse(String(item.expires_at || '').replace(' ', 'T') + 'Z');
            (revoked || (Number.isFinite(expiry) && expiry <= Date.now()) ? history : active).push(item);
        }
        for (const [heading, collection] of [['Действующие', active], ['История', history]]) {
            if (collection.length === 0) continue;
            list.append(makeElement('h3', 'tasks-keys__group-heading', heading));
            for (const item of collection) {
            const card = makeElement('article', 'tasks-keys__item');
            const heading = makeElement('div', 'tasks-keys__item-heading');
            heading.append(makeElement('strong', '', String(item.name || 'Без названия')));
            const revoked = item.revoked_at !== null && item.revoked_at !== undefined;
            const expires = Date.parse(String(item.expires_at || '').replace(' ', 'T') + 'Z');
            const expired = !revoked && Number.isFinite(expires) && expires <= Date.now();
            const label = revoked ? 'Отозван' : expired ? 'Истёк' : 'Действует';
            heading.append(makeElement('span', revoked || expired ? 'tasks-keys__muted' : 'tasks-keys__active', label));
            card.append(heading);
            card.append(makeElement('p', 'tasks-keys__hint', (scopeNames[item.scope] || 'Неизвестные права') +
                ' · До ' + formatDate(item.expires_at)));
            if (!revoked && !expired) {
                const button = makeElement('button', 'btn-secondary', 'Отозвать');
                button.type = 'button';
                button.addEventListener('click', () => revokeKey(item.id, button));
                card.append(button);
            }
            list.append(card);
            }
        }
    }

    async function loadKeys() {
        if (busy) return;
        busy = true;
        refresh.disabled = true;
        setStatus('Обновляем список…');
        try {
            const data = await request(page.dataset.listUrl);
            showItems(data.items);
            setStatus('Список подключений обновлён');
        } catch (error) {
            setStatus(error.message, true);
        } finally {
            refresh.disabled = false;
            busy = false;
        }
    }

    async function revokeKey(id, button) {
        if (!Number.isSafeInteger(Number(id)) || Number(id) < 1 || busy) return;
        if (!window.confirm('Отозвать этот ключ? Подключение сразу потеряет доступ.')) return;
        busy = true;
        button.disabled = true;
        setStatus('Отзываем ключ…');
        const params = new URLSearchParams({ csrf_token: csrf.value });
        try {
            await request(page.dataset.revokeRoot + '/' + encodeURIComponent(String(id)) + '/revoke', {
                method: 'POST',
                body: params
            });
            setStatus('Ключ отозван');
        } catch (error) {
            setStatus(error.message, true);
        } finally {
            busy = false;
            button.disabled = false;
            await loadKeys();
        }
    }


    const actionLabels = {
        read: 'Просмотр задач',
        create: 'Создание задачи',
        update: 'Изменение задачи'
    };

    function showAudit(entries) {
        auditList.replaceChildren();
        if (!Array.isArray(entries) || entries.length === 0) {
            auditList.append(makeElement('p', 'tasks-keys__hint', 'Обращений пока нет.'));
            return;
        }
        for (const entry of entries) {
            const item = makeElement('article', 'tasks-keys__item');
            const heading = makeElement('div', 'tasks-keys__item-heading');
            heading.append(makeElement('strong', '', String(entry.token_name || 'Подключение')));
            const statusCode = Number(entry.status_code);
            heading.append(makeElement('span',
                statusCode >= 400 ? 'tasks-keys__muted' : 'tasks-keys__active',
                Number.isInteger(statusCode) ? String(statusCode) : '—'));
            item.append(heading);
            item.append(makeElement('p', 'tasks-keys__hint',
                (actionLabels[entry.action] || 'Неизвестная операция') +
                ' · ' + formatDate(entry.created_at)));
            auditList.append(item);
        }
    }

    async function loadAudit() {
        if (auditRefresh.disabled) return;
        auditRefresh.disabled = true;
        auditStatus.textContent = 'Обновляем историю…';
        auditStatus.classList.toggle('tasks-keys__error', false);
        try {
            const result = await request(page.dataset.historyUrl);
            showAudit(result.items);
            auditStatus.textContent = 'История обновлена';
        } catch (error) {
            auditStatus.textContent = error.message;
            auditStatus.classList.toggle('tasks-keys__error', true);
        } finally {
            auditRefresh.disabled = false;
        }
    }

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy) return;
        busy = true;
        const button = form.querySelector('[type="submit"]');
        button.disabled = true;
        clearSecret();
        setStatus('Создаём ключ…');
        try {
            const result = await request(page.dataset.createUrl, {
                method: 'POST',
                body: new FormData(form)
            });
            if (!result.created || typeof result.created.token !== 'string') {
                throw new Error('Сервер не вернул созданный ключ');
            }
            secretValue.value = result.created.token;
            secretPanel.hidden = false;
            setStatus('Ключ создан. Сохраните его в защищённом месте.');
            form.reset();
        } catch (error) {
            setStatus(error.message, true);
        } finally {
            button.disabled = false;
            busy = false;
            // Список перечитывается отдельно, чтобы секрет не хранился в нём.
            await loadKeys();
        }
    });

    copy.addEventListener('click', async () => {
        if (!secretValue.value) return;
        try {
            await navigator.clipboard.writeText(secretValue.value);
            setStatus('Ключ скопирован. Удалите его из буфера обмена после сохранения.');
        } catch {
            secretValue.focus();
            secretValue.select();
            setStatus('Выделен ключ для ручного копирования.');
        }
    });
    hide.addEventListener('click', clearSecret);
    refresh.addEventListener('click', loadKeys);
    auditRefresh.addEventListener('click', loadAudit);
    window.addEventListener('pagehide', clearSecret);
    loadKeys();
    loadAudit();
})();
