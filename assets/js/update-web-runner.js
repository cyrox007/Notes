(() => {
    'use strict';

    const RETRY_DELAY_MS = 1200;
    const MAX_TRANSIENT_RETRIES = 8;
    const REQUEST_TIMEOUT_MS = 90000;

    class UpdateRequestError extends Error {
        constructor(message, retryable) {
            super(message);
            this.retryable = retryable;
        }
    }

    function wait(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    async function jsonRequest(url, options) {
        const controller = new AbortController();
        const timer = window.setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
        try {
            const response = await fetch(url, Object.assign({
                signal: controller.signal,
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
                throw new UpdateRequestError(
                    `Сервер обновления вернул некорректный ответ (HTTP ${response.status}).`,
                    response.status === 408 || response.status === 429 || response.status >= 500
                );
            }

            return { response, payload };
        } finally {
            window.clearTimeout(timer);
        }
    }

    async function run(form, onProgress = null) {
        if (!(form instanceof HTMLFormElement)) {
            throw new Error('Не найдена форма запуска обновления.');
        }

        const startUrl = String(form.dataset.updateStartUrl || form.action || '');
        let stepUrl = String(form.dataset.updateStepUrl || '');
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
        if (result.continuation_url) {
            const continuationUrl = new URL(String(result.continuation_url), window.location.href);
            if (continuationUrl.origin !== window.location.origin) {
                throw new Error('Недопустимый адрес продолжения обновления.');
            }
            stepUrl = continuationUrl.href;
        }
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
                    const retryable = typeof step.payload?.retryable === 'boolean'
                        ? step.payload.retryable
                        : step.response.status === 408 || step.response.status === 429
                            || step.response.status >= 500;
                    if (!retryable || retries >= MAX_TRANSIENT_RETRIES) {
                        throw new UpdateRequestError(
                            `${step.payload?.message || 'Не удалось продолжить обновление.'} `
                                + `(HTTP ${step.response.status}; код: ${step.payload?.error || 'unknown'}; `
                                + `транзакция: ${transactionId}; повторов: ${retries}).`, retryable
                        );
                    }

                    retries += 1;
                    await wait(RETRY_DELAY_MS);
                    continue;
                }

                retries = 0;
                result = step.payload.result;
                if (result.continuation_url) {
                    const continuationUrl = new URL(String(result.continuation_url), window.location.href);
                    if (continuationUrl.origin !== window.location.origin) {
                        throw new UpdateRequestError('Недопустимый адрес продолжения обновления.', false);
                    }
                    stepUrl = continuationUrl.href;
                }
                if (typeof onProgress === 'function') onProgress(result);

                const runtimeRefreshDelay = Math.max(
                    0,
                    Math.min(60000, Number(result.runtime_refresh_delay_ms || 0))
                );
                if (runtimeRefreshDelay > 0) {
                    await wait(runtimeRefreshDelay);
                }
            } catch (error) {
                if (error instanceof UpdateRequestError && !error.retryable) throw error;
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
