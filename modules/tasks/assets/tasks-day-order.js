window.wspace.page.register('modules/tasks/assets/tasks-day-order.js', (pageScope) => {
    'use strict';

    const root = document.querySelector('.tasks[data-day-order-enabled="1"]');
    if (!root || root.dataset.dayOrderReady === '1') return;
    root.dataset.dayOrderReady = '1';

    const list = root.querySelector('.tasks__list');
    const status = root.querySelector('[data-day-order-status]');
    const pendingCommands = new Map();
    let busy = false;

    function message(text, error = false) {
        if (!status) return;
        status.textContent = text;
        status.dataset.error = error ? '1' : '0';
    }

    function commandId() {
        const value = globalThis.crypto?.randomUUID?.()
            || Date.now().toString(36) + Math.random().toString(36).slice(2);
        return 'task:day-order:' + value;
    }

    async function move(button) {
        if (busy || !list) return;
        const card = button.closest('.task-item');
        const uid = card?.dataset.taskId || '';
        const day = root.dataset.dayQueue || '';
        const direction = button.dataset.dayMove || '';
        const version = root.dataset.dayQueueVersion || '';
        if (!/^[a-f0-9]{32}$/.test(uid) || !/^[1-9][0-9]*$/.test(version)) {
            message('Список устарел. Обновите страницу.', true);
            return;
        }

        const signature = [day, uid, direction, version].join('|');
        const key = pendingCommands.get(signature) || commandId();
        pendingCommands.set(signature, key);
        const csrf = root.querySelector('input[name="csrf_token"]')?.value || '';
        const fields = new URLSearchParams({
            csrf_token: csrf,
            day, task_uid: uid, direction,
            expected_version: version,
            command_id: key
        });

        busy = true;
        button.disabled = true;
        message('Сохраняем порядок…');
        let responded = false;
        try {
            const response = await pageScope.fetch(root.dataset.dayOrderUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                body: fields.toString()
            });
            responded = true;
            const payload = await response.json().catch(() => null);
            pendingCommands.delete(signature);
            if (!response.ok || payload?.success !== true) {
                if (response.status === 409) {
                    message(payload?.message || 'Очередь изменилась. Обновляем список…', true);
                    pageScope.refresh();
                    return;
                }
                throw new Error(payload?.message || 'Не удалось изменить порядок');
            }

            root.dataset.dayQueueVersion = String(payload.version);
            if (payload.unchanged) {
                message('Задача уже находится на краю очереди.');
                return;
            }

            const neighbor = direction === 'up'
                ? card.previousElementSibling : card.nextElementSibling;
            if (!neighbor?.classList.contains('task-item')) {
                pageScope.refresh();
                return;
            }
            if (direction === 'up') {
                list.insertBefore(card, neighbor);
            } else {
                list.insertBefore(neighbor, card);
            }
            message('Порядок сохранён.');
        } catch (error) {
            // После потери ответа идентификатор команды сохраняется для повтора.
            if (responded) pendingCommands.delete(signature);
            message(error?.message || 'Нет связи с сервером. Повторите действие.', true);
        } finally {
            busy = false;
            button.disabled = false;
        }
    }

    pageScope.listen(root, 'click', (event) => {
        const button = event.target.closest('[data-day-move]');
        if (!button || !root.contains(button)) return;
        event.preventDefault();
        void move(button);
    });
});
