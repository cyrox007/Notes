window.wspace.page.register('modules/tasks/assets/task-boards.js', (pageScope) => {
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
        const placeholder = document.createElement('div');
        placeholder.className = 'task-board-drop-placeholder';
        placeholder.setAttribute('aria-hidden', 'true');

        function clearDragState() {
            placeholder.remove();
            root.classList.remove('task-boards-page--dragging');
            root.querySelectorAll('.task-board-column').forEach((column) => {
                column.dataset.dragOver = 'false';
                column.dataset.dropReady = 'false';
            });
            if (dragged) dragged.classList.remove('task-board-card--dragging');
        }

        function createDragPreview(card) {
            const preview = document.createElement('div');
            preview.className = 'task-board-drag-preview';

            const marker = document.createElement('span');
            marker.className = 'task-board-drag-preview__marker';
            marker.innerHTML = '<i class="fa fa-bars" aria-hidden="true"></i>';

            const title = document.createElement('strong');
            title.textContent = card.querySelector('.task-board-card__title strong')?.textContent?.trim() || 'Задача';

            preview.append(marker, title);
            pageScope.append(preview);
            return preview;
        }

        root.querySelectorAll('.task-board-card[draggable="true"]').forEach((card) => {
            card.addEventListener('dragstart', (event) => {
                const transfer = event.dataTransfer;
                if (!transfer) {
                    event.preventDefault();
                    return;
                }

                dragged = card;
                card.classList.add('task-board-card--dragging');
                root.classList.add('task-boards-page--dragging');
                root.querySelectorAll('.task-board-column').forEach((column) => {
                    if (column !== card.closest('.task-board-column')) column.dataset.dropReady = 'true';
                });

                const preview = createDragPreview(card);
                transfer.effectAllowed = 'move';
                transfer.setData('text/plain', card.dataset.taskUid || '');
                transfer.setDragImage(preview, 28, 20);
                pageScope.setTimeout(() => preview.remove(), 0);
            });
            card.addEventListener('dragend', () => {
                clearDragState();
                dragged = null;
            });
        });

        root.querySelectorAll('.task-board-column').forEach((column) => {
            column.addEventListener('dragover', (event) => {
                if (!dragged) return;
                event.preventDefault();
                if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';

                root.querySelectorAll('.task-board-column').forEach((candidate) => {
                    if (candidate !== column) candidate.dataset.dragOver = 'false';
                });
                column.dataset.dragOver = 'true';
                const items = column.querySelector('.task-board-column__items');
                if (items && placeholder.parentElement !== items) {
                    placeholder.textContent = 'Переместить сюда';
                    items.appendChild(placeholder);
                }
            });
            column.addEventListener('dragleave', (event) => {
                if (event.relatedTarget instanceof Node && column.contains(event.relatedTarget)) return;
                const bounds = column.getBoundingClientRect();
                if (event.clientX >= bounds.left && event.clientX < bounds.right
                    && event.clientY >= bounds.top && event.clientY < bounds.bottom) return;
                column.dataset.dragOver = 'false';
                if (placeholder.closest('.task-board-column') === column) placeholder.remove();
            });
            column.addEventListener('drop', async (event) => {
                event.preventDefault();
                if (!dragged) return;

                const card = dragged;
                const status = column.dataset.status || '';
                const url = card.dataset.updateUrl || '';
                const currentColumn = card.closest('.task-board-column');
                clearDragState();

                if (!status || !url || currentColumn?.dataset.status === status) {
                    dragged = null;
                    return;
                }

                const csrfToken = card.querySelector('input[name="csrf_token"]')?.value
                    || root.querySelector('input[name="csrf_token"]')?.value
                    || '';

                const version = card.dataset.version || '';
                if (!/^[1-9][0-9]*$/.test(version)) {
                    dragged = null;
                    window.alert('Версия задачи недоступна. Обновите доску.');
                    return;
                }
                const signature = version + ':' + status;
                if (card.dataset.lastMoveSignature !== signature) {
                    card.dataset.lastMoveSignature = signature;
                    card.dataset.lastMoveCommand = 'board:web:' + crypto.randomUUID();
                }
                card.dataset.moveState = 'saving';
                try {
                    const response = await pageScope.fetch(url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': csrfToken,
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: new URLSearchParams({ status, expected_version: version, command_id: card.dataset.lastMoveCommand }).toString()
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || payload.success !== true) {
                        if (response.status === 409 && payload.error === 'version_conflict') {
                            card.dataset.version = String(payload.current_version);
                            pageScope.refresh();
                        }
                        throw new Error(payload.message || `HTTP ${response.status}`);
                    }

                    card.dataset.version = String(payload.version);
                    card.querySelectorAll('input[name="expected_version"]').forEach(input => {
                        input.value = String(payload.version);
                    });
                    delete card.dataset.lastMoveSignature;
                    delete card.dataset.lastMoveCommand;
                    column.querySelector('.task-board-column__items')?.appendChild(card);
                    const select = card.querySelector('select[name="status"]');
                    if (select) select.value = status;
                    card.dataset.moveState = 'saved';
                    card.classList.add('task-board-card--just-moved');
                    pageScope.setTimeout(() => card.classList.remove('task-board-card--just-moved'), 520);
                    root.querySelectorAll('.task-board-column').forEach(item => {
                        const count = item.querySelectorAll('.task-board-card').length;
                        const pill = item.querySelector('.task-board-pill');
                        if (pill) pill.textContent = String(count);
                    });
                } catch (error) {
                    card.dataset.moveState = 'error';
                    const message = `Не удалось изменить статус: ${error.message}`;
                    if (window.wspace?.feedback?.toast) {
                        window.wspace.feedback.toast(message, 'error');
                    } else {
                        window.alert(message);
                    }
                    pageScope.setTimeout(() => {
                        if (card.dataset.moveState === 'error') delete card.dataset.moveState;
                    }, 1800);
                } finally {
                    dragged = null;
                }
            });
        });
    }

    if (document.readyState === 'loading') pageScope.onInit( boot);
    else boot();
})();
});
