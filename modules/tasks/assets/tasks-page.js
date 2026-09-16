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

        if (openBtn && modal) {
            openBtn.addEventListener('click', () => {
                modal.style.display = 'block';
            });
        }

        closeBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                if (modal) modal.style.display = 'none';
            });
        });

        window.addEventListener('click', (event) => {
            if (modal && event.target === modal) modal.style.display = 'none';
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

        root.querySelectorAll('.task-status-toggle').forEach((toggle) => {
            toggle.dataset.previousValue = toggle.value;
            toggle.addEventListener('change', async function () {
                const previous = this.dataset.previousValue || this.defaultValue || 'pending';
                this.disabled = true;
                try {
                    await updateTaskStatus(this.dataset.taskId, this.value);
                    this.dataset.previousValue = this.value;
                    window.location.reload();
                } catch (error) {
                    this.value = previous;
                    this.disabled = false;
                    window.alert(`Не удалось изменить статус: ${error.message}`);
                }
            });
        });

        root.querySelectorAll('.task-complete-toggle').forEach((toggle) => {
            toggle.addEventListener('change', async function () {
                const targetStatus = this.checked ? 'completed' : 'pending';
                this.disabled = true;
                try {
                    await updateTaskStatus(this.dataset.taskId, targetStatus);
                    window.location.reload();
                } catch (error) {
                    this.checked = !this.checked;
                    this.disabled = false;
                    window.alert(`Не удалось изменить задачу: ${error.message}`);
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
                this.disabled = true;
                try {
                    await requestJson(appPath(`/tasks/subtask/${encodeURIComponent(this.dataset.subtaskId)}/toggle`), { method: 'POST' });
                    window.location.reload();
                } catch (error) {
                    this.checked = !this.checked;
                    this.disabled = false;
                    syncSubtaskProgress(this);
                    window.alert(`Не удалось изменить подзадачу: ${error.message}`);
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
