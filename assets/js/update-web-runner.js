(() => {
    'use strict';

    const RETRY_DELAY_MS = 1200;
    const MAX_TRANSIENT_RETRIES = 8;

    function wait(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    async function jsonRequest(url, options) {
        const response = await fetch(url, Object.assign({
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }, options));

        let payload = null;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('Сервер обновления вернул некорректный ответ.');
        }

        return { response, payload };
    }

    async function run(form, onProgress = null) {
        if (!(form instanceof HTMLFormElement)) {
            throw new Error('Не найдена форма запуска обновления.');
        }

        const startUrl = String(form.dataset.updateStartUrl || form.action || '');
        const stepUrl = String(form.dataset.updateStepUrl || '');
        if (!startUrl || !stepUrl) {
            throw new Error('Не найден безопасный endpoint web-updater.');
        }

        const started = await jsonRequest(startUrl, {
            method: 'POST',
            body: new FormData(form),
        });
        if (!started.response.ok || !started.payload?.success || !started.payload?.result) {
            throw new Error(started.payload?.message || 'Не удалось начать обновление.');
        }

        let result = started.payload.result;
        const transactionId = String(result.transaction_id || '');
        const token = String(result.continuation_token || '');
        if (!transactionId || !token) {
            throw new Error('Сервер не вернул безопасное продолжение транзакции.');
        }

        if (typeof onProgress === 'function') onProgress(result);

        let retries = 0;
        while (String(result.status || '') === 'in_progress') {
            try {
                const step = await jsonRequest(stepUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-Workspace-Update-Transaction': transactionId,
                        'X-Workspace-Update-Token': token,
                    },
                });

                if (!step.response.ok || !step.payload?.success || !step.payload?.result) {
                    const retryable = Boolean(step.payload?.retryable)
                        || step.response.status === 409
                        || step.response.status >= 500;
                    if (!retryable || retries >= MAX_TRANSIENT_RETRIES) {
                        throw new Error(step.payload?.message || 'Не удалось продолжить обновление.');
                    }

                    retries += 1;
                    await wait(RETRY_DELAY_MS);
                    continue;
                }

                retries = 0;
                result = step.payload.result;
                if (typeof onProgress === 'function') onProgress(result);
            } catch (error) {
                if (retries >= MAX_TRANSIENT_RETRIES) throw error;
                retries += 1;
                await wait(RETRY_DELAY_MS);
            }
        }

        return result;
    }

    window.wspace = window.wspace || {};
    window.wspace.updateWebRunner = Object.freeze({ run });
})();
