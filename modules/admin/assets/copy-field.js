'use strict';

function copyTextFallback(value) {
    const helper = document.createElement('textarea');
    helper.value = value;
    helper.setAttribute('readonly', '');
    helper.style.position = 'fixed';
    helper.style.opacity = '0';
    helper.style.pointerEvents = 'none';

    document.body.appendChild(helper);
    helper.select();
    helper.setSelectionRange(0, value.length);

    let copied = false;
    try {
        copied = document.execCommand('copy');
    } finally {
        helper.remove();
    }

    return copied;
}

async function copyText(value) {
    if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {
            // Для локального HTTP и старых браузеров остаётся совместимый запасной путь.
        }
    }

    return copyTextFallback(value);
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-copy-target]').forEach((button) => {
        if (!(button instanceof HTMLButtonElement)) return;

        const selector = button.dataset.copyTarget || '';
        const target = selector ? document.querySelector(selector) : null;
        if (!target) return;

        const statusId = button.getAttribute('aria-describedby') || '';
        const status = statusId ? document.getElementById(statusId) : null;
        const defaultText = button.textContent?.trim() || 'Копировать';
        let resetTimer = null;

        button.addEventListener('click', async () => {
            const value = target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement
                ? target.value.trim()
                : target.textContent?.trim() || '';

            if (!value) {
                if (status) status.textContent = 'Нет значения для копирования';
                return;
            }

            button.disabled = true;
            const copied = await copyText(value);
            button.disabled = false;

            if (resetTimer !== null) {
                window.clearTimeout(resetTimer);
            }

            if (copied) {
                button.textContent = 'Скопировано';
                if (status) status.textContent = 'Код скопирован в буфер обмена';
            } else {
                button.textContent = 'Не скопировано';
                if (status) status.textContent = 'Не удалось скопировать код';
                if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement) {
                    target.focus();
                    target.select();
                }
            }

            resetTimer = window.setTimeout(() => {
                button.textContent = defaultText;
                if (status) status.textContent = '';
            }, 1800);
        });
    });
});
