(() => {
    'use strict';

    function boot() {
        const root = document.querySelector('.task-boards-page');
        if (!root || root.dataset.boardBehaviorReady === '1') return;
        root.dataset.boardBehaviorReady = '1';

        const audience = document.getElementById('task-board-audience');
        const members = document.getElementById('task-board-member-picker');
        const syncAudience = () => {
            if (!audience || !members) return;
            const allActive = audience.value === 'all_active';
            members.hidden = allActive;
            members.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                input.disabled = allActive;
            });
        };
        audience?.addEventListener('change', syncAudience);
        syncAudience();

        let dragged = null;
        root.querySelectorAll('.task-board-card[draggable="true"]').forEach((card) => {
            card.addEventListener('dragstart', () => {
                dragged = card;
                card.dataset.dragging = 'true';
            });
            card.addEventListener('dragend', () => {
                card.dataset.dragging = 'false';
                dragged = null;
                root.querySelectorAll('.task-board-column').forEach((column) => {
                    column.dataset.dragOver = 'false';
                });
            });
        });

        root.querySelectorAll('.task-board-column').forEach((column) => {
            column.addEventListener('dragover', (event) => {
                if (!dragged) return;
                event.preventDefault();
                column.dataset.dragOver = 'true';
            });
            column.addEventListener('dragleave', () => {
                column.dataset.dragOver = 'false';
            });
            column.addEventListener('drop', async (event) => {
                event.preventDefault();
                column.dataset.dragOver = 'false';
                if (!dragged) return;

                const card = dragged;
                const status = column.dataset.status || '';
                const url = card.dataset.updateUrl || '';
                if (!status || !url) return;
                const currentColumn = card.closest('.task-board-column');
                if (currentColumn?.dataset.status === status) return;
                const csrfToken = card.querySelector('input[name="csrf_token"]')?.value
                    || root.querySelector('input[name="csrf_token"]')?.value
                    || '';

                try {
                    const response = await fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': csrfToken,
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams({ status }).toString()
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || payload.success !== true) {
                        throw new Error(payload.message || `HTTP ${response.status}`);
                    }
                    column.querySelector('.task-board-column__items')?.appendChild(card);
                    const select = card.querySelector('select[name="status"]');
                    if (select) select.value = status;
                    window.setTimeout(() => window.location.reload(), 120);
                } catch (error) {
                    window.alert(`Не удалось изменить статус: ${error.message}`);
                }
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
