(() => {
    'use strict';

    function boot() {
        const root = document.querySelector('.tasks');
        if (!root || root.dataset.pageBehaviorReady === '1') return;
        root.dataset.pageBehaviorReady = '1';

        const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
        const modal = document.getElementById('create-task-modal');
        const openBtn = document.getElementById('open-create-task');
        const closeBtns = document.querySelectorAll('.close-modal');

        const safeColor = (value) => /^#[0-9a-f]{6}$/i.test(String(value || '')) ? String(value) : '#3498db';
        root.querySelectorAll('[data-task-priority-color]').forEach((node) => {
            node.style.backgroundColor = safeColor(node.dataset.taskPriorityColor);
        });
        root.querySelectorAll('[data-category-color]').forEach((node) => {
            node.style.backgroundColor = safeColor(node.dataset.categoryColor);
        });
        root.querySelectorAll('[data-progress]').forEach((node) => {
            const percent = Math.max(0, Math.min(100, Number(node.dataset.progress || 0)));
            node.style.width = percent + '%';
        });

        if (openBtn && modal) {
            openBtn.addEventListener('click', () => {
                modal.hidden = false;
            });
        }

        closeBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (modal) modal.hidden = true;
            });
        });

        window.addEventListener('click', (event) => {
            if (modal && event.target === modal) modal.hidden = true;
        });

        async function requestJson(url, options = {}) {
            const headers = new Headers(options.headers || {});
            headers.set('Accept', 'application/json');
            headers.set('X-Requested-With', 'XMLHttpRequest');
            const response = await fetch(url, Object.assign({}, options, { headers }));
            let payload = null;
            try {
                payload = await response.json();
            } catch (_) {
                payload = { success: false, error: 'Сервер вернул некорректный ответ' };
            }
            if (!response.ok || !payload || payload.success !== true) {
                throw new Error(payload?.error || payload?.message || `HTTP ${response.status}`);
            }
            return payload;
        }

        async function updateTaskStatus(taskUid, status) {
            return requestJson(appPath(`/tasks/${encodeURIComponent(taskUid)}/update`), {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: new URLSearchParams({ status }).toString()
            });
        }

        function emitTaskStatus(taskUid, nextStatus, previousStatus) {
            if (!taskUid || !nextStatus || nextStatus === previousStatus) return;
            root.dispatchEvent(new CustomEvent('tasks:status-change', {
                detail: { taskUid, nextStatus, previousStatus }
            }));
        }

        function syncSubtaskProgress(toggle) {
            const container = toggle?.closest('.task-subtasks');
            if (!container) return;
            const toggles = [...container.querySelectorAll('.subtask-toggle')];
            const total = toggles.length;
            const completed = toggles.filter((item) => item.checked).length;
            const percent = total > 0 ? Math.round((completed / total) * 100) : 0;
            const percentNode = container.querySelector('.task-subtasks__percent');
            const progress = container.querySelector('.task-subtasks__progress');
            const bar = container.querySelector('.task-subtasks__progress-bar');
            if (percentNode) percentNode.textContent = String(percent);
            if (progress) progress.setAttribute('aria-valuenow', String(percent));
            if (bar) bar.style.width = `${percent}%`;
            toggle.closest('.subtask-item')?.classList.toggle('completed', toggle.checked);
        }

        function taskItemFor(control) {
            return control?.closest('.task-item') || null;
        }

        function markSaveState(control, state) {
            const task = taskItemFor(control);
            if (!task) return;
            task.dataset.saveState = state;
            if (state === 'saved') {
                window.setTimeout(() => {
                    if (task.dataset.saveState === 'saved') delete task.dataset.saveState;
                }, 900);
            }
        }

        root.querySelectorAll('.task-status-toggle').forEach((toggle) => {
            toggle.dataset.previousValue = toggle.value;
            toggle.addEventListener('change', async function () {
                const previous = this.dataset.previousValue || this.defaultValue || 'pending';
                const next = this.value;
                if (next === previous) return;

                const uiAlreadySynced = this.dataset.uiSynced === '1';
                delete this.dataset.uiSynced;
                if (!uiAlreadySynced) emitTaskStatus(this.dataset.taskId, next, previous);

                this.disabled = true;
                markSaveState(this, 'saving');
                try {
                    await updateTaskStatus(this.dataset.taskId, next);
                    this.dataset.previousValue = next;
                    markSaveState(this, 'saved');
                } catch (error) {
                    this.value = previous;
                    this.dataset.previousValue = previous;
                    emitTaskStatus(this.dataset.taskId, previous, next);
                    markSaveState(this, 'error');
                    window.alert(`Не удалось изменить статус: ${error.message}`);
                } finally {
                    this.disabled = false;
                }
            });
        });

        root.querySelectorAll('.task-complete-toggle').forEach((toggle) => {
            toggle.addEventListener('change', async function () {
                const task = taskItemFor(this);
                const statusSelect = task?.querySelector('.task-status-toggle');
                const previous = task?.dataset.status || statusSelect?.value || (this.checked ? 'pending' : 'completed');
                const targetStatus = this.checked ? 'completed' : 'pending';
                if (targetStatus === previous) return;

                emitTaskStatus(this.dataset.taskId, targetStatus, previous);
                this.disabled = true;
                markSaveState(this, 'saving');
                try {
                    await updateTaskStatus(this.dataset.taskId, targetStatus);
                    if (statusSelect) statusSelect.dataset.previousValue = targetStatus;
                    markSaveState(this, 'saved');
                } catch (error) {
                    this.checked = previous === 'completed';
                    if (statusSelect) statusSelect.dataset.previousValue = previous;
                    emitTaskStatus(this.dataset.taskId, previous, targetStatus);
                    markSaveState(this, 'error');
                    window.alert(`Не удалось изменить задачу: ${error.message}`);
                } finally {
                    this.disabled = false;
                }
            });
        });

        root.querySelectorAll('.edit-task').forEach((button) => {
            button.addEventListener('click', () => {
                const panel = document.querySelector(`[data-task-edit-panel="${CSS.escape(button.dataset.taskId)}"]`);
                if (panel) panel.hidden = !panel.hidden;
            });
        });

        root.querySelectorAll('.close-task-edit').forEach((button) => {
            button.addEventListener('click', () => {
                const panel = document.querySelector(`[data-task-edit-panel="${CSS.escape(button.dataset.taskId)}"]`);
                if (panel) panel.hidden = true;
            });
        });

        root.querySelectorAll('.add-subtask-btn').forEach((button) => {
            button.addEventListener('click', async () => {
                const title = window.prompt('Название подзадачи:');
                if (title === null || title.trim() === '') return;
                try {
                    await requestJson(appPath(`/tasks/${encodeURIComponent(button.dataset.taskId)}/subtask`), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({ title: title.trim() }).toString()
                    });
                    window.location.reload();
                } catch (error) {
                    window.alert(`Не удалось добавить подзадачу: ${error.message}`);
                }
            });
        });

        root.querySelectorAll('.subtask-toggle').forEach((toggle) => {
            toggle.addEventListener('change', async function () {
                const previous = !this.checked;
                this.disabled = true;
                syncSubtaskProgress(this);
                markSaveState(this, 'saving');
                try {
                    await requestJson(appPath(`/tasks/subtask/${encodeURIComponent(this.dataset.subtaskId)}/toggle`), { method: 'POST' });
                    markSaveState(this, 'saved');
                } catch (error) {
                    this.checked = previous;
                    syncSubtaskProgress(this);
                    markSaveState(this, 'error');
                    window.alert(`Не удалось изменить подзадачу: ${error.message}`);
                } finally {
                    this.disabled = false;
                }
            });
        });

        root.querySelectorAll('.delete-subtask').forEach((button) => {
            button.addEventListener('click', async () => {
                if (!window.confirm('Удалить подзадачу?')) return;
                try {
                    await requestJson(appPath(`/tasks/subtask/${encodeURIComponent(button.dataset.subtaskId)}/delete`), { method: 'POST' });
                    window.location.reload();
                } catch (error) {
                    window.alert(`Не удалось удалить подзадачу: ${error.message}`);
                }
            });
        });

        root.querySelectorAll('.attach-category-btn').forEach((button) => {
            button.addEventListener('click', async () => {
                const selector = document.querySelector(`.task-category-select[data-task-id="${CSS.escape(button.dataset.taskId)}"]`);
                const categoryId = selector?.value || '';
                if (!categoryId) return;
                try {
                    await requestJson(appPath(`/tasks/${encodeURIComponent(button.dataset.taskId)}/category/${encodeURIComponent(categoryId)}`), { method: 'POST' });
                    window.location.reload();
                } catch (error) {
                    window.alert(`Не удалось добавить категорию: ${error.message}`);
                }
            });
        });

        root.querySelectorAll('.detach-category-btn').forEach((button) => {
            button.addEventListener('click', async () => {
                try {
                    await requestJson(appPath(`/tasks/${encodeURIComponent(button.dataset.taskId)}/category/${encodeURIComponent(button.dataset.categoryId)}`), { method: 'DELETE' });
                    window.location.reload();
                } catch (error) {
                    window.alert(`Не удалось убрать категорию: ${error.message}`);
                }
            });
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
