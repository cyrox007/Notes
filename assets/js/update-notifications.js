(() => {
    'use strict';

    const CHECK_INTERVAL_MS = 5 * 60 * 1000;
    const CACHE_KEY = 'workspace.update.notification.v2';
    const CHECKED_AT_KEY = 'workspace.update.notification.checked_at.v2';
    const NOTIFIED_PREFIX = 'workspace.update.notification.shown.';

    function init() {
        const host = document.querySelector('[data-update-notifications]');
        if (!host) return;

        const statusUrl = String(host.dataset.updateStatusUrl || '').trim();
        const form = host.querySelector('[data-update-apply-form]');
        const action = host.querySelector('[data-update-action]');
        const detailsLink = host.querySelector('.workspace-notifications__header a');
        if (!statusUrl) return;

        host.hidden = true;
        host.setAttribute('aria-hidden', 'true');

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

        function dispatchNotification(payload) {
            const available = payload?.status === 'ok' && payload?.update_available === true;
            const version = String(payload?.target_version || '').trim();
            const canApply = payload?.can_apply === true;
            document.dispatchEvent(new CustomEvent('wspace:update-notification', {
                detail: {
                    available,
                    version,
                    canApply,
                    title: available
                        ? (version ? `Доступно обновление ${version}` : 'Доступно обновление')
                        : '',
                    message: available
                        ? (canApply
                            ? 'Новая версия проверена сервером обновлений и готова к автоматической установке.'
                            : 'Новая версия доступна. Установить её может суперадминистратор.')
                        : '',
                    detailsUrl: detailsLink instanceof HTMLAnchorElement ? detailsLink.href : ''
                }
            }));
        }

        function render(payload, announce) {
            dispatchNotification(payload);
            const available = payload?.status === 'ok' && payload?.update_available === true;
            if (!available || !announce) return;

            const version = String(payload.target_version || '').trim();
            const versionCode = Number(payload.target_version_code || 0);
            if (versionCode <= 0) return;
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

        async function installUpdate() {
            if (!(form instanceof HTMLFormElement) || !(action instanceof HTMLButtonElement)) {
                window.wspace?.feedback?.toast?.(
                    'Автоматическая установка недоступна. Откройте раздел «Обновления».',
                    'error',
                    7000
                );
                return;
            }

            if (form.dataset.updateWebMode !== 'true') {
                form.requestSubmit();
                return;
            }

            const runner = window.wspace?.updateWebRunner;
            if (!runner || typeof runner.run !== 'function') {
                window.wspace?.feedback?.toast?.(
                    'Не загружен безопасный web-updater. Откройте раздел «Обновления».',
                    'error',
                    8000
                );
                return;
            }

            document.dispatchEvent(new CustomEvent('wspace:update-install-state', {
                detail: { state: 'running', progress: 0 }
            }));
            action.disabled = true;
            document.dispatchEvent(new CustomEvent('wspace:update-install-start'));

            try {
                const result = await runner.run(form, (state) => {
                    const progress = Math.max(0, Math.min(100, Number(state?.progress || 0)));
                    document.dispatchEvent(new CustomEvent('wspace:update-install-state', {
                        detail: { state: 'running', progress }
                    }));
                });
                const status = String(result?.status || '');
                if (status === 'committed') {
                    safeStorageSet(CHECKED_AT_KEY, '0');
                    window.location.reload();
                    return;
                }
                if (status === 'recovered') {
                    safeStorageSet(CHECKED_AT_KEY, '0');
                    const diagnosticCode = String(result?.diagnostic_code || '').trim();
                    const recoveredMessage = result?.message
                        || 'Обновление не установлено. Предыдущая версия восстановлена.';
                    window.wspace?.feedback?.toast?.(
                        diagnosticCode ? `${recoveredMessage} Код диагностики: ${diagnosticCode}.` : recoveredMessage,
                        'error',
                        9000
                    );
                    document.dispatchEvent(new CustomEvent('wspace:update-install-state', {
                        detail: { state: 'error' }
                    }));
                    window.setTimeout(() => window.location.reload(), 1200);
                    return;
                }
                throw new Error('Updater завершился в неизвестном состоянии.');
            } catch (error) {
                action.disabled = false;
                document.dispatchEvent(new CustomEvent('wspace:update-install-state', {
                    detail: { state: 'error' }
                }));
                window.wspace?.feedback?.toast?.(
                    error instanceof Error ? error.message : 'Не удалось установить обновление.',
                    'error',
                    9000
                );
            }
        }

        document.addEventListener('wspace:update-install-request', () => void installUpdate());

        const cached = readCached();
        if (cached) render(cached, false);
        void check(true);
        window.setInterval(() => void check(true), CHECK_INTERVAL_MS);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') void check(false);
        });
        window.addEventListener('online', () => void check(true));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
