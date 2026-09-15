<div class="task-item task-priority-{$task.priority|escape}" data-task-id="{$task.uid|escape}" data-status="{$task.status|escape}">
    <div class="task-header">
        <div class="task-title-section">
            <input type="checkbox"
                   class="task-complete-toggle"
                   {if $task.status == 'completed'}checked{/if}
                   data-task-id="{$task.uid|escape}"
                   title="Отметить как выполненную">
            <h3 class="task-title {if $task.status == 'completed'}completed{/if}">{$task.title|escape}</h3>
            <span class="task-priority-badge" style="background-color: {$task.priority_color|escape};">
                {$task.priority|escape}
            </span>
        </div>

        <div class="task-actions">
            <select class="task-status-toggle" data-task-id="{$task.uid|escape}" aria-label="Статус задачи">
                <option value="pending" {if $task.status == 'pending'}selected{/if}>Ожидает</option>
                <option value="in_progress" {if $task.status == 'in_progress'}selected{/if}>В процессе</option>
                <option value="completed" {if $task.status == 'completed'}selected{/if}>Завершена</option>
                <option value="cancelled" {if $task.status == 'cancelled'}selected{/if}>Отменена</option>
            </select>

            <button type="button" class="btn-icon edit-task" data-task-id="{$task.uid|escape}" title="Редактировать">
                <i class="fa fa-edit" aria-hidden="true"></i>
            </button>
            <form action="{route_path name='delete_task' uid=$task.uid}" method="post" onsubmit="return confirm('Вы уверены, что хотите удалить эту задачу?')">
                {csrf_token}
                <button type="submit" class="btn-icon delete-task" title="Удалить">
                    <i class="fa fa-trash" aria-hidden="true"></i>
                </button>
            </form>
        </div>
    </div>

    {if $task.description}
        <div class="task-description">
            <p>{$task.description|escape|truncate:200}</p>
        </div>
    {/if}

    <div class="task-meta">
        {if $task.due_date}
            <div class="task-due-date {if $task.is_overdue}overdue{/if}">
                <i class="fa fa-calendar" aria-hidden="true"></i>
                <span>
                    {if $task.is_overdue}Просрочено:{else}Срок:{/if}
                    {$task.due_date|date_format:"%d.%m.%Y %H:%M"}
                </span>
            </div>
        {/if}

        {if $task.categories}
            <div class="task-categories">
                {foreach $task.categories as $cat}
                    <span class="category-badge" style="background-color: {$cat.color|escape};">
                        <i class="fa {$cat.icon|escape}" aria-hidden="true"></i>
                        {$cat.name|escape}
                        <button type="button"
                                class="detach-category-btn"
                                data-task-id="{$task.uid|escape}"
                                data-category-id="{$cat.id}"
                                title="Убрать категорию"
                                aria-label="Убрать категорию">×</button>
                    </span>
                {/foreach}
            </div>
        {/if}

        <div class="task-info">
            <span class="task-created">Создано: {$task.created_at|date_format:"%d.%m.%Y %H:%M"}</span>
            <span class="task-status-label">{$task.status_label|escape}</span>
        </div>
    </div>

    <div class="task-category-attach">
        <select class="task-category-select" data-task-id="{$task.uid|escape}" aria-label="Добавить категорию">
            <option value="">Выберите категорию</option>
            {foreach $categories as $availableCategory}
                <option value="{$availableCategory.id}">{$availableCategory.name|escape}</option>
            {/foreach}
        </select>
        <button type="button" class="btn-sm attach-category-btn" data-task-id="{$task.uid|escape}">Добавить категорию</button>
    </div>

    <div class="task-subtasks" data-subtask-total="{$task.subtasks|count}">
        <div class="subtasks-header">
            <span class="task-subtasks__label">Подзадачи (<span class="task-subtasks__percent">{$task.completion_percentage}</span>%)</span>
            <button type="button" class="btn-sm add-subtask-btn" data-task-id="{$task.uid|escape}">+ Добавить</button>
        </div>
        <div class="task-subtasks__progress" role="progressbar" aria-label="Прогресс подзадач" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$task.completion_percentage}">
            <span class="task-subtasks__progress-bar" style="width: {$task.completion_percentage}%"></span>
        </div>
        <ul class="subtasks-list">
            {foreach $task.subtasks as $subtask}
                <li class="subtask-item {if $subtask.is_completed}completed{/if}" data-subtask-id="{$subtask.id}">
                    <input type="checkbox"
                           class="subtask-toggle"
                           {if $subtask.is_completed}checked{/if}
                           data-subtask-id="{$subtask.id}"
                           aria-label="Статус подзадачи">
                    <span class="subtask-title">{$subtask.title|escape}</span>
                    <button type="button" class="btn-icon delete-subtask" data-subtask-id="{$subtask.id}" title="Удалить подзадачу">
                        <i class="fa fa-times" aria-hidden="true"></i>
                    </button>
                </li>
            {/foreach}
        </ul>
    </div>

    <div class="task-edit-panel" data-task-edit-panel="{$task.uid|escape}" hidden>
        <form action="{route_path name='update_task' uid=$task.uid}" method="post" class="task-edit-form">
            {csrf_token}
            <div class="form-group">
                <label>Название</label>
                <input type="text" name="title" maxlength="255" required value="{$task.title|escape}">
            </div>
            <div class="form-group">
                <label>Описание</label>
                <textarea name="description" rows="4" maxlength="10000">{$task.description|default:''|escape}</textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Статус</label>
                    <select name="status">
                        <option value="pending" {if $task.status == 'pending'}selected{/if}>Ожидает</option>
                        <option value="in_progress" {if $task.status == 'in_progress'}selected{/if}>В процессе</option>
                        <option value="completed" {if $task.status == 'completed'}selected{/if}>Завершена</option>
                        <option value="cancelled" {if $task.status == 'cancelled'}selected{/if}>Отменена</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Приоритет</label>
                    <select name="priority">
                        <option value="low" {if $task.priority == 'low'}selected{/if}>Низкий</option>
                        <option value="medium" {if $task.priority == 'medium'}selected{/if}>Средний</option>
                        <option value="high" {if $task.priority == 'high'}selected{/if}>Высокий</option>
                        <option value="urgent" {if $task.priority == 'urgent'}selected{/if}>Срочный</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Срок выполнения</label>
                    <input type="datetime-local" name="due_date" value="{if $task.due_date}{$task.due_date|date_format:'%Y-%m-%dT%H:%M'}{/if}">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Сохранить</button>
                <button type="button" class="btn-secondary close-task-edit" data-task-id="{$task.uid|escape}">Отмена</button>
            </div>
        </form>
    </div>
</div>
