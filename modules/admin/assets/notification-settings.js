window.wspace.page.register('modules/admin/assets/notification-settings.js', (pageScope) => {
    pageScope.onInit(() => {
        const page = document.querySelector('[data-notification-administration]');
        if (!page) return;
        const feedback = page.querySelector('[data-notification-feedback]');
        page.querySelectorAll('[data-notification-ajax-form]').forEach((form) => {
            pageScope.listen(form, 'submit', async (event) => {
                event.preventDefault();
                const button = form.querySelector('[type="submit"]');
                if (!button || button.disabled) return;
                button.disabled = true;
                form.setAttribute('aria-busy', 'true');
                feedback.hidden = false;
                feedback.textContent = 'Отправка запроса…';
                feedback.dataset.state = 'loading';
                try {
                    const data = new FormData(form);
                    const response = await pageScope.fetch(form.action, {
                        method: 'POST',
                        body: data,
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const result = await response.json();
                    if (!response.ok || result.status !== 'ok') {
                        throw new Error(result.message || ('Ошибка запроса: ' + response.status));
                    }
                    feedback.textContent = result.message || 'Операция выполнена.';
                    if (result.job_id) {
                        feedback.textContent += ' Номер задания: ' + result.job_id
                            + '; состояние: ' + result.state
                            + '; код: ' + (result.last_result_code || 'пока нет') + '.';
                        if (typeof result.guidance === 'string' && result.guidance !== '') {
                            feedback.textContent += ' ' + result.guidance;
                        }
                    }
                    // HTTP 200 подтверждает запись пробного события, но не
                    // означает успешную передачу письма внешнему серверу.
                    let indication = 'success';
                    if (result.job_id && result.state === 'failed') {
                        indication = 'error';
                    } else if (result.job_id && result.state !== 'sent') {
                        indication = 'warning';
                    }
                    feedback.dataset.state = indication;
                    const password = form.querySelector('[name="current_password"]');
                    if (password) password.value = '';
                } catch (error) {
                    feedback.dataset.state = 'error';
                    feedback.textContent = error instanceof Error ? error.message : 'Не удалось выполнить операцию.';
                } finally {
                    button.disabled = false;
                    form.removeAttribute('aria-busy');
                }
            });
        });
    });
});
