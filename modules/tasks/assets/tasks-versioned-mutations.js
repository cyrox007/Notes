window.wspace.page.register('modules/tasks/assets/tasks-versioned-mutations.js', (pageScope) => {
(() => {
    'use strict';

    const root = document.querySelector('.tasks');
    if (!root || root.dataset.versionedMutationsReady === '1') return;
    root.dataset.versionedMutationsReady = '1';

    const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;

    function csrfToken() {
        return root.querySelector('input[name="csrf_token"]')?.value || '';
    }

    function taskFor(control) {
        return control?.closest('.task-item') || null;
    }

    function taskVersion(task) {
        const version = Number.parseInt(task?.dataset.version || '', 10);
        return Number.isInteger(version) && version > 0 ? version : null;
    }

    function newCommandId(action) {
        const random = globalThis.crypto?.randomUUID
            ? globalThis.crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
        return `web:${action}:${random}`;
    }

    function replaceCommandId(form, action) {
        const input = form?.querySelector('input[name="command_id"]');
        if (input) input.value = newCommandId(action);
    }

    function applyVersion(task, version) {
        const normalized = Number.parseInt(String(version ?? ''), 10);
        if (!task || !Number.isInteger(normalized) || normalized < 1) return;
        task.dataset.version = String(normalized);
        task.querySelectorAll('input[name="expected_version"]').forEach((input) => {
            input.value = String(normalized);
        });
    }

    function toDateTimeLocal(value) {
        if (!value) return '';
        const text = String(value).replace(' ', 'T');
        return text.length >= 16 ? text.slice(0, 16) : text;
    }

    function reconcileTask(task, current) {
        if (!task || !current || typeof current !== 'object') return;
        applyVersion(task, current.version);

        if (typeof current.status === 'string') {
            task.dataset.status = current.status;
            const status = task.querySelector('.task-status-toggle');
            if (status) {
                status.value = current.status;
                status.dataset.previousValue = current.status;
            }
            const completed = task.querySelector('.task-complete-toggle');
            if (completed) completed.checked = current.status === 'completed';
            task.querySelector('.task-title')?.classList.toggle('completed', current.status === 'completed');
        }

        if (typeof current.title === 'string') {
            const title = task.querySelector('.task-title');
            if (title) title.textContent = current.title;
            const input = task.querySelector('.task-edit-form input[name="title"]');
            if (input) input.value = current.title;
        }
        const description = task.querySelector('.task-edit-form textarea[name="description"]');
        if (description) description.value = current.description || '';
        const priority = task.querySelector('.task-edit-form select[name="priority"]');
        if (priority && typeof current.priority === 'string') priority.value = current.priority;
        const editStatus = task.querySelector('.task-edit-form select[name="status"]');
        if (editStatus && typeof current.status === 'string') editStatus.value = current.status;
        const dueDate = task.querySelector('.task-edit-form input[name="due_date"]');
        if (dueDate) dueDate.value = toDateTimeLocal(current.due_date);

        const archiveForm = task.querySelector('.task-archive-form');
        const archivedInput = archiveForm?.querySelector('input[name="archived"]');
        const archived = Boolean(current.archived_at);
        if (archivedInput) archivedInput.value = archived ? '0' : '1';
        task.querySelectorAll('.task-status-toggle, .task-complete-toggle').forEach((control) => {
            control.disabled = archived;
        });
    }

    function saveState(task, state) {
        if (!task) return;
        task.dataset.saveState = state;
        if (state === 'saved') {
            pageScope.setTimeout(() => {
                if (task.dataset.saveState === 'saved') delete task.dataset.saveState;
            }, 900);
        }
    }

    async function requestMutation(url, body) {
        const params = body instanceof URLSearchParams ? body : new URLSearchParams(body);
        if (!params.has('csrf_token')) params.set('csrf_token', csrfToken());

        const response = await pageScope.fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: params.toString()
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch (_) {
            payload = { success: false, message: 'Сервер вернул некорректный ответ' };
        }

        if (!response.ok || payload?.success !== true) {
            const error = new Error(payload?.message || payload?.error || `HTTP ${response.status}`);
            error.status = response.status;
            error.payload = payload;
            throw error;
        }
        return payload;
    }

    function handleMutationError(task, error, actionLabel) {
        if (error?.status === 409 && error?.payload?.error === 'version_conflict') {
            reconcileTask(task, error.payload.current);
            saveState(task, 'error');
            window.alert(error.payload.message || 'Задача уже изменена в другой вкладке. Показано актуальное состояние.');
            return true;
        }
        saveState(task, 'error');
        window.alert(`${actionLabel}: ${error?.message || 'неизвестная ошибка'}`);
        return false;
    }

    function statusBody(task, status, action) {
        const version = taskVersion(task);
        if (version === null) throw new Error('Не удалось определить версию задачи');
        return new URLSearchParams({
            status,
            expected_version: String(version),
            command_id: newCommandId(action)
        });
    }

    async function changeStatus(control, targetStatus, previousStatus) {
        const task = taskFor(control);
        if (!task) return;

        control.disabled = true;
        saveState(task, 'saving');
        try {
            const payload = await requestMutation(
                appPath(`/tasks/${encodeURIComponent(task.dataset.taskId)}/update`),
                statusBody(task, targetStatus, 'task-status')
            );
            reconcileTask(task, payload.current || { status: targetStatus, version: payload.version });
            root.dispatchEvent(new CustomEvent('tasks:status-change', {
                detail: { taskUid: task.dataset.taskId, nextStatus: targetStatus, previousStatus }
            }));
            saveState(task, 'saved');
        } catch (error) {
            reconcileTask(task, {
                status: previousStatus,
                version: taskVersion(task)
            });
            handleMutationError(task, error, 'Не удалось изменить задачу');
        } finally {
            control.disabled = false;
        }
    }

    root.addEventListener('change', (event) => {
        const control = event.target instanceof Element ? event.target : null;
        if (!control) return;

        const status = control.closest('.task-status-toggle');
        if (status && root.contains(status)) {
            event.stopImmediatePropagation();
            const previous = status.dataset.previousValue || status.defaultValue || 'pending';
            const next = status.value;
            if (next === previous) return;
            status.dataset.previousValue = previous;
            void changeStatus(status, next, previous);
            return;
        }

        const completed = control.closest('.task-complete-toggle');
        if (completed && root.contains(completed)) {
            event.stopImmediatePropagation();
            const task = taskFor(completed);
            const previous = task?.dataset.status || (completed.checked ? 'pending' : 'completed');
            const next = completed.checked ? 'completed' : 'pending';
            if (next === previous) return;
            void changeStatus(completed, next, previous);
        }
    }, true);

    root.addEventListener('submit', (event) => {
        const form = event.target instanceof HTMLFormElement ? event.target : null;
        if (!form || !root.contains(form)) return;

        const editForm = form.closest('.task-edit-form');
        const archiveForm = form.closest('.task-archive-form');
        const deleteForm = form.closest('.task-delete-form');
        if (!editForm && !archiveForm && !deleteForm) return;

        event.preventDefault();
        event.stopImmediatePropagation();
        const task = taskFor(form);
        if (!task) return;

        if (deleteForm && !window.confirm('Вы уверены, что хотите удалить эту задачу?')) return;

        const action = deleteForm ? 'task-delete' : archiveForm ? 'task-archive' : 'task-edit';
        const endpoint = deleteForm
            ? `/tasks/${encodeURIComponent(task.dataset.taskId)}/delete`
            : `/tasks/${encodeURIComponent(task.dataset.taskId)}/update`;
        const params = new URLSearchParams(new FormData(form));
        params.set('expected_version', String(taskVersion(task) || form.querySelector('input[name="expected_version"]')?.value || ''));

        saveState(task, 'saving');
        form.querySelectorAll('button, input, select, textarea').forEach((control) => {
            if (!control.matches('input[type="hidden"]')) control.dataset.wasDisabled = control.disabled ? '1' : '0';
            if (!control.matches('input[type="hidden"]')) control.disabled = true;
        });

        void requestMutation(appPath(endpoint), params).then((payload) => {
            if (deleteForm) {
                task.remove();
                return;
            }
            reconcileTask(task, payload.current || { version: payload.version });
            saveState(task, 'saved');
            if (archiveForm) {
                task.remove();
                return;
            }
            const panel = task.querySelector('.task-edit-panel');
            if (panel) panel.hidden = true;
            replaceCommandId(form, action);
        }).catch((error) => {
            const conflict = handleMutationError(task, error, 'Не удалось сохранить задачу');
            if (conflict) {
                replaceCommandId(form, action);
                const expected = form.querySelector('input[name="expected_version"]');
                if (expected) expected.value = task.dataset.version || expected.value;
            }
        }).finally(() => {
            if (!task.isConnected) return;
            form.querySelectorAll('button, input, select, textarea').forEach((control) => {
                if (control.matches('input[type="hidden"]')) return;
                control.disabled = control.dataset.wasDisabled === '1';
                delete control.dataset.wasDisabled;
            });
        });
    }, true);
})();
});
