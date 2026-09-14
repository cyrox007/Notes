(function bootstrapUsabilityActions() {
    const feedback = () => window.wspace?.feedback;

    async function requestJson(url, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-Requested-With', 'XMLHttpRequest');
        const response = await fetch(url, Object.assign({}, options, { headers }));
        let payload = null;
        try { payload = await response.json(); } catch (_) {}
        if (!response.ok || !payload || payload.success !== true) {
            throw new Error(payload?.error || payload?.message || `HTTP ${response.status}`);
        }
        return payload;
    }

    function encodedForm(values) {
        return new URLSearchParams(Object.entries(values).map(([key, value]) => [key, String(value)])).toString();
    }

    async function updateTaskStatus(uid, status) {
        return requestJson(`/tasks/${encodeURIComponent(uid)}/update`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: encodedForm({ status })
        });
    }

    function taskItem(element) {
        return element instanceof Element ? element.closest('.task-item') : null;
    }

    function applyTaskStatus(item, status) {
        if (!item) return;
        const completed = status === 'completed';
        const statusSelect = item.querySelector('.task-status-toggle');
        const completeToggle = item.querySelector('.task-complete-toggle');
        const title = item.querySelector('.task-title');
        const label = item.querySelector('.task-status-label');
        const labels = { pending: 'Ожидает', in_progress: 'В процессе', completed: 'Завершена', cancelled: 'Отменена' };
        if (statusSelect) {
            statusSelect.value = status;
            statusSelect.dataset.previousValue = status;
            statusSelect.disabled = false;
        }
        if (completeToggle) {
            completeToggle.checked = completed;
            completeToggle.disabled = false;
        }
        title?.classList.toggle('completed', completed);
        if (label && labels[status]) label.textContent = labels[status];
    }

    async function handleTaskChange(event) {
        const target = event.target;
        if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return false;

        if (target.matches('.task-status-toggle')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const previous = target.dataset.previousValue || target.defaultValue || 'pending';
            const uid = target.dataset.taskId;
            if (!uid) return true;
            target.disabled = true;
            try {
                const result = await updateTaskStatus(uid, target.value);
                applyTaskStatus(taskItem(target), result.status || target.value);
                feedback()?.toast('Статус задачи обновлён', 'success');
            } catch (error) {
                target.value = previous;
                target.disabled = false;
                feedback()?.toast(`Не удалось изменить статус: ${error.message}`, 'error');
            }
            return true;
        }

        if (target.matches('.task-complete-toggle')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const previous = !target.checked;
            const uid = target.dataset.taskId;
            if (!uid) return true;
            target.disabled = true;
            try {
                const result = await updateTaskStatus(uid, target.checked ? 'completed' : 'pending');
                applyTaskStatus(taskItem(target), result.status || (target.checked ? 'completed' : 'pending'));
                feedback()?.toast(target.checked ? 'Задача завершена' : 'Задача возвращена в работу', 'success');
            } catch (error) {
                target.checked = previous;
                target.disabled = false;
                feedback()?.toast(`Не удалось изменить задачу: ${error.message}`, 'error');
            }
            return true;
        }

        if (target.matches('.subtask-toggle')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const previous = !target.checked;
            const id = target.dataset.subtaskId;
            if (!id) return true;
            target.disabled = true;
            try {
                const result = await requestJson(`/tasks/subtask/${encodeURIComponent(id)}/toggle`, { method: 'POST' });
                target.checked = Number(result.is_completed) === 1;
                target.closest('.subtask-item')?.classList.toggle('completed', target.checked);
                target.disabled = false;
                feedback()?.toast('Подзадача обновлена', 'success');
            } catch (error) {
                target.checked = previous;
                target.disabled = false;
                feedback()?.toast(`Не удалось изменить подзадачу: ${error.message}`, 'error');
            }
            return true;
        }

        return false;
    }

    function createSubtaskNode(subtask) {
        const item = document.createElement('li');
        item.className = 'subtask-item';
        item.dataset.subtaskId = String(subtask.id);

        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'subtask-toggle';
        checkbox.dataset.subtaskId = String(subtask.id);
        checkbox.setAttribute('aria-label', 'Статус подзадачи');

        const title = document.createElement('span');
        title.className = 'subtask-title';
        title.textContent = String(subtask.title || '');

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn-icon delete-subtask';
        remove.dataset.subtaskId = String(subtask.id);
        remove.title = 'Удалить подзадачу';
        remove.setAttribute('aria-label', 'Удалить подзадачу');
        const icon = document.createElement('i');
        icon.className = 'fa fa-times';
        icon.setAttribute('aria-hidden', 'true');
        remove.appendChild(icon);

        item.append(checkbox, title, remove);
        return item;
    }

    async function handleTaskClick(event) {
        const target = event.target instanceof Element ? event.target : null;
        if (!target) return false;

        const add = target.closest('.add-subtask-btn');
        if (add) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const uid = add.dataset.taskId;
            if (!uid) return true;
            const value = feedback()?.prompt
                ? await feedback().prompt('Название подзадачи:', '', { title: 'Новая подзадача', confirmText: 'Добавить' })
                : window.prompt('Название подзадачи:');
            const title = value === null ? '' : String(value).trim();
            if (!title) return true;
            try {
                const result = await requestJson(`/tasks/${encodeURIComponent(uid)}/subtask`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: encodedForm({ title })
                });
                const item = taskItem(add);
                item?.querySelector('.subtasks-list')?.appendChild(createSubtaskNode(result.subtask));
                feedback()?.toast('Подзадача добавлена', 'success');
            } catch (error) {
                feedback()?.toast(`Не удалось добавить подзадачу: ${error.message}`, 'error');
            }
            return true;
        }

        const remove = target.closest('.delete-subtask');
        if (remove) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const id = remove.dataset.subtaskId;
            if (!id) return true;
            const confirmed = feedback()?.confirm
                ? await feedback().confirm('Удалить подзадачу?', { danger: true, confirmText: 'Удалить' })
                : window.confirm('Удалить подзадачу?');
            if (!confirmed) return true;
            try {
                await requestJson(`/tasks/subtask/${encodeURIComponent(id)}/delete`, { method: 'POST' });
                remove.closest('.subtask-item')?.remove();
                feedback()?.toast('Подзадача удалена', 'success');
            } catch (error) {
                feedback()?.toast(`Не удалось удалить подзадачу: ${error.message}`, 'error');
            }
            return true;
        }

        const fileDelete = target.closest('.file-manager .btn-delete');
        if (fileDelete) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const item = fileDelete.closest('.file-manager__item');
            const id = item?.dataset.id;
            if (!id) return true;
            const name = item.dataset.name || 'элемент';
            const confirmed = feedback()?.confirm
                ? await feedback().confirm(`Удалить «${name}»?`, { title: 'Удаление', danger: true, confirmText: 'Удалить' })
                : window.confirm(`Удалить «${name}»?`);
            if (!confirmed) return true;
            try {
                await requestJson('/files/delete/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: encodedForm({ id })
                });
                item.remove();
                feedback()?.toast('Удалено', 'success');
                if (!document.querySelector('.file-manager__item')) window.location.reload();
            } catch (error) {
                feedback()?.toast(error.message || 'Ошибка при удалении', 'error');
            }
            return true;
        }

        const renameConfirm = target.closest('#modal-rename .modal-ok');
        if (renameConfirm) {
            event.preventDefault();
            event.stopImmediatePropagation();
            const id = document.getElementById('rename-id')?.value || '';
            const input = document.getElementById('rename-input');
            const name = input?.value.trim() || '';
            if (!id || !name) {
                feedback()?.toast('Введите название', 'error');
                input?.focus();
                return true;
            }
            try {
                await requestJson('/files/rename/', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: encodedForm({ id, name })
                });
                const item = document.querySelector(`.file-manager__item[data-id="${CSS.escape(id)}"]`);
                if (item) {
                    item.dataset.name = name;
                    const extension = item.dataset.extension || '';
                    const label = item.querySelector('.file-manager__item-name');
                    if (label) label.textContent = `${name}${item.dataset.type !== 'folder' && extension ? `.${extension}` : ''}`;
                    item.querySelectorAll('[aria-label]').forEach((node) => {
                        const prefix = node.classList.contains('btn-delete') ? 'Удалить' : node.classList.contains('btn-rename') ? 'Переименовать' : 'Открыть';
                        node.setAttribute('aria-label', `${prefix} ${name}`);
                    });
                }
                document.getElementById('modal-rename')?.classList.remove('show');
                document.body.classList.remove('file-manager-modal-open');
                feedback()?.toast('Переименовано', 'success');
            } catch (error) {
                feedback()?.toast(error.message || 'Ошибка при переименовании', 'error');
            }
            return true;
        }

        return false;
    }

    document.addEventListener('change', (event) => {
        if (event.target instanceof Element && event.target.matches('.task-status-toggle,.task-complete-toggle,.subtask-toggle')) {
            handleTaskChange(event);
        }
    }, true);

    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) return;
        if (event.target.closest('.add-subtask-btn,.delete-subtask,.file-manager .btn-delete,#modal-rename .modal-ok')) {
            handleTaskClick(event);
        }
    }, true);

    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!form.querySelector('.delete-task')) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const confirmed = feedback()?.confirm
            ? await feedback().confirm('Вы уверены, что хотите удалить эту задачу?', { title: 'Удаление задачи', danger: true, confirmText: 'Удалить' })
            : window.confirm('Вы уверены, что хотите удалить эту задачу?');
        if (confirmed) HTMLFormElement.prototype.submit.call(form);
    }, true);
})();
