(() => {
    'use strict';

    function showInstallProgress(form) {
        const page = form.closest('.admin-page');
        const progress = page?.querySelector('[data-update-progress]');
        if (!progress) return;

        progress.hidden = false;
        progress.scrollIntoView({ block: 'nearest', behavior: 'smooth' });

        form.querySelectorAll('button, input[type="submit"]').forEach((control) => {
            control.disabled = true;
            control.setAttribute('aria-disabled', 'true');
        });

        const button = form.querySelector('button[type="submit"], input[type="submit"]');
        if (button instanceof HTMLButtonElement) {
            button.dataset.originalLabel = button.textContent || '';
            button.textContent = 'Установка выполняется…';
        }
    }

    function boot() {
        document.addEventListener('submit', (event) => {
            const form = event.target instanceof HTMLFormElement
                ? event.target.closest('[data-update-install-form]')
                : null;
            if (!form) return;

            queueMicrotask(() => {
                if (event.defaultPrevented) return;
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
