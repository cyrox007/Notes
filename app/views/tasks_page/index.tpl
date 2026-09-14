{extends file='core/base.tpl'}
{block name=title}
    Ежедневник - Задачи
{/block}
{block name=body}
<section class="content-header">
    <h1>Ежедневник</h1>
</section>

<section class="tasks">
    <div class="tasks__stats">
        <div class="stat-card stat-pending">
            <span class="stat-value">{$stats.pending}</span>
            <span class="stat-label">Ожидает</span>
        </div>
        <div class="stat-card stat-in_progress">
            <span class="stat-value">{$stats.in_progress}</span>
            <span class="stat-label">В процессе</span>
        </div>
        <div class="stat-card stat-completed">
            <span class="stat-value">{$stats.completed}</span>
            <span class="stat-label">Завершено</span>
        </div>
        <div class="stat-card stat-overdue">
            <span class="stat-value">{$stats.overdue}</span>
            <span class="stat-label">Просрочено</span>
        </div>
    </div>

    <div class="tasks__controls">
        <div class="tasks__filters">
            <a href="?filter=all" class="filter-btn {if $currentFilter == 'all'}active{/if}">Все</a>
            <a href="?filter=today" class="filter-btn {if $currentFilter == 'today'}active{/if}">Сегодня</a>
            <a href="?filter=week" class="filter-btn {if $currentFilter == 'week'}active{/if}">Неделя</a>
            <a href="?filter=pending" class="filter-btn {if $currentFilter == 'pending'}active{/if}">Ожидают</a>
            <a href="?filter=in_progress" class="filter-btn {if $currentFilter == 'in_progress'}active{/if}">В процессе</a>
            <a href="?filter=overdue" class="filter-btn {if $currentFilter == 'overdue'}active{/if}">Просрочены</a>
            <a href="?filter=completed" class="filter-btn {if $currentFilter == 'completed'}active{/if}">Завершены</a>
            <a href="?filter=cancelled" class="filter-btn {if $currentFilter == 'cancelled'}active{/if}">Отменены</a>
        </div>

        <form method="get" action="{route_path name='tasks'}" class="tasks__sort-form">
            <input type="hidden" name="filter" value="{$currentFilter|escape}">
            <label>
                Сортировка
                <select name="sort">
                    <option value="created_at" {if $currentSort == 'created_at'}selected{/if}>По созданию</option>
                    <option value="updated_at" {if $currentSort == 'updated_at'}selected{/if}>По изменению</option>
                    <option value="due_date" {if $currentSort == 'due_date'}selected{/if}>По сроку</option>
                    <option value="priority" {if $currentSort == 'priority'}selected{/if}>По приоритету</option>
                    <option value="status" {if $currentSort == 'status'}selected{/if}>По статусу</option>
                    <option value="title" {if $currentSort == 'title'}selected{/if}>По названию</option>
                </select>
            </label>
            <select name="direction" aria-label="Направление сортировки">
                <option value="desc" {if $currentDirection == 'desc'}selected{/if}>↓</option>
                <option value="asc" {if $currentDirection == 'asc'}selected{/if}>↑</option>
            </select>
            <button type="submit" class="btn-secondary">Применить</button>
        </form>

        <button class="btn-primary" id="open-create-task" type="button">+ Новая задача</button>
    </div>

    <div class="tasks__category-create">
        <form action="{route_path name='create_category'}" method="post" class="category-create-form">
            {csrf_token}
            <label>
                Новая категория
                <input type="text" name="name" maxlength="120" required placeholder="Например: Проект">
            </label>
            <label>
                Цвет
                <input type="color" name="color" value="#3498db">
            </label>
            <label>
                Иконка
                <select name="icon">
                    <option value="fa-folder">Папка</option>
                    <option value="fa-briefcase">Работа</option>
                    <option value="fa-star">Важное</option>
                    <option value="fa-users">Команда</option>
                    <option value="fa-calendar">Сроки</option>
                    <option value="fa-check">Контроль</option>
                </select>
            </label>
            <button type="submit" class="btn-secondary">Создать категорию</button>
        </form>
    </div>

    <div id="create-task-modal" class="modal" style="display:none;">
        <div class="modal-content">
            <button class="close-modal" type="button" aria-label="Закрыть">&times;</button>
            <h2>Новая задача</h2>
            <form action="{route_path name='task_create'}" method="post" class="task-form">
                {csrf_token}
                <div class="form-group">
                    <label for="title">Название *</label>
                    <input type="text" id="title" name="title" maxlength="255" required placeholder="Введите название задачи">
                </div>

                <div class="form-group">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" rows="4" maxlength="10000" placeholder="Описание задачи (необязательно)"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="priority">Приоритет</label>
                        <select id="priority" name="priority">
                            <option value="low">Низкий</option>
                            <option value="medium" selected>Средний</option>
                            <option value="high">Высокий</option>
                            <option value="urgent">Срочный</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="due_date">Срок выполнения</label>
                        <input type="datetime-local" id="due_date" name="due_date">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Создать задачу</button>
                    <button type="button" class="btn-secondary close-modal">Отмена</button>
                </div>
            </form>
        </div>
    </div>

    <div class="tasks__list">
        {if $tasks}
            {foreach $tasks as $task}
                {include file="^elements/task_item/index.tpl" task=$task user=$user categories=$categories}
            {/foreach}
        {else}
            <div class="tasks__empty">
                <p>Здесь пока нет задач. Создайте первую задачу!</p>
            </div>
        {/if}
    </div>
