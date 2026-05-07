{extends file='core/base.tpl'}
{block name=title}
    Ежедневник - Задачи
{/block}
{block name=body}
<section class="content-header">
    <h1>Ежедневник</h1>
</section>

<section class="tasks">
    <!-- Статистика -->
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

    <!-- Фильтры и создание -->
    <div class="tasks__controls">
        <div class="tasks__filters">
            <a href="?filter=all" class="filter-btn {if $currentFilter == 'all'}active{/if}">Все</a>
            <a href="?filter=today" class="filter-btn {if $currentFilter == 'today'}active{/if}">Сегодня</a>
            <a href="?filter=week" class="filter-btn {if $currentFilter == 'week'}active{/if}">Неделя</a>
            <a href="?filter=pending" class="filter-btn {if $currentFilter == 'pending'}active{/if}">Ожидают</a>
            <a href="?filter=in_progress" class="filter-btn {if $currentFilter == 'in_progress'}active{/if}">В процессе</a>
            <a href="?filter=overdue" class="filter-btn {if $currentFilter == 'overdue'}active{/if}">Просрочены</a>
            <a href="?filter=completed" class="filter-btn {if $currentFilter == 'completed'}active{/if}">Завершены</a>
        </div>
        
        <button class="btn-primary" id="open-create-task">+ Новая задача</button>
    </div>

    <!-- Форма создания задачи (модальное окно) -->
    <div id="create-task-modal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2>Новая задача</h2>
            <form action="{route_path name="task_create"}" method="post" class="task-form">
                {csrf_token}
                <div class="form-group">
                    <label for="title">Название *</label>
                    <input type="text" id="title" name="title" required placeholder="Введите название задачи">
                </div>
                
                <div class="form-group">
                    <label for="description">Описание</label>
                    <textarea id="description" name="description" rows="4" placeholder="Описание задачи (необязательно)"></textarea>
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

    <!-- Список задач -->
    <div class="tasks__list">
        {if $tasks}
            {foreach $tasks as $task}
                {include file="^elements/task_item/index.tpl" task=$task user=$user}
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
    // Модальное окно создания задачи
    const modal = document.getElementById('create-task-modal');
    const openBtn = document.getElementById('open-create-task');
    const closeBtns = document.querySelectorAll('.close-modal');

    if (openBtn) {
        openBtn.addEventListener('click', () => {
            modal.style.display = 'block';
        });
    }

    closeBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            modal.style.display = 'none';
        });
    });

    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });

    // Быстрое переключение статуса задачи
    document.querySelectorAll('.task-status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const taskId = this.dataset.taskId;
            const newStatus = this.value;
            
            fetch(`/tasks/${taskId}/update`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `status=${newStatus}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        });
    });
});
{/literal}
</script>
{/block}
