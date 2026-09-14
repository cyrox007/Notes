(() => {
    'use strict';

    const root = document.getElementById('file-manager-quota');
    if (!root) {
        return;
    }

    const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;

    if (!document.querySelector('link[data-file-manager-quota-style]')) {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = appPath('/assets/css/file_manager/quota.css');
        stylesheet.dataset.fileManagerQuotaStyle = '1';
        document.head.appendChild(stylesheet);
    }

    const endpoint = root.dataset.url || '';
    const usedNode = root.querySelector('[data-quota-used]');
    const quotaNode = root.querySelector('[data-quota-total]');
    const remainingNode = root.querySelector('[data-quota-remaining]');
    const bar = root.querySelector('[data-quota-bar]');
    const status = root.querySelector('[data-quota-status]');

    function formatBytes(bytes) {
        const value = Math.max(0, Number(bytes) || 0);
        const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        if (value === 0) return '0 Б';
        const index = Math.min(units.length - 1, Math.floor(Math.log(value) / Math.log(1024)));
        const amount = value / Math.pow(1024, index);
        const digits = index === 0 || amount >= 100 ? 0 : amount >= 10 ? 1 : 2;
        return `${amount.toFixed(digits)} ${units[index]}`;
    }

    function showError() {
        root.classList.remove('file-manager__quota--ready');
        root.classList.add('file-manager__quota--error');
        if (status) status.textContent = 'Данные хранилища временно недоступны';
    }

    if (!endpoint) {
        showError();
        return;
    }

    fetch(endpoint, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
    })
        .then(async (response) => {
            const data = await response.json();
            if (!response.ok || !data.success || !data.storage) {
                throw new Error(data.message || `HTTP ${response.status}`);
            }
            return data.storage;
        })
        .then((storage) => {
            if (usedNode) usedNode.textContent = formatBytes(storage.used_bytes);
            if (quotaNode) quotaNode.textContent = formatBytes(storage.quota_bytes);
            if (remainingNode) remainingNode.textContent = formatBytes(storage.remaining_bytes);
            if (bar) {
                const percent = Math.max(0, Math.min(100, Number(storage.percent) || 0));
                bar.style.width = `${percent}%`;
                bar.parentElement?.setAttribute('aria-valuenow', String(Math.round(percent)));
            }
            if (status) status.textContent = `${Number(storage.percent || 0).toFixed(1)}% занято`;
            root.classList.remove('file-manager__quota--error');
            root.classList.add('file-manager__quota--ready');
        })
        .catch((error) => {
            console.error('File Manager quota load failed', error);
            showError();
        });
})();
