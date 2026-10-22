(() => {
    'use strict';

    const root = document.querySelector('[data-assignee-queue]');
    if (!root) return;

    const refresh = root.querySelector('[data-queue-refresh]');
    const status = root.querySelector('[data-queue-status]');
    const groups = root.querySelector('[data-queue-groups]');
    const summary = root.querySelector('[data-queue-summary]');
    const timezoneForm = root.querySelector('[data-queue-timezone-form]');
    const timezoneInput = timezoneForm.querySelector('[name="timezone"]');
    const labels = { overdue: 'Просрочено', today: 'Сегодня', tomorrow: 'Завтра' };
    let loading = false;

    function node(tag, css, content) {
        const el = document.createElement(tag);
        if (css) el.className = css;
        if (content !== undefined) el.textContent = content;
        return el;
    }

    function draw(data) {
        groups.replaceChildren();
        timezoneInput.value = data.timezone || '';
        const counts = data.summary || {};
        summary.textContent = 'На ' + (data.date || 'сегодня') + ': просрочено — ' + (counts.overdue ?? 0) +
            ', сегодня — ' + (counts.today ?? 0) + ', завтра — ' + (counts.tomorrow ?? 0) +
            '. Часовой пояс: ' + (data.timezone || 'не задан') + '.';
        for (const key of ['overdue', 'today', 'tomorrow']) {
            const section = node('section', 'tasks-assignee__group');
            const items = Array.isArray(data.groups?.[key]) ? data.groups[key] : [];
            section.append(node('h3', '', labels[key] + ' · ' + items.length +
                (data.has_more?.[key] ? '+' : '')));
            if (items.length === 0) {
                section.append(node('p', 'tasks-assignee__empty', 'Нет задач'));
            }
            for (const item of items) {
                const row = node('a', 'tasks-assignee__item');
                const personal = item.source === 'personal';
                const url = personal ? root.dataset.personalUrl :
                    root.dataset.boardsUrl + '?board=' + encodeURIComponent(item.board_uid || '');
                row.href = url;
                row.append(node('strong', '', item.title || 'Задача'));
                row.append(node('span', 'tasks-assignee__meta',
                    (personal ? 'Личная задача' : item.board_name || 'Общая доска') +
                    ' · ' + (item.due_date || 'Без срока')));
                section.append(row);
            }
            groups.append(section);
        }
    }

    async function load() {
        if (loading) return;
        loading = true;
        refresh.disabled = true;
        status.textContent = 'Обновляем рабочую очередь…';
        try {
            const res = await fetch(root.dataset.queueUrl, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            if (!res.ok || !(res.headers.get('content-type') || '').includes('application/json')) {
                throw new Error('Не удалось получить задачи. Проверьте подключение и повторите запрос.');
            }
            const data = await res.json();
            if (!data || !data.groups) throw new Error('Сервер вернул неполную очередь');
            draw(data);
            status.textContent = 'Рабочая очередь обновлена';
        } catch (error) {
            status.textContent = error.message || 'Ошибка загрузки';
        } finally {
            refresh.disabled = false;
            loading = false;
        }
    }

    timezoneForm.addEventListener('submit', async event => {
        event.preventDefault();
        const button = timezoneForm.querySelector('[type="submit"]');
        button.disabled = true;
        status.textContent = 'Сохраняем часовой пояс…';
        try {
            const response = await fetch(root.dataset.timezoneUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(timezoneForm)
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || 'Не удалось сохранить часовой пояс');
            await load();
        } catch (error) {
            status.textContent = error.message || 'Ошибка сохранения';
        } finally {
            button.disabled = false;
        }
    });
    refresh.addEventListener('click', load);
    load();
})();