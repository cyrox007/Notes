<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$boardRows = isset($boards) && is_array($boards) ? $boards : [];
$selected = isset($selectedBoard) && is_array($selectedBoard) ? $selectedBoard : null;
$taskRows = isset($boardTasks) && is_array($boardTasks) ? $boardTasks : [];
$memberRows = isset($boardMembers) && is_array($boardMembers) ? $boardMembers : [];
$activeUserRows = isset($activeUsers) && is_array($activeUsers) ? $activeUsers : [];
$policies = isset($boardPolicies) && is_array($boardPolicies) ? $boardPolicies : [];
$statsData = isset($boardStats) && is_array($boardStats) ? $boardStats : [];
$flash = isset($task_boards_flash) && is_array($task_boards_flash) ? $task_boards_flash : null;
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$selectedUid = (string) ($selected['uid'] ?? '');
$canWrite = !empty($selected['can_write']);
$canManage = !empty($selected['can_manage']);
$canCreateBoards = !empty($policies['can_create_shared_boards']);
$canAssignTasks = !empty($policies['can_assign_tasks']);
$maxBoardMembers = max(0, (int) ($policies['max_board_members'] ?? 0));
$statusLabels = ['pending' => 'Новые', 'in_progress' => 'В работе', 'completed' => 'Готово', 'cancelled' => 'Отменено'];
$memberIds = [];
foreach ($memberRows as $member) {
    if (is_array($member)) $memberIds[(int) ($member['id'] ?? 0)] = true;
}
$boardHref = static function (string $uid) use ($view): string {
    $url = $view->route('task_boards');
    return $uid === '' ? $url : $url . '?board=' . rawurlencode($uid);
};
ob_start();
?>
<section class="task-boards-page">
    <header class="task-boards-hero">
        <div><span class="admin-page__eyebrow">Совместная работа</span><h1>Общие доски задач</h1><p>Доски для выбранной команды или для всех активных пользователей Workspace.</p></div>
        <div class="task-boards-hero__actions">
            <a class="task-board-button" href="<?= $view->e($view->route('tasks')) ?>"><i class="fa fa-user" aria-hidden="true"></i> Личные задачи</a>
            <?php if ($selected !== null): ?><a class="task-board-button" href="<?= $view->e($view->route('task_boards')) ?>">Все доски</a><?php endif; ?>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <div class="task-board-flash<?= ($flash['type'] ?? '') === 'error' ? ' task-board-flash--error' : '' ?>" role="status"><?= $view->e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <section class="task-board-panel">
        <div class="task-board-section-title"><div><h2>Доступные доски</h2><div class="task-board-hint">Доски «Все пользователи» автоматически доступны каждому активному аккаунту.</div></div></div>
        <?php if ($boardRows !== []): ?>
            <div class="task-board-list">
                <?php foreach ($boardRows as $board): ?>
                    <?php if (!is_array($board)) { continue; } $uid = (string) ($board['uid'] ?? ''); ?>
                    <a class="task-board-tab<?= $selectedUid !== '' && $selectedUid === $uid ? ' task-board-tab--active' : '' ?>" href="<?= $view->e($boardHref($uid)) ?>">
                        <strong><?= $view->e($board['name'] ?? '') ?></strong>
                        <small><?= ($board['audience'] ?? '') === 'all_active' ? 'Все пользователи' : ((int) ($board['member_count'] ?? 0) . ' участн.') ?> · <?= (int) ($board['task_count'] ?? 0) ?> задач</small>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="task-board-empty">Общих досок пока нет.</div>
        <?php endif; ?>
    </section>

    <?php if ($canCreateBoards): ?>
        <section class="task-board-panel">
            <div class="task-board-section-title"><div><h2>Создать доску</h2><div class="task-board-hint">Можно открыть доску выбранной группе или всем активным пользователям.</div></div></div>
            <form action="<?= $view->e($view->route('task_board_create')) ?>" method="post" class="task-board-create-grid" id="task-board-create-form">
                <?= $view->csrfInput() ?>
                <label class="task-board-field"><span>Название</span><input name="name" type="text" maxlength="160" required placeholder="Например, Команда продукта"></label>
                <label class="task-board-field"><span>Кому доступна</span><select name="audience" id="task-board-audience"><option value="members">Выбранным пользователям</option><option value="all_active">Всем активным пользователям</option></select></label>
                <div class="task-board-field task-board-field--wide task-board-members-block" id="task-board-member-picker">
                    <span>Участники</span>
                    <div class="task-board-users">
                        <?php foreach ($activeUserRows as $member): ?>
                            <?php if (!is_array($member) || (int) ($member['id'] ?? 0) === (int) ($currentUser['id'] ?? 0)) { continue; } ?>
                            <label class="task-board-user"><input type="checkbox" name="member_ids[]" value="<?= $view->e($member['id'] ?? '') ?>"><span><strong><?= $view->e($member['firstname'] ?? '') ?> <?= $view->e($member['lastname'] ?? '') ?></strong><small>@<?= $view->e($member['username'] ?? '') ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($maxBoardMembers > 0): ?><small class="task-board-hint">Лимит роли: до <?= $maxBoardMembers ?> участников вместе с владельцем.</small><?php endif; ?>
                </div>
                <div class="task-board-actions-row"><button class="task-board-button task-board-button--primary" type="submit"><i class="fa fa-plus" aria-hidden="true"></i> Создать доску</button></div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($selected !== null): ?>
        <section class="task-board-panel">
            <div class="task-board-summary"><div><span class="admin-page__eyebrow">Активная доска</span><h2><?= $view->e($selected['name'] ?? '') ?></h2><div class="task-board-summary__meta"><span class="task-board-pill"><?= ($selected['audience'] ?? '') === 'all_active' ? 'Все активные пользователи' : 'Выбранная команда' ?></span><span class="task-board-pill">Владелец: @<?= $view->e($selected['owner_username'] ?? '') ?></span><span class="task-board-pill">Ваш доступ: <?= $view->e($selected['access_role'] ?? '') ?></span></div></div></div>
        </section>

        <section class="task-board-stats">
            <div class="task-board-stat"><strong><?= (int) ($statsData['pending'] ?? 0) ?></strong><span>Ожидает</span></div>
            <div class="task-board-stat"><strong><?= (int) ($statsData['in_progress'] ?? 0) ?></strong><span>В процессе</span></div>
            <div class="task-board-stat"><strong><?= (int) ($statsData['completed'] ?? 0) ?></strong><span>Завершено</span></div>
            <div class="task-board-stat"><strong><?= (int) ($statsData['cancelled'] ?? 0) ?></strong><span>Отменено</span></div>
        </section>

        <?php if ($canWrite): ?>
            <section class="task-board-panel">
                <div class="task-board-section-title"><div><h2>Новая задача на доске</h2><div class="task-board-hint">Задача сразу видна всем участникам этой доски.</div></div></div>
                <form action="<?= $view->e($view->route('task_board_task_create', ['uid' => $selectedUid])) ?>" method="post" class="task-board-task-form">
                    <?= $view->csrfInput() ?>
                    <label class="task-board-field"><span>Название</span><input name="title" type="text" maxlength="255" required></label>
                    <label class="task-board-field"><span>Срок</span><input name="due_date" type="datetime-local"></label>
                    <label class="task-board-field"><span>Статус</span><select name="status"><option value="pending">Ожидает</option><option value="in_progress">В процессе</option><option value="completed">Завершена</option><option value="cancelled">Отменена</option></select></label>
                    <label class="task-board-field"><span>Приоритет</span><select name="priority"><option value="low">Низкий</option><option value="medium" selected>Средний</option><option value="high">Высокий</option><option value="urgent">Срочный</option></select></label>
                    <label class="task-board-field task-board-field--wide"><span>Описание</span><textarea name="description" rows="3" maxlength="10000"></textarea></label>
                    <?php if ($canAssignTasks): ?>
                        <div class="task-board-field task-board-field--wide"><span>Исполнители</span><div class="task-board-users">
                            <?php foreach ($memberRows as $member): ?>
                                <?php if (!is_array($member)) { continue; } ?>
                                <label class="task-board-user"><input type="checkbox" name="assignee_ids[]" value="<?= $view->e($member['id'] ?? '') ?>"><span><strong><?= $view->e($member['firstname'] ?? '') ?> <?= $view->e($member['lastname'] ?? '') ?></strong><small>@<?= $view->e($member['username'] ?? '') ?></small></span></label>
                            <?php endforeach; ?>
                        </div></div>
                    <?php endif; ?>
                    <div class="task-board-actions-row"><button class="task-board-button task-board-button--primary" type="submit">Добавить задачу</button></div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($canManage && ($selected['audience'] ?? '') === 'members'): ?>
            <section class="task-board-panel">
                <div class="task-board-section-title"><div><h2>Участники доски</h2><div class="task-board-hint">Владелец остаётся участником всегда. Остальные пользователи получают роль участника доски.</div></div></div>
                <form action="<?= $view->e($view->route('task_board_members', ['uid' => $selectedUid])) ?>" method="post">
                    <?= $view->csrfInput() ?>
                    <div class="task-board-users">
                        <?php foreach ($activeUserRows as $candidate): ?>
                            <?php $candidateId = is_array($candidate) ? (int) ($candidate['id'] ?? 0) : 0; if (!is_array($candidate) || $candidateId === (int) ($selected['owner_user_id'] ?? 0)) { continue; } ?>
                            <label class="task-board-user"><input type="checkbox" name="member_ids[]" value="<?= $view->e($candidateId) ?>" <?= isset($memberIds[$candidateId]) ? 'checked' : '' ?>><span><strong><?= $view->e($candidate['firstname'] ?? '') ?> <?= $view->e($candidate['lastname'] ?? '') ?></strong><small>@<?= $view->e($candidate['username'] ?? '') ?></small></span></label>
                        <?php endforeach; ?>
                    </div>
                    <div class="task-board-actions-row task-board-actions-row--spaced"><button class="task-board-button task-board-button--primary" type="submit">Сохранить состав</button></div>
                </form>
            </section>
        <?php endif; ?>

        <section class="task-board-panel">
            <div class="task-board-section-title"><div><h2>Задачи доски</h2><div class="task-board-hint">Перетаскивайте карточки между колонками — статус сохранится на сервере.</div></div></div>
            <div class="task-board-columns" id="shared-task-board">
                <?php foreach ($statusLabels as $statusCode => $statusLabel): ?>
                    <?php $tasksForStatus = array_values(array_filter($taskRows, static fn ($row): bool => is_array($row) && (string) ($row['status'] ?? '') === $statusCode)); ?>
                    <div class="task-board-column" data-status="<?= $view->e($statusCode) ?>">
                        <div class="task-board-column__head"><h3><?= $view->e($statusLabel) ?></h3><span class="task-board-pill"><?= count($tasksForStatus) ?></span></div>
                        <div class="task-board-column__items">
                            <?php foreach ($tasksForStatus as $boardTask): ?>
                                <?php $taskUid = (string) ($boardTask['uid'] ?? ''); $assignees = isset($boardTask['assignees']) && is_array($boardTask['assignees']) ? $boardTask['assignees'] : []; ?>
                                <article class="task-board-card" draggable="<?= $canWrite ? 'true' : 'false' ?>" data-task-uid="<?= $view->e($taskUid) ?>" data-update-url="<?= $view->e($view->route('task_board_task_update', ['uid' => $taskUid])) ?>">
                                    <div class="task-board-card__title"><strong><?= $view->e($boardTask['title'] ?? '') ?></strong><span class="task-board-priority"><?= $view->e($boardTask['priority'] ?? '') ?></span></div>
                                    <?php if (trim((string) ($boardTask['description'] ?? '')) !== ''): ?><p class="task-board-card__description"><?= $view->e($boardTask['description']) ?></p><?php endif; ?>
                                    <div class="task-board-card__meta"><span>Автор: @<?= $view->e($boardTask['creator_username'] ?? '') ?></span><?php if (!empty($boardTask['due_date'])): ?><span>Срок: <?= $view->e($boardTask['due_date']) ?></span><?php endif; ?></div>
                                    <?php if ($assignees !== []): ?><div class="task-board-assignees"><?php foreach ($assignees as $assignee): ?><?php if (!is_array($assignee)) { continue; } ?><span class="task-board-assignee">@<?= $view->e($assignee['username'] ?? '') ?></span><?php endforeach; ?></div><?php endif; ?>
                                    <?php if ($canWrite): ?>
                                        <div class="task-board-card__actions">
                                            <form action="<?= $view->e($view->route('task_board_task_update', ['uid' => $taskUid])) ?>" method="post">
                                                <?= $view->csrfInput() ?>
                                                <select name="status" data-submit-on-change aria-label="Статус задачи">
                                                    <option value="pending" <?= ($boardTask['status'] ?? '') === 'pending' ? 'selected' : '' ?>>Ожидает</option><option value="in_progress" <?= ($boardTask['status'] ?? '') === 'in_progress' ? 'selected' : '' ?>>В процессе</option><option value="completed" <?= ($boardTask['status'] ?? '') === 'completed' ? 'selected' : '' ?>>Завершена</option><option value="cancelled" <?= ($boardTask['status'] ?? '') === 'cancelled' ? 'selected' : '' ?>>Отменена</option>
                                                </select>
                                            </form>
                                            <form action="<?= $view->e($view->route('task_board_task_delete', ['uid' => $taskUid])) ?>" method="post" data-confirm-message="Удалить задачу с общей доски?" data-confirm-title="Подтверждение" data-confirm-text="Удалить">
                                                <?= $view->csrfInput() ?><input type="hidden" name="board_uid" value="<?= $view->e($selectedUid) ?>"><button class="task-board-button task-board-button--danger" type="submit">Удалить</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Общие доски задач',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('tasks', 'style.css'),
        $view->moduleAsset('tasks', 'hardening.css'),
        $view->moduleAsset('tasks', 'boards.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('tasks', 'task-boards.js'),
        $view->moduleAsset('tasks', 'task-boards-nav.js'),
    ],
], $content);
