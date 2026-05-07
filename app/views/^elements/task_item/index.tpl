<div class="task-item task-priority-{$task.priority}" data-task-id="{$task.uid}">
    <div class="task-header">
        <div class="task-title-section">
            <input type="checkbox" class="task-complete-toggle" 
                   {if $task.status == 'completed'}checked{/if} 
                   data-task-id="{$task.uid}"
                   title="Отметить как выполненную">
            <h3 class="task-title {if $task.status == 'completed'}completed{/if}">{$task.title}</h3>
            <span class="task-priority-badge" style="background-color: {$task.getPriorityColor()}">
                {$task.priority}
            </span>
        </div>
        
        <div class="task-actions">
            <select class="task-status-toggle" data-task-id="{$task.uid}">
                <option value="pending" {if $task.status == 'pending'}selected{/if}>Ожидает</option>
                <option value="in_progress" {if $task.status == 'in_progress'}selected{/if}>В процессе</option>
                <option value="completed" {if $task.status == 'completed'}selected{/if}>Завершена</option>
                <option value="cancelled" {if $task.status == 'cancelled'}selected{/if}>Отменена</option>
            </select>
            
            <a href="#" class="btn-icon edit-task" title="Редактировать">
                <i class="fa fa-edit"></i>
            </a>
            <a href="{route_path name='delete_task' uid=$task.uid}" class="btn-icon delete-task" 
               title="Удалить" onclick="return confirm('Вы уверены, что хотите удалить эту задачу?')">
                <i class="fa fa-trash"></i>
            </a>
        </div>
    </div>
    
    {if $task.description}
    <div class="task-description">
        <p>{$task.description|truncate:200}</p>
    </div>
    {/if}
    
    <div class="task-meta">
        {if $task.due_date}
        <div class="task-due-date {if $task.isOverdue()}overdue{/if}">
            <i class="fa fa-calendar"></i>
            <span>
                {if $task.isOverdue()}
                    Просрочено: {$task.due_date|date_format:"%d.%m.%Y %H:%M"}
                {else}
                    Срок: {$task.due_date|date_format:"%d.%m.%Y %H:%M"}
                {/if}
            </span>
        </div>
        {/if}
        
        {if $task.categories}
        <div class="task-categories">
            {foreach $task.categories as $cat}
            <span class="category-badge" style="background-color: {$cat.color}">
                <i class="fa {$cat.icon}"></i> {$cat.name}
            </span>
            {/foreach}
        </div>
        {/if}
        
        <div class="task-info">
            <span class="task-created">Создано: {$task.created_at|date_format:"%d.%m.%Y %H:%M"}</span>
        </div>
    </div>
    
    <!-- Подзадачи -->
    {if $task.subtasks}
    <div class="task-subtasks">
        <div class="subtasks-header">
            <span>Подзадачи ({$task.getCompletionPercentage()|round:0}%)</span>
            <button class="btn-sm add-subtask-btn" data-task-id="{$task.uid}">+ Добавить</button>
        </div>
        <ul class="subtasks-list">
            {foreach $task.subtasks as $subtask}
            <li class="subtask-item {if $subtask.is_completed}completed{/if}" data-subtask-id="{$subtask.id}">
                <input type="checkbox" class="subtask-toggle" 
                       {if $subtask.is_completed}checked{/if}
                       data-subtask-id="{$subtask.id}">
                <span class="subtask-title">{$subtask.title}</span>
                <button class="btn-icon delete-subtask" data-subtask-id="{$subtask.id}" title="Удалить">
                    <i class="fa fa-times"></i>
                </button>
            </li>
            {/foreach}
        </ul>
    </div>
    {else}
    <div class="task-subtasks-empty">
        <button class="btn-sm add-subtask-btn" data-task-id="{$task.uid}">+ Добавить подзадачу</button>
    </div>
    {/if}
</div>
