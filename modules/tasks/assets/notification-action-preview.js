(() => {
    'use strict';

    function start() {
        const root = document.querySelector('[data-notification-action-preview]');
        if (!root) return;

        const button = root.querySelector('[data-notification-action-confirm]');
        const feedback = root.querySelector('[data-notification-action-feedback]');
        const params = new URLSearchParams(window.location.hash.slice(1));
        const token = params.get('token') || '';
        // Сразу удаляем секрет из истории и адресной строки браузера.
        if (window.location.hash) {
            window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search);
        }

        if (!/^[a-f0-9]{64}$/.test(token)) {
            feedback.textContent = 'Ссылка недействительна. Откройте актуальное уведомление.';
            return;
        }

        const csrf = document.querySelector('#csrf-token-template input[name="csrf_token"]')?.value;
        if (!csrf) {
            feedback.textContent = 'Сессия устарела. Обновите страницу.';
            return;
        }

        const check = async () => {
            const body = new URLSearchParams({ action_token: token, csrf_token: csrf });
            const response = await fetch(root.dataset.inspectUrl, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                redirect: 'error',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });
            const result = await response.json();
            if (!response.ok || result.success !== true) {
                throw new Error(result.message || 'Ссылка уже не действует.');
            }
        };

        void check().then(() => {
            feedback.textContent = 'Ссылка действительна. Подтвердите выполнение задачи.';
            button.disabled = false;
        }).catch((error) => {
            feedback.textContent = error instanceof Error ? error.message : 'Ссылка недоступна.';
        });

        button.addEventListener('click', async () => {
            button.disabled = true;
            feedback.textContent = 'Проверяем разрешение и сохраняем…';
            try {
                const body = new URLSearchParams({ action_token: token, csrf_token: csrf });
                const response = await fetch(root.dataset.confirmUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    redirect: 'error',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body.toString()
                });
                const result = await response.json();
                if (!response.ok || result.success !== true) {
                    throw new Error(result.message || 'Подтверждение не удалось.');
                }
                feedback.textContent = 'Задача отмечена выполненной. Ссылка больше не действует.';
            } catch (error) {
                feedback.textContent = error instanceof Error
                    ? error.message : 'Не удалось подтвердить действие.';
            }
        }, { once: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start, { once: true });
    } else {
        start();
    }
})();
