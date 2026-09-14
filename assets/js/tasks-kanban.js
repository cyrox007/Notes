(() => {
    const STATUS_META = [
        ['pending', 'Новые', 'Не начато'],
        ['in_progress', 'В работе', 'Сейчас в процессе'],
        ['completed', 'Готово', 'Завершённые задачи'],
        ['cancelled', 'Отменено', 'Снято с работы'],
    ];

    function boot() {
        const root = document.querySelector('.tasks');
        const list = root?.querySelector('.tasks__list');
        const controls = root?.querySelector('.tasks__controls');
        if (!root || !list || !controls || root.dataset.kanbanReady === '1') return;
        root.dataset.kanbanReady = '1';

        const tasks = [...list.querySelectorAll('.task-item')];
        const empty = list.querySelector('.tasks__empty');
        const board = document.createElement('div');
        board.className = 'tasks-board';
        board.setAttribute('aria-label', 'Доска задач');

        const columns = new Map();
        for (const [status, title, hint] of STATUS_META) {
            const column = document.createElement('section');
            column.className = `tasks-board__column tasks-board__column--${status}`;
            column.dataset.status = status;
            column.innerHTML = `
                <header class="tasks-board__column-header">
                    <div>
                        <div class="tasks-board__column-title-row">
                            <span class="tasks-board__dot" aria-hidden="true"></span>
                            <h2>${title}</h2>
                            <span class="tasks-board__count" aria-label="Количество задач">0</span>
                        </div>
                        <p>${hint}</p>
                    </div>
                    <button type="button" class="tasks-board__quick-add" data-status="${status}" aria-label="Добавить задачу в колонку ${title}">+</button>
                </header>
                <div class="tasks-board__dropzone" data-status="${status}" aria-label="${title}"></div>
            `;
            board.appendChild(column);
            columns.set(status, column.querySelector('.tasks-board__dropzone'));
        }

        list.insertAdjacentElement('afterend', board);

        const switcher = document.createElement('div');
        switcher.className = 'tasks-view-switch';
        switcher.setAttribute('aria-label', 'Представление задач');
        switcher.innerHTML = `
            <button type="button" class="tasks-view-switch__button" data-view="board" aria-pressed="false">
                <i class="fa fa-columns" aria-hidden="true"></i> Доска
            </button>
            <button type="button" class="tasks-view-switch__button" data-view="list" aria-pressed="false">
                <i class="fa fa-list" aria-hidden="true"></i> Список
            </button>
        `;
        controls.prepend(switcher);

        const originalOrder = new Map(tasks.map((task, index) => [task, index]));
        let activeView = localStorage.getItem('workspace.tasks.view') === 'list' ? 'list' : 'board';

        function statusOf(task) {
            return task.querySelector('.task-status-toggle')?.value || 'pending';
        }

        function updateCounts() {
            for (const [status, zone] of columns) {
                const count = zone.querySelectorAll(':scope > .task-item').length;
                const badge = zone.closest('.tasks-board__column')?.querySelector('.tasks-board__count');
                if (badge) badge.textContent = String(count);
            }
        }

        function moveTaskToBoard(task) {
            const zone = columns.get(statusOf(task)) || columns.get('pending');
            if (zone && task.parentElement !== zone) zone.appendChild(task);
        }

        function renderView(view) {
            activeView = view === 'list' ? 'list' : 'board';
            localStorage.setItem('workspace.tasks.view', activeView);
            const isBoard = activeView === 'board';
            board.hidden = !isBoard;
            list.hidden = isBoard;
            switcher.querySelectorAll('[data-view]').forEach((button) => {
                const active = button.dataset.view === activeView;
                button.classList.toggle('active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });

            if (isBoard) {
                empty?.removeAttribute('hidden');
                for (const task of tasks) moveTaskToBoard(task);
                updateCounts();
            } else {
                tasks.sort((a, b) => (originalOrder.get(a) ?? 0) - (originalOrder.get(b) ?? 0));
                for (const task of tasks) list.appendChild(task);
            }
        }

        switcher.addEventListener('click', (event) => {
            const button = event.target.closest('[data-view]');
            if (button) renderView(button.dataset.view);
        });

        // Existing status API remains the single source of truth. Dragging is started
        // from an explicit handle so editing controls never accidentally cancel a move.
        for (const task of tasks) {
            task.draggable = false;
            const header = task.querySelector('.task-header');
            if (header && !header.querySelector('.tasks-board__drag-handle')) {
                const handle = document.createElement('span');
                handle.className = 'tasks-board__drag-handle';
                handle.draggable = true;
                handle.title = 'Перетащить задачу';
                handle.setAttribute('aria-label', 'Перетащить задачу');
                handle.innerHTML = '<i class="fa fa-bars" aria-hidden="true"></i>';
                header.prepend(handle);
            }

            const statusLabel = task.querySelector('.task-status-label');
            if (statusLabel) {
                new MutationObserver(() => {
                    if (activeView === 'board') {
                        moveTaskToBoard(task);
                        task.classList.remove('task-item--status-pending');
                        updateCounts();
                    }
                }).observe(statusLabel, { childList: true, subtree: true, characterData: true });
            }

            task.addEventListener('dragstart', (event) => {
                if (!event.target.closest('.tasks-board__drag-handle')) {
                    event.preventDefault();
                    return;
                }
                task.classList.add('task-item--dragging');
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', task.dataset.taskId || '');
            });
            task.addEventListener('dragend', () => {
                task.classList.remove('task-item--dragging', 'task-item--status-pending');
                board.querySelectorAll('.tasks-board__dropzone--active').forEach((zone) => zone.classList.remove('tasks-board__dropzone--active'));
            });
        }

        for (const [status, zone] of columns) {
            zone.addEventListener('dragover', (event) => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                zone.classList.add('tasks-board__dropzone--active');
            });
            zone.addEventListener('dragleave', () => zone.classList.remove('tasks-board__dropzone--active'));
            zone.addEventListener('drop', (event) => {
                event.preventDefault();
                zone.classList.remove('tasks-board__dropzone--active');
                const uid = event.dataTransfer.getData('text/plain');
                const task = tasks.find((candidate) => candidate.dataset.taskId === uid);
                const select = task?.querySelector('.task-status-toggle');
                if (!task || !select || select.value === status) return;

                task.classList.add('task-item--status-pending');
                select.value = status;
                select.dispatchEvent(new Event('change', { bubbles: true }));

                // Failure path in the shared action restores the old select value.
                // Do not move optimistically; the status-label observer moves only on success.
                window.setTimeout(() => task.classList.remove('task-item--status-pending'), 6000);
            });
        }

        root.querySelectorAll('.tasks-board__quick-add').forEach((button) => {
            button.addEventListener('click', () => {
                const form = document.querySelector('#create-task-modal .task-form');
                const open = document.getElementById('open-create-task');
                if (!form || !open) return;
                let status = form.querySelector('input[name="status"]');
                if (!status) {
                    status = document.createElement('input');
                    status.type = 'hidden';
                    status.name = 'status';
                    form.appendChild(status);
                }
                status.value = button.dataset.status || 'pending';
                open.click();
                document.getElementById('title')?.focus();
            });
        });

        document.getElementById('open-create-task')?.addEventListener('click', (event) => {
            if (event.isTrusted) {
                const status = document.querySelector('#create-task-modal input[name="status"]');
                if (status) status.value = 'pending';
            }
        });

        if (tasks.length === 0) board.classList.add('tasks-board--empty');
        renderView(activeView);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
