(() => {
    'use strict';

    const CHECK_INTERVAL_MS = 5 * 60 * 1000;
    const CACHE_KEY = 'workspace.update.notification.v1';
    const CHECKED_AT_KEY = 'workspace.update.notification.checked_at.v1';
    const NOTIFIED_PREFIX = 'workspace.update.notification.shown.';

    document.addEventListener('DOMContentLoaded', () => {
        const host = document.querySelector('[data-update-notifications]');
        if (!host) return;

        const statusUrl = String(host.dataset.updateStatusUrl || '').trim();
        const toggle = host.querySelector('[data-update-notifications-toggle]');
        const panel = host.querySelector('[data-update-notifications-panel]');
        const badge = host.querySelector('[data-update-badge]');
        const empty = host.querySelector('[data-update-empty]');
        const item = host.querySelector('[data-update-item]');
        const title = host.querySelector('[data-update-title]');
        const message = host.querySelector('[data-update-message]');
        const form = host.querySelector('[data-update-apply-form]');
        const action = host.querySelector('[data-update-action]');

        if (!statusUrl || !toggle || !panel || !badge || !empty || !item || !title || !message) {
            return;
        }

        function setPanel(open) {
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        toggle.addEventListener('click', () => {
            setPanel(panel.hidden);
        });

        document.addEventListener('click', (event) => {
            if (!panel.hidden && !host.contains(event.target)) {
                setPanel(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.hidden) {
                setPanel(false);
                toggle.focus();
            }
        });

        if (form && action) {
            form.addEventListener('submit', () => {
                action.disabled = true;
                action.textContent = 'Устанавливаем…';
            });
        }

        function safeStorageGet(key) {
            try {
                return window.localStorage.getItem(key);
            } catch (_) {
                return null;
            }
        }

        function safeStorageSet(key, value) {
            try {
                window.localStorage.setItem(key, value);
            } catch (_) {
                // Недоступное локальное хранилище не должно ломать проверку обновлений.
            }
        }

        function render(payload, announce) {
            const available = payload?.status === 'ok' && payload?.update_available === true;
            if (!available) {
                badge.hidden = true;
                item.hidden = true;
                empty.hidden = false;
                return;
            }

            const version = String(payload.target_version || '').trim();
            const versionCode = Number(payload.target_version_code || 0);
            const canApply = payload.can_apply === true;

            badge.hidden = false;
            item.hidden = false;
            empty.hidden = true;
            title.textContent = version ? `Доступно обновление ${version}` : 'Доступно обновление';
            message.textContent = canApply
                ? 'Новая версия проверена сервером обновлений и готова к автоматической установке.'
                : 'Новая версия доступна. Установить её может суперадминистратор.';

            if (form) form.hidden = !canApply;
            if (action) {
                action.textContent = version ? `Обновить до ${version}` : 'Обновить';
                action.disabled = false;
            }

            if (!announce || versionCode <= 0) return;
            const notifiedKey = NOTIFIED_PREFIX + versionCode;
            if (safeStorageGet(notifiedKey) === '1') return;

            const feedback = window.wspace?.feedback;
            if (typeof feedback?.toast === 'function') {
                feedback.toast(
                    version
                        ? `Доступно обновление Workspace Organizer ${version}`
                        : 'Доступно обновление Workspace Organizer',
                    'info',
                    8000
                );
            }
            safeStorageSet(notifiedKey, '1');
        }

        function readCached() {
            const raw = safeStorageGet(CACHE_KEY);
            if (!raw) return null;
            try {
                const value = JSON.parse(raw);
                return value && typeof value === 'object' ? value : null;
            } catch (_) {
                return null;
            }
        }

        function saveCached(payload) {
            safeStorageSet(CACHE_KEY, JSON.stringify(payload));
            safeStorageSet(CHECKED_AT_KEY, String(Date.now()));
        }

        async function check(force = false) {
            if (document.visibilityState === 'hidden') return;

            const checkedAt = Number(safeStorageGet(CHECKED_AT_KEY) || 0);
            if (!force && checkedAt > 0 && Date.now() - checkedAt < CHECK_INTERVAL_MS) {
                const cached = readCached();
                if (cached) render(cached, false);
                return;
            }

            try {
                const response = await fetch(statusUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (!response.ok) return;

                const payload = await response.json();
                if (!payload || typeof payload !== 'object') return;
                saveCached(payload);
                render(payload, true);
            } catch (_) {
                // Сбой сети не мешает основной работе. Следующая проверка повторится автоматически.
            }
        }

        const cached = readCached();
        if (cached) render(cached, false);
        check(true);

        window.setInterval(() => check(true), CHECK_INTERVAL_MS);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') check(false);
        });
        window.addEventListener('online', () => check(true));
    });
})();
