(() => {
    'use strict';

    function boot() {
        const root = document.querySelector('.notes');
        if (!root || root.dataset.pageBehaviorReady === '1') return;
        root.dataset.pageBehaviorReady = '1';

        const feedback = window.wspace?.feedback;
        const busyForms = new WeakSet();

        function toast(message, type = 'info') {
            feedback?.toast?.(message, type);
        }

        function confirmAction(message) {
            return window.confirm(message);
        }

        function visibleList() {
            return root.querySelector('#all-user.visible') || root.querySelector('#personal.visible') || root.querySelector('#personal');
        }

        function syncCount() {
            const count = root.querySelector('.notes__count');
            const list = visibleList();
            if (count && list) count.textContent = String(list.querySelectorAll('.notes__name').length);
        }

        function ensurePersonalEmptyState() {
            const personal = root.querySelector('#personal');
            if (!personal || personal.querySelector('.notes__name')) return;
            if (personal.querySelector('.notes__list_item')) return;

            const item = document.createElement('div');
            item.className = 'notes__list_item';
            const text = document.createElement('p');
            text.textContent = 'Здесь ничего нет';
            item.append(text);
            personal.append(item);
        }

        function replaceNotesContent(html, sourceUrl, deletedAction) {
            const documentCopy = new DOMParser().parseFromString(html, 'text/html');
            const current = root.querySelector('.notes__content');
            const fresh = documentCopy.querySelector('.notes__content');
            if (!current || !fresh) throw new Error('Сервер вернул неполный список заметок');

            const noteStillPresent = [...documentCopy.querySelectorAll('.notes__delete-form')].some((form) => {
                const action = form.getAttribute('action');
                if (!action) return false;
                return new URL(action, sourceUrl).pathname === deletedAction;
            });
            if (noteStillPresent) throw new Error('Сервер не подтвердил удаление заметки');

            current.replaceWith(document.importNode(fresh, true));
        }

        async function deleteNote(form, button) {
            if (busyForms.has(form)) return;
            const message = form.dataset.confirmMessage || 'Вы уверены, что хотите удалить эту заметку?';
            if (!confirmAction(message)) return;

            busyForms.add(form);
            button.disabled = true;
            const row = form.closest('.notes__list_item');
            const deletedAction = new URL(form.action, window.location.href).pathname;
            let deleted = false;

            try {
                const response = await fetch(form.action, {
                    method: (form.method || 'post').toUpperCase(),
                    credentials: 'same-origin',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const html = await response.text();
                replaceNotesContent(html, response.url || window.location.href, deletedAction);
                deleted = true;
                toast('Заметка удалена', 'success');
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Не удалось удалить заметку';
                feedback?.inline?.(form, message, 'error');
                toast(message, 'error');
            } finally {
                busyForms.delete(form);
                if (!deleted && button.isConnected) button.disabled = false;
                if (deleted && row?.isConnected) {
                    row.remove();
                    ensurePersonalEmptyState();
                    syncCount();
                }
            }
        }

        root.addEventListener('click', (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            const toggle = target.closest('.switch-btn');
            if (toggle && root.contains(toggle)) {
                const personal = root.querySelector('#personal');
                const allUsers = root.querySelector('#all-user');
                const authorColumn = root.querySelector('.notes__list_head--author');
                if (!personal || !allUsers) return;

                const enabled = !toggle.classList.contains('switch-on');
                toggle.classList.toggle('switch-on', enabled);
                toggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
                personal.classList.toggle('visible', !enabled);
                allUsers.classList.toggle('visible', enabled);
                const title = root.querySelector('.notes__title');
                if (title) title.textContent = enabled ? 'Все пользователи' : 'Мои заметки';
                if (authorColumn) authorColumn.hidden = !enabled;
                syncCount();
                return;
            }

            const deleteButton = target.closest('.notes__btn--delete');
            if (!(deleteButton instanceof HTMLButtonElement) || !root.contains(deleteButton)) return;
            const form = deleteButton.closest('.notes__delete-form');
            if (!(form instanceof HTMLFormElement)) return;

            event.preventDefault();
            event.stopImmediatePropagation();
            void deleteNote(form, deleteButton);
        }, true);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
