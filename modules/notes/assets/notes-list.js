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

        async function confirmAction(message) {
            if (feedback?.confirm) {
                return feedback.confirm(message, {
                    title: 'Удаление заметки',
                    confirmText: 'Удалить',
                    danger: true,
                });
            }
            return window.confirm(message);
        }

        async function requestJson(url, options = {}) {
            const headers = new Headers(options.headers || {});
            headers.set('Accept', 'application/json');
            headers.set('X-Requested-With', 'XMLHttpRequest');
            const response = await fetch(url, {
                ...options,
                credentials: 'same-origin',
                headers,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok || payload?.success !== true) {
                throw new Error(payload?.error || `HTTP ${response.status}`);
            }
            return payload;
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

        async function refreshNotesContent() {
            const response = await fetch(window.location.href, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const html = await response.text();
            const documentCopy = new DOMParser().parseFromString(html, 'text/html');
            const current = root.querySelector('.notes__content');
            const fresh = documentCopy.querySelector('.notes__content');
            if (!current || !fresh) throw new Error('Сервер вернул неполный список заметок');
            current.replaceWith(document.importNode(fresh, true));
        }

        async function deleteNote(form, button) {
            if (busyForms.has(form)) return;
            const message = form.dataset.confirmMessage || 'Вы уверены, что хотите удалить эту заметку?';
            if (!await confirmAction(message)) return;

            busyForms.add(form);
            button.disabled = true;
            const row = form.closest('.notes__list_item');
            let deleted = false;

            try {
                await requestJson(form.action, {
                    method: (form.method || 'post').toUpperCase(),
                    body: new FormData(form),
                });
                deleted = true;

                try {
                    await refreshNotesContent();
                } catch (_) {
                    row?.remove();
                    ensurePersonalEmptyState();
                    syncCount();
                    toast('Заметка удалена. Список обновлён локально.', 'warning');
                    return;
                }

                toast('Заметка удалена', 'success');
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Не удалось удалить заметку';
                feedback?.inline?.(form, message, 'error');
                toast(message, 'error');
            } finally {
                busyForms.delete(form);
                if (!deleted && button.isConnected) button.disabled = false;
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
