(() => {
    'use strict';

    const RETRY_DELAY_MS = 1200;
    const MAX_TRANSIENT_RETRIES = 8;

    function progressElements(form) {
        const page = form.closest('.admin-page');
        const progress = page?.querySelector('[data-update-progress]') || null;

        return {
            progress,
            title: progress?.querySelector('[data-update-progress-title]') || null,
            message: progress?.querySelector('[data-update-progress-message]') || null,
            bar: progress?.querySelector('[data-update-progress-bar]') || null,
        };
    }

    function showInstallProgress(form) {
        const elements = progressElements(form);
        if (!elements.progress) {
            return elements;
        }

        elements.progress.hidden = false;
        elements.progress.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

        form.querySelectorAll('button, input[type="submit"]').forEach((control) => {
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        });

        const button = form.querySelector('button[type="submit"], input[type="submit"]');
        if (button instanceof HTMLButtonElement) {
            button.dataset.originalLabel = button.textContent || '';
            button.textContent = 'Установка выполняется…';
        }

        return elements;
    }

    function updateProgress(elements, result) {
        const progress = Math.max(0, Math.min(100, Number(result?.progress || 0)));
        const message = String(result?.message || 'Выполняется безопасное обновление…');

        if (elements.bar instanceof HTMLProgressElement) {
            elements.bar.value = progress;
        }
        if (elements.message) {
            elements.message.textContent = message;
        }
        if (elements.title) {
            elements.title.textContent = progress >= 100
                ? 'Установка завершена'
                : `Установка выполняется — ${progress}%`;
        }
    }

    function failProgress(elements, message) {
        if (elements.title) {
            elements.title.textContent = 'Обновление требует повторной проверки';
        }
        if (elements.message) {
            elements.message.textContent = message;
        }
    }

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
        } catch (error) {
            throw new Error('Сервер обновления вернул некорректный ответ.');
        }

        return { response, payload };
    }

    async function startWebUpdate(form) {
        const startUrl = String(form.dataset.updateStartUrl || form.action || '');
        const { response, payload } = await jsonRequest(startUrl, {
            method: 'POST',
            body: new FormData(form),
        });

        if (!response.ok || !payload?.success || !payload?.result) {
            throw new Error(payload?.message || 'Не удалось начать обновление.');
        }

        return payload.result;
    }

    async function performWebStep(stepUrl, transactionId, token) {
        return jsonRequest(stepUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-Workspace-Update-Transaction': transactionId,
                'X-Workspace-Update-Token': token,
            },
        });
    }

    async function runWebUpdate(form) {
        const confirmMessage = String(form.dataset.updateWebConfirm || '');
        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        const elements = showInstallProgress(form);
        const stepUrl = String(form.dataset.updateStepUrl || '');
        if (!stepUrl) {
            failProgress(elements, 'Не найден безопасный endpoint продолжения обновления.');
            return;
        }

        try {
            let result = await startWebUpdate(form);
            const transactionId = String(result.transaction_id || '');
            const token = String(result.continuation_token || '');

            if (!transactionId || !token) {
                throw new Error('Сервер не вернул безопасное продолжение транзакции.');
            }

            updateProgress(elements, result);

            let retries = 0;
            while (String(result.status || '') === 'in_progress') {
                try {
                    const step = await performWebStep(stepUrl, transactionId, token);
                    if (!step.response.ok || !step.payload?.success || !step.payload?.result) {
                        const retryable = Boolean(step.payload?.retryable)
                            || step.response.status === 409
                            || step.response.status >= 500;

                        if (retryable && retries < MAX_TRANSIENT_RETRIES) {
                            retries += 1;
                            await wait(RETRY_DELAY_MS);
                            continue;
                        }

                        throw new Error(
                            step.payload?.message
                                || 'Не удалось продолжить обновление.'
                        );
                    }

                    retries = 0;
                    result = step.payload.result;
                    updateProgress(elements, result);
                } catch (error) {
                    if (retries < MAX_TRANSIENT_RETRIES) {
                        retries += 1;
                        await wait(RETRY_DELAY_MS);
                        continue;
                    }
                    throw error;
                }
            }

            if (String(result.status || '') === 'committed') {
                updateProgress(elements, Object.assign({}, result, {
                    progress: 100,
                    message: 'Обновление установлено. Перезагружаем интерфейс…',
                }));
                await wait(700);
                window.location.reload();
                return;
            }

            if (String(result.status || '') === 'recovered') {
                failProgress(
                    elements,
                    result.message
                        || 'Обновление не установлено. Предыдущая версия автоматически восстановлена.'
                );
                await wait(1400);
                window.location.reload();
                return;
            }

            throw new Error('Updater завершился в неизвестном состоянии.');
        } catch (error) {
            failProgress(
                elements,
                error instanceof Error
                    ? error.message
                    : 'Обновление не удалось продолжить. Автоматическое восстановление выполнится по журналу.'
            );
        }
    }

    function boot() {
        document.addEventListener('submit', (event) => {
            const form = event.target instanceof HTMLFormElement
                ? event.target.closest('[data-update-install-form]')
                : null;
            if (!form) {
                return;
            }

            if (form.dataset.updateWebMode === 'true') {
                event.preventDefault();
                void runWebUpdate(form);
                return;
            }

            queueMicrotask(() => {
                if (event.defaultPrevented) {
                    return;
                }
                document.dispatchEvent(new CustomEvent('wspace:update-install-start'));
                showInstallProgress(form);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
})();
