window.wspace.page.register('modules/tasks/assets/task-boards-versioned.js', (pageScope) => {
(() => {
    'use strict';

    function boot() {
        const root = document.querySelector('.task-boards-page');
        if (!root || root.dataset.versionedBoardsReady === '1') return;
        root.dataset.versionedBoardsReady = '1';

        const pending = new WeakMap();

        function cardFor(form) {
            return form.closest('.task-board-card');
        }

        function generateCommandId() {
            return 'board:web:' + (globalThis.crypto?.randomUUID?.()
                || Date.now().toString(36) + Math.random().toString(36).slice(2));
        }

        function applyVersion(card, version) {
            if (!card || !Number.isInteger(Number(version)) || Number(version) < 1) return;
            card.dataset.version = String(version);
            card.querySelectorAll('input[name="expected_version"]').forEach(input => {
                input.value = String(version);
            });
        }

        function reconcile(card, current) {
            if (!current) return;
            applyVersion(card, current.version);
            const statusForm = card?.querySelector('select[name="status"]');
            if (statusForm && current.status) {
                statusForm.value = current.status;
                statusForm.dataset.serverStatus = current.status;
            }
            const title = card?.querySelector('.task-board-card__title strong');
            if (title && typeof current.title === 'string') title.textContent = current.title;
        }

        function recount() {
            root.querySelectorAll('.task-board-column').forEach(column => {
                const pill = column.querySelector('.task-board-pill');
                if (pill) pill.textContent = String(column.querySelectorAll('.task-board-card').length);
            });
        }

        async function perform(form) {
            if (form.dataset.submitting === '1') return;
            const card = cardFor(form);
            if (!card) return;

            const body = new URLSearchParams(new FormData(form));
            const version = card.dataset.version;
            if (!/^[1-9][0-9]*$/.test(version || '')) {
                window.alert('Неизвестна версия задачи. Обновите доску.');
                return;
            }
            body.set('expected_version', version);
            const signature = body.toString().replace(/(^|&)command_id=[^&]*/g, '$1command_id=');
            const previous = pending.get(form);
            const commandId = previous?.signature === signature
                ? previous.commandId : generateCommandId();
            pending.set(form, {signature, commandId});
            body.set('command_id', commandId);
            form.querySelector('input[name="command_id"]').value = commandId;
            form.dataset.submitting = '1';
            card.dataset.moveState = 'saving';

            try {
                const response = await pageScope.fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: body.toString()
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok || result.success !== true) {
                    if (response.status === 409 && result.error === 'version_conflict') {
                        pending.delete(form);
                        reconcile(card, result.current);
                        pageScope.refresh();
                    }
                    throw new Error(result.message || 'Не удалось изменить задачу');
                }

                pending.delete(form);
                applyVersion(card, result.version);
                card.dataset.moveState = 'saved';

                if (form.action.includes('/delete') || body.has('archived')) {
                    pageScope.refresh();
                    return;
                }
                if (body.has('status')) {
                    const status = String(result.current?.status || body.get('status'));
                    const select = card.querySelector('select[name="status"]');
                    if (select) select.dataset.serverStatus = status;
                    const column = [...root.querySelectorAll('.task-board-column')]
                        .find(item => item.dataset.status === status);
                    column?.querySelector('.task-board-column__items')?.appendChild(card);
                    recount();
                }
            } catch (error) {
                card.dataset.moveState = 'error';
                const select = form.querySelector('select[name="status"]');
                if (select) select.value = select.dataset.serverStatus || select.defaultValue || select.value;
                window.alert(error.message || 'Не удалось сохранить задачу');
            } finally {
                delete form.dataset.submitting;
            }
        }

        root.querySelectorAll('.task-board-card select[name="status"]').forEach(select => {
            select.dataset.serverStatus = select.value;
        });

        root.addEventListener('change', event => {
            const select = event.target?.closest?.('.task-board-card select[name="status"]');
            if (!select || !root.contains(select)) return;
            const form = select.form;
            if (!form?.hasAttribute('data-board-task-mutation')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            if (select.value === select.dataset.serverStatus) return;
            void perform(form);
        }, true);

        root.addEventListener('submit', event => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-board-task-mutation')) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            if (form.action.includes('/delete') && !window.confirm('Удалить задачу с общей доски?')) return;
            void perform(form);
        }, true);
    }

    if (document.readyState === 'loading') pageScope.onInit(boot);
    else boot();
})();
});