</section>

<script>
{literal}
document.addEventListener('DOMContentLoaded', () => {
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
        if (modal && event.target === modal) {
            modal.style.display = 'none';
        }
    });

    async function requestJson(url, options = {}) {
        const headers = new Headers(options.headers || {});
        headers.set('Accept', 'application/json');
        headers.set('X-Requested-With', 'XMLHttpRequest');
        const response = await fetch(url, Object.assign({}, options, { headers }));
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            payload = { success: false, error: 'Сервер вернул некорректный ответ' };
        }
        if (!response.ok || !payload || payload.success !== true) {
            throw new Error(payload?.error || payload?.message || `HTTP ${response.status}`);
        }
        return payload;
    }

    async function updateTaskStatus(taskUid, status) {
        return requestJson(`/tasks/${encodeURIComponent(taskUid)}/update`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams({ status }).toString()
        });
    }

    document.querySelectorAll('.task-status-toggle').forEach((toggle) => {
        toggle.addEventListener('change', async function () {
            const previous = this.dataset.previousValue || this.defaultValue || '';
            this.disabled = true;
            try {
                await updateTaskStatus(this.dataset.taskId, this.value);
                location.reload();
            } catch (error) {
                if (previous) this.value = previous;
                alert(`Не удалось изменить статус: ${error.message}`);
                this.disabled = false;
            }
        });
        toggle.dataset.previousValue = toggle.value;
    });

    document.querySelectorAll('.task-complete-toggle').forEach((toggle) => {
        toggle.addEventListener('change', async function () {
            const targetStatus = this.checked ? 'completed' : 'pending';
            this.disabled = true;
            try {
                await updateTaskStatus(this.dataset.taskId, targetStatus);
                location.reload();
            } catch (error) {
                this.checked = !this.checked;
                this.disabled = false;
                alert(`Не удалось изменить задачу: ${error.message}`);
            }
        });
    });

    document.querySelectorAll('.edit-task').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = document.querySelector(`[data-task-edit-panel="${CSS.escape(button.dataset.taskId)}"]`);
            if (panel) panel.hidden = !panel.hidden;
        });
    });

    document.querySelectorAll('.close-task-edit').forEach((button) => {
        button.addEventListener('click', () => {
            const panel = document.querySelector(`[data-task-edit-panel="${CSS.escape(button.dataset.taskId)}"]`);
            if (panel) panel.hidden = true;
        });
    });

    document.querySelectorAll('.add-subtask-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const title = window.prompt('Название подзадачи:');
            if (title === null || title.trim() === '') return;
            try {
                await requestJson(`/tasks/${encodeURIComponent(button.dataset.taskId)}/subtask`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: new URLSearchParams({ title: title.trim() }).toString()
                });
                location.reload();
            } catch (error) {
                alert(`Не удалось добавить подзадачу: ${error.message}`);
            }
        });
    });

    document.querySelectorAll('.subtask-toggle').forEach((toggle) => {
        toggle.addEventListener('change', async function () {
            this.disabled = true;
            try {
                await requestJson(`/tasks/subtask/${encodeURIComponent(this.dataset.subtaskId)}/toggle`, {
                    method: 'POST'
                });
                location.reload();
            } catch (error) {
                this.checked = !this.checked;
                this.disabled = false;
                alert(`Не удалось изменить подзадачу: ${error.message}`);
            }
        });
    });

    document.querySelectorAll('.delete-subtask').forEach((button) => {
        button.addEventListener('click', async () => {
            if (!confirm('Удалить подзадачу?')) return;
            try {
                await requestJson(`/tasks/subtask/${encodeURIComponent(button.dataset.subtaskId)}/delete`, {
                    method: 'POST'
                });
                location.reload();
            } catch (error) {
                alert(`Не удалось удалить подзадачу: ${error.message}`);
            }
        });
    });

    document.querySelectorAll('.attach-category-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const selector = document.querySelector(`.task-category-select[data-task-id="${CSS.escape(button.dataset.taskId)}"]`);
            const categoryId = selector?.value || '';
            if (!categoryId) return;
            try {
                await requestJson(`/tasks/${encodeURIComponent(button.dataset.taskId)}/category/${encodeURIComponent(categoryId)}`, {
                    method: 'POST'
                });
                location.reload();
            } catch (error) {
                alert(`Не удалось добавить категорию: ${error.message}`);
            }
        });
    });

    document.querySelectorAll('.detach-category-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await requestJson(`/tasks/${encodeURIComponent(button.dataset.taskId)}/category/${encodeURIComponent(button.dataset.categoryId)}`, {
                    method: 'DELETE'
                });
                location.reload();
            } catch (error) {
                alert(`Не удалось убрать категорию: ${error.message}`);
            }
        });
    });
});
{/literal}
</script>
{/block}
