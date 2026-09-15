{extends file='core/base.tpl'}
{block name=title}Общие доски задач{/block}
{block name=body}
<style>{include file='tasks_page/boards.css'}</style>

<section class="task-boards-page">
    <header class="task-boards-hero">
        <div>
            <span class="admin-page__eyebrow">Совместная работа</span>
            <h1>Общие доски задач</h1>
            <p>Доски для выбранной команды или для всех активных пользователей Workspace.</p>
        </div>
        <div class="task-boards-hero__actions">
            <a class="task-board-button" href="{route_path name='tasks'}"><i class="fa fa-user"></i> Личные задачи</a>
            {if $selectedBoard}
                <a class="task-board-button" href="{route_path name='task_boards'}">Все доски</a>
            {/if}
        </div>
    </header>

    {if $task_boards_flash}
        <div class="task-board-flash {if $task_boards_flash.type == 'error'}task-board-flash--error{/if}" role="status">
            {$task_boards_flash.message|escape}
        </div>
    {/if}

    <section class="task-board-panel">
        <div class="task-board-section-title">
            <div>
                <h2>Доступные доски</h2>
                <div class="task-board-hint">Доски «Все пользователи» автоматически доступны каждому активному аккаунту.</div>
            </div>
        </div>
        {if $boards}
            <div class="task-board-list">
                {foreach $boards as $board}
                    <a class="task-board-tab {if $selectedBoard && $selectedBoard.uid == $board.uid}task-board-tab--active{/if}"
                       href="{route_path name='task_boards'}?board={$board.uid|escape:'url'}">
                        <strong>{$board.name|escape}</strong>
                        <small>{if $board.audience == 'all_active'}Все пользователи{else}{$board.member_count} участн.{/if} · {$board.task_count} задач</small>
                    </a>
                {/foreach}
            </div>
        {else}
            <div class="task-board-empty">Общих досок пока нет.</div>
        {/if}
    </section>

    {if $boardPolicies.can_create_shared_boards}
        <section class="task-board-panel">
            <div class="task-board-section-title">
                <div>
                    <h2>Создать доску</h2>
                    <div class="task-board-hint">Можно открыть доску выбранной группе или всем активным пользователям.</div>
                </div>
            </div>
            <form action="{route_path name='task_board_create'}" method="post" class="task-board-create-grid" id="task-board-create-form">
                {csrf_token}
                <label class="task-board-field">
                    <span>Название</span>
                    <input name="name" type="text" maxlength="160" required placeholder="Например, Команда продукта">
                </label>
                <label class="task-board-field">
                    <span>Кому доступна</span>
                    <select name="audience" id="task-board-audience">
                        <option value="members">Выбранным пользователям</option>
                        <option value="all_active">Всем активным пользователям</option>
                    </select>
                </label>
                <div class="task-board-field task-board-field--wide task-board-members-block" id="task-board-member-picker">
                    <span>Участники</span>
                    <div class="task-board-users">
                        {foreach $activeUsers as $member}
                            {if $member.id != $user.id}
                                <label class="task-board-user">
                                    <input type="checkbox" name="member_ids[]" value="{$member.id}">
                                    <span>
                                        <strong>{$member.firstname|escape} {$member.lastname|escape}</strong>
                                        <small>@{$member.username|escape}</small>
                                    </span>
                                </label>
                            {/if}
                        {/foreach}
                    </div>
                    {if $boardPolicies.max_board_members > 0}
                        <small class="task-board-hint">Лимит роли: до {$boardPolicies.max_board_members} участников вместе с владельцем.</small>
                    {/if}
                </div>
                <div class="task-board-actions-row">
                    <button class="task-board-button task-board-button--primary" type="submit"><i class="fa fa-plus"></i> Создать доску</button>
                </div>
            </form>
        </section>
    {/if}

    {if $selectedBoard}
        <section class="task-board-panel">
            <div class="task-board-summary">
                <div>
                    <span class="admin-page__eyebrow">Активная доска</span>
                    <h2>{$selectedBoard.name|escape}</h2>
                    <div class="task-board-summary__meta">
                        <span class="task-board-pill">{if $selectedBoard.audience == 'all_active'}Все активные пользователи{else}Выбранная команда{/if}</span>
                        <span class="task-board-pill">Владелец: @{$selectedBoard.owner_username|escape}</span>
                        <span class="task-board-pill">Ваш доступ: {$selectedBoard.access_role|escape}</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="task-board-stats">
            <div class="task-board-stat"><strong>{$boardStats.pending}</strong><span>Ожидает</span></div>
            <div class="task-board-stat"><strong>{$boardStats.in_progress}</strong><span>В процессе</span></div>
            <div class="task-board-stat"><strong>{$boardStats.completed}</strong><span>Завершено</span></div>
            <div class="task-board-stat"><strong>{$boardStats.cancelled}</strong><span>Отменено</span></div>
        </section>

        {if $selectedBoard.can_write}
            <section class="task-board-panel">
                <div class="task-board-section-title">
                    <div>
                        <h2>Новая задача на доске</h2>
                        <div class="task-board-hint">Задача сразу видна всем участникам этой доски.</div>
                    </div>
                </div>
                <form action="{route_path name='task_board_task_create' uid=$selectedBoard.uid}" method="post" class="task-board-task-form">
                    {csrf_token}
                    <label class="task-board-field">
                        <span>Название</span>
                        <input name="title" type="text" maxlength="255" required>
                    </label>
                    <label class="task-board-field">
                        <span>Срок</span>
                        <input name="due_date" type="datetime-local">
                    </label>
                    <label class="task-board-field">
                        <span>Статус</span>
                        <select name="status">
                            <option value="pending">Ожидает</option>
                            <option value="in_progress">В процессе</option>
                            <option value="completed">Завершена</option>
                            <option value="cancelled">Отменена</option>
                        </select>
                    </label>
                    <label class="task-board-field">
                        <span>Приоритет</span>
                        <select name="priority">
                            <option value="low">Низкий</option>
                            <option value="medium" selected>Средний</option>
                            <option value="high">Высокий</option>
                            <option value="urgent">Срочный</option>
                        </select>
                    </label>
                    <label class="task-board-field task-board-field--wide">
                        <span>Описание</span>
                        <textarea name="description" rows="3" maxlength="10000"></textarea>
                    </label>
                    {if $boardPolicies.can_assign_tasks}
                        <div class="task-board-field task-board-field--wide">
                            <span>Исполнители</span>
                            <div class="task-board-users">
                                {foreach $boardMembers as $member}
                                    <label class="task-board-user">
                                        <input type="checkbox" name="assignee_ids[]" value="{$member.id}">
                                        <span>
                                            <strong>{$member.firstname|escape} {$member.lastname|escape}</strong>
                                            <small>@{$member.username|escape}</small>
                                        </span>
                                    </label>
                                {/foreach}
                            </div>
                        </div>
                    {/if}
                    <div class="task-board-actions-row">
                        <button class="task-board-button task-board-button--primary" type="submit">Добавить задачу</button>
                    </div>
                </form>
            </section>
        {/if}

        {if $selectedBoard.can_manage && $selectedBoard.audience == 'members'}
            <section class="task-board-panel">
                <div class="task-board-section-title">
                    <div>
                        <h2>Участники доски</h2>
                        <div class="task-board-hint">Владелец остаётся участником всегда. Остальные пользователи получают роль участника доски.</div>
                    </div>
                </div>
                <form action="{route_path name='task_board_members' uid=$selectedBoard.uid}" method="post">
                    {csrf_token}
                    <div class="task-board-users">
                        {foreach $activeUsers as $candidate}
                            {if $candidate.id != $selectedBoard.owner_user_id}
                                {assign var=isBoardMember value=false}
                                {foreach $boardMembers as $existingMember}
                                    {if $existingMember.id == $candidate.id}{assign var=isBoardMember value=true}{/if}
                                {/foreach}
                                <label class="task-board-user">
                                    <input type="checkbox" name="member_ids[]" value="{$candidate.id}" {if $isBoardMember}checked{/if}>
                                    <span>
                                        <strong>{$candidate.firstname|escape} {$candidate.lastname|escape}</strong>
                                        <small>@{$candidate.username|escape}</small>
                                    </span>
                                </label>
                            {/if}
                        {/foreach}
                    </div>
                    <div class="task-board-actions-row" style="margin-top:12px">
                        <button class="task-board-button task-board-button--primary" type="submit">Сохранить состав</button>
                    </div>
                </form>
            </section>
        {/if}

        <section class="task-board-panel">
            <div class="task-board-section-title">
                <div>
                    <h2>Задачи доски</h2>
                    <div class="task-board-hint">Перетаскивайте карточки между колонками — статус сохранится на сервере.</div>
                </div>
            </div>
            <div class="task-board-columns" id="shared-task-board">
                {foreach ['pending'=>'Новые','in_progress'=>'В работе','completed'=>'Готово','cancelled'=>'Отменено'] as $statusCode=>$statusLabel}
                    <div class="task-board-column" data-status="{$statusCode}">
                        <div class="task-board-column__head">
                            <h3>{$statusLabel}</h3>
                            {assign var=statusCount value=0}
                            {foreach $boardTasks as $task}{if $task.status == $statusCode}{assign var=statusCount value=$statusCount+1}{/if}{/foreach}
                            <span class="task-board-pill">{$statusCount}</span>
                        </div>
                        <div class="task-board-column__items">
                            {foreach $boardTasks as $task}
                                {if $task.status == $statusCode}
                                    <article class="task-board-card" draggable="{if $selectedBoard.can_write}true{else}false{/if}"
                                             data-task-uid="{$task.uid|escape}"
                                             data-update-url="{route_path name='task_board_task_update' uid=$task.uid}">
                                        <div class="task-board-card__title">
                                            <strong>{$task.title|escape}</strong>
                                            <span class="task-board-priority">{$task.priority|escape}</span>
                                        </div>
                                        {if $task.description}<p class="task-board-card__description">{$task.description|escape}</p>{/if}
                                        <div class="task-board-card__meta">
                                            <span>Автор: @{$task.creator_username|escape}</span>
                                            {if $task.due_date}<span>Срок: {$task.due_date|escape}</span>{/if}
                                        </div>
                                        {if $task.assignees}
                                            <div class="task-board-assignees">
                                                {foreach $task.assignees as $assignee}
                                                    <span class="task-board-assignee">@{$assignee.username|escape}</span>
                                                {/foreach}
                                            </div>
                                        {/if}
                                        {if $selectedBoard.can_write}
                                            <div class="task-board-card__actions">
                                                <form action="{route_path name='task_board_task_update' uid=$task.uid}" method="post">
                                                    {csrf_token}
                                                    <select name="status" onchange="this.form.submit()" aria-label="Статус задачи">
                                                        <option value="pending" {if $task.status == 'pending'}selected{/if}>Ожидает</option>
                                                        <option value="in_progress" {if $task.status == 'in_progress'}selected{/if}>В процессе</option>
                                                        <option value="completed" {if $task.status == 'completed'}selected{/if}>Завершена</option>
                                                        <option value="cancelled" {if $task.status == 'cancelled'}selected{/if}>Отменена</option>
                                                    </select>
                                                </form>
                                                <form action="{route_path name='task_board_task_delete' uid=$task.uid}" method="post" onsubmit="return confirm('Удалить задачу с общей доски?')">
                                                    {csrf_token}
                                                    <input type="hidden" name="board_uid" value="{$selectedBoard.uid|escape}">
                                                    <button class="task-board-button task-board-button--danger" type="submit">Удалить</button>
                                                </form>
                                            </div>
                                        {/if}
                                    </article>
                                {/if}
                            {/foreach}
                        </div>
                    </div>
                {/foreach}
            </div>
        </section>
    {/if}
