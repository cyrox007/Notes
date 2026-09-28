'use strict';

function copyInstallationIdFallback(value) {
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

async function copyInstallationId(value) {
    if (window.isSecureContext && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(value);
            return true;
        } catch {
            // Для локального HTTP и браузеров без Clipboard API используем совместимый запасной путь.
        }
    }

    return copyInstallationIdFallback(value);
}

document.addEventListener('DOMContentLoaded', function () {
    const button = document.querySelector('[data-copy-installation-id]');
    const valueNode = document.getElementById('installation-id-value');
    const status = document.getElementById('installation-id-copy-status');

    if (!button || !valueNode || !status) {
        return;
    }

    const defaultText = button.textContent?.trim() || 'Копировать';
    let resetTimer = null;

    button.addEventListener('click', async function () {
        const value = valueNode.textContent?.trim() || '';
        if (value === '') {
            status.textContent = 'Installation ID отсутствует';
            return;
        }

        button.disabled = true;
        const copied = await copyInstallationId(value);
        button.disabled = false;

        if (resetTimer !== null) {
            window.clearTimeout(resetTimer);
        }

        if (copied) {
            button.textContent = 'Скопировано';
            status.textContent = 'Installation ID скопирован в буфер обмена';
        } else {
            button.textContent = 'Не скопировано';
            status.textContent = 'Не удалось скопировать Installation ID';
        }

        resetTimer = window.setTimeout(function () {
            button.textContent = defaultText;
            status.textContent = '';
        }, 1800);
    });
});
