window.wspace.page.register('modules/tasks/assets/tasks-page.js', (pageScope) => {
(() => {
    'use strict';

    function boot() {
        const root = document.querySelector('.tasks');
        if (!root || root.dataset.pageBehaviorReady === '1') return;
        root.dataset.pageBehaviorReady = '1';

        const filters = root.querySelector('.tasks__filter-panel');
        for (const panel of [filters, root.querySelector('.tasks__category-create')].filter(Boolean)) {
            const filters = panel;
            const summary = panel.querySelector('summary');
            if (!summary) continue;
            let motion = null;
            let expanded = filters.open;
            summary.addEventListener('click', event => {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
                event.preventDefault();
                const start = filters.getBoundingClientRect().height;
                motion?.cancel();
                expanded = motion ? !expanded : !filters.open;
                filters.open = expanded;
                const end = filters.getBoundingClientRect().height;
                filters.open = true;
                filters.style.overflow = 'hidden';
                motion = filters.animate({height:[start+'px',end+'px']}, {duration:240,easing:'cubic-bezier(.2,.8,.2,1)'});
                motion.onfinish = () => {
                    filters.open = expanded;
                    filters.style.overflow = '';
                    motion = null;
                };
            });
        }

        const appPath = (path) => window.wspace?.path ? window.wspace.path(path) : path;
        const modal = document.getElementById('create-task-modal');
        const openBtn = document.getElementById('open-create-task');
        const closeBtns = document.querySelectorAll('[data-task-modal-close]');

        let modalCloseTimer = null;

        function openTaskModal() {
            if (!modal) return;
            if (modalCloseTimer !== null) {
                window.clearTimeout(modalCloseTimer);
                modalCloseTimer = null;
            }
            modal.classList.remove('is-closing');
            modal.hidden = false;
        }

        function closeTaskModal() {
            if (!modal || modal.hidden || modal.classList.contains('is-closing')) return;
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                modal.hidden = true;
                return;
            }
            modal.classList.add('is-closing');
            modalCloseTimer = pageScope.setTimeout(() => {
                modal.hidden = true;
                modal.classList.remove('is-closing');
                modalCloseTimer = null;
            }, 170);
        }

        if (openBtn && modal) {
            openBtn.addEventListener('click', openTaskModal);
        }

        closeBtns.forEach((btn) => {
            btn.addEventListener('click', closeTaskModal);
        });

        pageScope.listen(window, 'click', (event) => {
            if (modal && event.target === modal) closeTaskModal();
        });

        pageScope.listen(document, 'keydown', (event) => {
            if (event.key === 'Escape' && modal && !modal.hidden) closeTaskModal();
        });

        async function requestJson(url, options = {}) {
            const headers = new Headers(options.headers || {});
            headers.set('Accept', 'application/json');
            headers.set('X-Requested-With', 'XMLHttpRequest');
            const response = await pageScope.fetch(url, Object.assign({}, options, { headers }));
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

        async function refreshTaskSection(taskUid, selector) {
            const response = await pageScope.fetch(window.location.href, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const html = await response.text();
            const documentCopy = new DOMParser().parseFromString(html, 'text/html');
            const escapedUid = CSS.escape(taskUid);
            const currentTask = root.querySelector(`.task-item[data-task-id="${escapedUid}"]`);
            const freshTask = documentCopy.querySelector(`.task-item[data-task-id="${escapedUid}"]`);
            const currentSection = currentTask?.querySelector(selector);
            const freshSection = freshTask?.querySelector(selector);
            if (!currentSection || !freshSection) {
                throw new Error('Не удалось обновить карточку задачи');
            }

            currentSection.replaceWith(document.importNode(freshSection, true));
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
            if (percentNode) percentNode.textContent = String(percent);
            if (progress) {
                cancelAnimationFrame(progress._animation);
                const from = progress.value, begun = performance.now();
                const animationLabel = container.querySelector('.task-subtasks__percent');
                const duration = matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 260;
                const frame = now => {
                    const t = duration ? Math.min(1, (now - begun) / duration) : 1;
                    const value = from + (percent - from) * (1 - Math.pow(1 - t, 3));
                    progress.value = value;
                    if (animationLabel) animationLabel.textContent = String(Math.round(value));
                    if (t < 1) progress._animation = requestAnimationFrame(frame);
                };
                frame(begun);
                progress.setAttribute('aria-valuenow', String(percent));
            }
            toggle.closest('.subtask-item')?.classList.toggle('completed', toggle.checked);
        }

        function taskItemFor(control) {
            return control?.closest('.task-item') || null;
        }

        function markTaskSaveState(task, state) {
            if (!task) return;
            task.dataset.saveState = state;
            if (state === 'saved') {
                pageScope.setTimeout(() => {
                    if (task.dataset.saveState === 'saved') delete task.dataset.saveState;
                }, 900);
            }
        }

        function markSaveState(control, state) {
            markTaskSaveState(taskItemFor(control), state);
        }

        root.querySelectorAll('.task-status-toggle').forEach((toggle) => {
            toggle.dataset.nativeTaskChange = '1';
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
            toggle.dataset.nativeTaskChange = '1';
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

        root.addEventListener('click', async (event) => {
            const target = event.target instanceof Element ? event.target : null;
            if (!target) return;

            const addSubtaskButton = target.closest('.add-subtask-btn');
            if (addSubtaskButton && root.contains(addSubtaskButton)) {
                const title = window.prompt('Название подзадачи:');
                if (title === null || title.trim() === '') return;
                const task = taskItemFor(addSubtaskButton);
                addSubtaskButton.disabled = true;
                markTaskSaveState(task, 'saving');
                try {
                    await requestJson(appPath(`/tasks/${encodeURIComponent(addSubtaskButton.dataset.taskId)}/subtask`), {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                        body: new URLSearchParams({ title: title.trim() }).toString()
                    });
                    await refreshTaskSection(addSubtaskButton.dataset.taskId, '.task-subtasks');
                    markTaskSaveState(task, 'saved');
                } catch (error) {
                    markTaskSaveState(task, 'error');
                    addSubtaskButton.disabled = false;
                    window.alert(`Не удалось добавить подзадачу: ${error.message}`);
                }
                return;
            }

            const deleteSubtaskButton = target.closest('.delete-subtask');
            if (deleteSubtaskButton && root.contains(deleteSubtaskButton)) {
                if (!window.confirm('Удалить подзадачу?')) return;
                const task = taskItemFor(deleteSubtaskButton);
                const taskUid = task?.dataset.taskId || '';
                deleteSubtaskButton.disabled = true;
                markTaskSaveState(task, 'saving');
                try {
                    await requestJson(appPath(`/tasks/subtask/${encodeURIComponent(deleteSubtaskButton.dataset.subtaskId)}/delete`), { method: 'POST' });
                    await refreshTaskSection(taskUid, '.task-subtasks');
                    markTaskSaveState(task, 'saved');
                } catch (error) {
                    markTaskSaveState(task, 'error');
                    deleteSubtaskButton.disabled = false;
                    window.alert(`Не удалось удалить подзадачу: ${error.message}`);
                }
                return;
            }

            const attachCategoryButton = target.closest('.attach-category-btn');
            if (attachCategoryButton && root.contains(attachCategoryButton)) {
                const task = taskItemFor(attachCategoryButton);
                const taskUid = attachCategoryButton.dataset.taskId || '';
                const selector = root.querySelector(`.task-category-select[data-task-id="${CSS.escape(taskUid)}"]`);
                const categoryId = selector?.value || '';
                if (!categoryId) return;
                attachCategoryButton.disabled = true;
                markTaskSaveState(task, 'saving');
                try {
                    await requestJson(appPath(`/tasks/${encodeURIComponent(taskUid)}/category/${encodeURIComponent(categoryId)}`), { method: 'POST' });
                    await refreshTaskSection(taskUid, '.task-meta');
                    if (selector) selector.value = '';
                    markTaskSaveState(task, 'saved');
                } catch (error) {
                    markTaskSaveState(task, 'error');
                    window.alert(`Не удалось добавить категорию: ${error.message}`);
                } finally {
                    attachCategoryButton.disabled = !selector?.value;
                }
                return;
            }

            const detachCategoryButton = target.closest('.detach-category-btn');
            if (detachCategoryButton && root.contains(detachCategoryButton)) {
                const task = taskItemFor(detachCategoryButton);
                const taskUid = detachCategoryButton.dataset.taskId || '';
                detachCategoryButton.disabled = true;
                markTaskSaveState(task, 'saving');
                try {
                    await requestJson(appPath(`/tasks/${encodeURIComponent(taskUid)}/category/${encodeURIComponent(detachCategoryButton.dataset.categoryId)}`), { method: 'DELETE' });
                    await refreshTaskSection(taskUid, '.task-meta');
                    markTaskSaveState(task, 'saved');
                } catch (error) {
                    markTaskSaveState(task, 'error');
                    detachCategoryButton.disabled = false;
                    window.alert(`Не удалось убрать категорию: ${error.message}`);
                }
            }
        });

        root.addEventListener('change', async (event) => {
            const target = event.target instanceof Element ? event.target : null;
            const toggle = target?.closest('.subtask-toggle');
            if (!toggle || !root.contains(toggle)) return;

            const previous = !toggle.checked;
            toggle.disabled = true;
            syncSubtaskProgress(toggle);
            markSaveState(toggle, 'saving');
            try {
                await requestJson(appPath(`/tasks/subtask/${encodeURIComponent(toggle.dataset.subtaskId)}/toggle`), { method: 'POST' });
                markSaveState(toggle, 'saved');
            } catch (error) {
                toggle.checked = previous;
                syncSubtaskProgress(toggle);
                markSaveState(toggle, 'error');
                window.alert(`Не удалось изменить подзадачу: ${error.message}`);
            } finally {
                toggle.disabled = false;
            }
        });

        root.querySelectorAll('[data-category-create-open]').forEach(link => {
            link.addEventListener('click', event => {
                const panel = root.querySelector('#task-category-create');
                if (!panel) return;
                event.preventDefault();
                if (!panel.open) panel.querySelector('summary')?.click();
                panel.scrollIntoView({behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'center'});
                panel.querySelector('input[name="name"]')?.focus({preventScroll:true});
            });
        });
        root.querySelectorAll('.task-category-select').forEach(selector => {
            const sync = () => {const button=selector.closest('.task-category-attach')?.querySelector('.attach-category-btn');if(button)button.disabled=!selector.value;};
            selector.addEventListener('change',sync);sync();
        });
    }

    if (document.readyState === 'loading') pageScope.onInit( boot);
    else boot();
})();
});