</section>

<script>
{literal}
document.addEventListener('DOMContentLoaded', () => {
    const audience = document.getElementById('task-board-audience');
    const members = document.getElementById('task-board-member-picker');
    const syncAudience = () => {
        if (!audience || !members) return;
        members.hidden = audience.value === 'all_active';
        members.querySelectorAll('input[type="checkbox"]').forEach((input) => {
            input.disabled = audience.value === 'all_active';
        });
    };
    audience?.addEventListener('change', syncAudience);
    syncAudience();

    let dragged = null;
    document.querySelectorAll('.task-board-card[draggable="true"]').forEach((card) => {
        card.addEventListener('dragstart', () => {
            dragged = card;
            card.dataset.dragging = 'true';
        });
        card.addEventListener('dragend', () => {
            card.dataset.dragging = 'false';
            dragged = null;
            document.querySelectorAll('.task-board-column').forEach((column) => column.dataset.dragOver = 'false');
        });
    });

    document.querySelectorAll('.task-board-column').forEach((column) => {
        column.addEventListener('dragover', (event) => {
            if (!dragged) return;
            event.preventDefault();
            column.dataset.dragOver = 'true';
        });
        column.addEventListener('dragleave', () => {
            column.dataset.dragOver = 'false';
        });
        column.addEventListener('drop', async (event) => {
            event.preventDefault();
            column.dataset.dragOver = 'false';
            if (!dragged) return;
            const status = column.dataset.status || '';
            const url = dragged.dataset.updateUrl || '';
            if (!status || !url) return;
            const currentColumn = dragged.closest('.task-board-column');
            if (currentColumn?.dataset.status === status) return;

            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams({ status }).toString()
                });
                const payload = await response.json();
                if (!response.ok || payload.success !== true) {
                    throw new Error(payload.message || `HTTP ${response.status}`);
                }
                column.querySelector('.task-board-column__items')?.appendChild(dragged);
                const select = dragged.querySelector('select[name="status"]');
                if (select) select.value = status;
                window.setTimeout(() => window.location.reload(), 120);
            } catch (error) {
                alert(`Не удалось изменить статус: ${error.message}`);
            }
        });
    });
});
{/literal}
</script>
{/block}
