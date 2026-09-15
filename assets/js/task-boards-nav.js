(() => {
    'use strict';

    document.addEventListener('DOMContentLoaded', () => {
        if (!document.querySelector('.tasks') || document.querySelector('[data-shared-task-boards-link]')) {
            return;
        }

        const createButton = document.getElementById('open-create-task');
        const controls = createButton?.parentElement || document.querySelector('.tasks__controls');
        if (!controls) return;

        const link = document.createElement('a');
        link.href = window.wspace?.path ? window.wspace.path('/tasks/boards/') : '/tasks/boards/';
        link.className = 'btn-secondary';
        link.dataset.sharedTaskBoardsLink = 'true';
        link.innerHTML = '<i class="fa fa-users" aria-hidden="true"></i> Общие доски';
        link.style.textDecoration = 'none';
        link.style.display = 'inline-flex';
        link.style.alignItems = 'center';
        link.style.justifyContent = 'center';
        link.style.gap = '7px';

        controls.insertBefore(link, createButton || null);
    });
})();
