window.wspace.page.register('modules/files/assets/spaces.js', (pageScope) => {
(() => {
    'use strict';

    function boot() {
        const root = document.querySelector('.file-spaces-page');
        if (!root || root.dataset.actionsReady === '1') return;
        root.dataset.actionsReady = '1';

        const newCommandId = () => 'files:web:' + (
            globalThis.crypto?.randomUUID?.()
            || Date.now().toString(36) + Math.random().toString(36).slice(2)
        );

        function syncSubject(form) {
            const type = form.querySelector('[data-filespace-subject-type]')?.value || 'everyone';
            const select = form.querySelector('[data-filespace-subject-id]');
            if (!select) return;
            let first = null;
            [...select.options].forEach(option => {
                const available = option.dataset.for === type;
                option.disabled = !available;
                option.hidden = !available;
                if (available && first === null) first = option;
            });
            if (first) select.value = first.value;
        }

        root.querySelectorAll('[data-filespace-subject-type]').forEach(select => {
            syncSubject(select.form);
            select.addEventListener('change', () => syncSubject(select.form));
        });

        root.querySelectorAll('[data-filespace-destination]').forEach(select => {
            const apply = () => {
                const versionInput = select.form?.querySelector('[name="expected_destination_version"]');
                if (versionInput) versionInput.value = select.selectedOptions[0]?.dataset.version || '';
            };
            select.addEventListener('change', apply);
            apply();
        });

        root.addEventListener('submit', async event => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement) || !form.matches('[data-filespace-action]')) return;
            event.preventDefault();
            if (form.dataset.filespaceConfirm && !window.confirm(form.dataset.filespaceConfirm)) return;
            if (form.dataset.submitting === '1') return;
            form.dataset.submitting = '1';
            const button = form.querySelector('[type="submit"]');
            if (button) button.disabled = true;

            try {
                const response = await pageScope.fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: new FormData(form),
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok || result.success !== true) {
                    if (response.status === 409 && result.error === 'version_conflict') {
                        pageScope.refresh();
                    }
                    throw new Error(result.message || ('Ошибка HTTP ' + response.status));
                }
                form.querySelector('[name="command_id"]').value = newCommandId();
                pageScope.refresh();
            } catch (error) {
                const message = error?.message || 'Не удалось сохранить изменения';
                if (window.wspace?.feedback?.toast) window.wspace.feedback.toast(message, 'error');
                else window.alert(message);
            } finally {
                delete form.dataset.submitting;
                if (button) button.disabled = false;
            }
        }, true);
    }

    if (document.readyState === 'loading') pageScope.onInit(boot);
    else boot();
})();
});
