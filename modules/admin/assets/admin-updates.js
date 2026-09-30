(() => {
    'use strict';

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

    async function runWebUpdate(form) {
        const confirmMessage = String(form.dataset.updateWebConfirm || '');
        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        const elements = showInstallProgress(form);
        const runner = window.wspace?.updateWebRunner;
        if (!runner || typeof runner.run !== 'function') {
            failProgress(elements, 'Не загружен безопасный web-updater.');
            return;
        }

        try {
            const result = await runner.run(form, (state) => updateProgress(elements, state));

            if (String(result.status || '') === 'committed') {
                updateProgress(elements, Object.assign({}, result, {
                    progress: 100,
                    message: 'Обновление установлено. Перезагружаем интерфейс…',
                }));
                window.setTimeout(() => window.location.reload(), 700);
                return;
            }

            if (String(result.status || '') === 'recovered') {
                failProgress(
                    elements,
                    result.message
                        || 'Обновление не установлено. Предыдущая версия автоматически восстановлена.'
                );
                window.setTimeout(() => window.location.reload(), 1400);
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
