<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$taskRow = isset($task) && is_array($task) ? $task : [];
$availableCategories = isset($categories) && is_array($categories) ? $categories : [];
$uid = (string) ($taskRow['uid'] ?? '');
$status = (string) ($taskRow['status'] ?? 'pending');
$priority = (string) ($taskRow['priority'] ?? 'medium');
$title = (string) ($taskRow['title'] ?? '');
$description = (string) ($taskRow['description'] ?? '');
$priorityColor = strtolower((string) ($taskRow['priority_color'] ?? '#3498db'));
if (preg_match('/^#[0-9a-f]{6}$/', $priorityColor) !== 1) { $priorityColor = '#3498db'; }
$taskCategories = isset($taskRow['categories']) && is_array($taskRow['categories']) ? $taskRow['categories'] : [];
$subtasks = isset($taskRow['subtasks']) && is_array($taskRow['subtasks']) ? $taskRow['subtasks'] : [];
$completion = max(0, min(100, (int) ($taskRow['completion_percentage'] ?? 0)));
$isOverdue = !empty($taskRow['is_overdue']);

$formatDate = static function (mixed $value, string $format): string {
    $raw = trim((string) $value);
    if ($raw === '') return '';
    $timestamp = strtotime($raw);
    return $timestamp === false ? $raw : date($format, $timestamp);
};
$displayDescription = $description;
if (mb_strlen($displayDescription) > 200) {
    $displayDescription = rtrim(mb_substr($displayDescription, 0, 197)) . '...';
}
$dueDate = $formatDate($taskRow['due_date'] ?? '', 'd.m.Y H:i');
$dueInput = $formatDate($taskRow['due_date'] ?? '', 'Y-m-d\TH:i');
$createdAt = $formatDate($taskRow['created_at'] ?? '', 'd.m.Y H:i');
?>
<div class="task-item task-priority-<?= $view->e($priority) ?>" data-task-id="<?= $view->e($uid) ?>" data-status="<?= $view->e($status) ?>">
    <div class="task-header">
        <div class="task-title-section">
            <input type="checkbox" class="task-complete-toggle" <?= $status === 'completed' ? 'checked' : '' ?> data-task-id="<?= $view->e($uid) ?>" title="Отметить как выполненную">
            <h3 class="task-title<?= $status === 'completed' ? ' completed' : '' ?>"><?= $view->e($title) ?></h3>
            <span class="task-priority-badge" style="background-color: <?= $view->e($priorityColor) ?>;"><?= $view->e($priority) ?></span>
        </div>

        <div class="task-actions">
            <select class="task-status-toggle" data-task-id="<?= $view->e($uid) ?>" aria-label="Статус задачи">
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Ожидает</option>
                <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>В процессе</option>
                <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Завершена</option>
                <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Отменена</option>
            </select>
            <button type="button" class="btn-icon edit-task" data-task-id="<?= $view->e($uid) ?>" title="Редактировать"><i class="fa fa-edit" aria-hidden="true"></i></button>
            <form action="<?= $view->e($view->route('delete_task', ['uid' => $uid])) ?>" method="post" onsubmit="return confirm('Вы уверены, что хотите удалить эту задачу?')">
                <?= $view->csrfInput() ?>
                <button type="submit" class="btn-icon delete-task" title="Удалить"><i class="fa fa-trash" aria-hidden="true"></i></button>
            </form>
        </div>
    </div>

    <?php if ($description !== ''): ?>
        <div class="task-description"><p><?= $view->e($displayDescription) ?></p></div>
    <?php endif; ?>

    <div class="task-meta">
        <?php if ($dueDate !== ''): ?>
            <div class="task-due-date<?= $isOverdue ? ' overdue' : '' ?>">
                <i class="fa fa-calendar" aria-hidden="true"></i>
                <span><?= $isOverdue ? 'Просрочено:' : 'Срок:' ?> <?= $view->e($dueDate) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($taskCategories !== []): ?>
            <div class="task-categories">
                <?php foreach ($taskCategories as $category): ?>
                    <?php
                        if (!is_array($category)) { continue; }
                        $categoryColor = strtolower((string) ($category['color'] ?? '#3498db'));
                        if (preg_match('/^#[0-9a-f]{6}$/', $categoryColor) !== 1) { $categoryColor = '#3498db'; }
                        $categoryIcon = strtolower((string) ($category['icon'] ?? 'fa-folder'));
                        if (preg_match('/^fa-[a-z0-9-]{1,48}$/', $categoryIcon) !== 1) { $categoryIcon = 'fa-folder'; }
                    ?>
                    <span class="category-badge" style="background-color: <?= $view->e($categoryColor) ?>;">
                        <i class="fa <?= $view->e($categoryIcon) ?>" aria-hidden="true"></i>
                        <?= $view->e($category['name'] ?? '') ?>
                        <button type="button" class="detach-category-btn" data-task-id="<?= $view->e($uid) ?>" data-category-id="<?= $view->e($category['id'] ?? '') ?>" title="Убрать категорию" aria-label="Убрать категорию">×</button>
                    </span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="task-info">
            <span class="task-created">Создано: <?= $view->e($createdAt) ?></span>
            <span class="task-status-label"><?= $view->e($taskRow['status_label'] ?? '') ?></span>
        </div>
    </div>

    <div class="task-category-attach">
        <select class="task-category-select" data-task-id="<?= $view->e($uid) ?>" aria-label="Добавить категорию">
            <option value="">Выберите категорию</option>
            <?php foreach ($availableCategories as $category): ?>
                <?php if (!is_array($category)) { continue; } ?>
                <option value="<?= $view->e($category['id'] ?? '') ?>"><?= $view->e($category['name'] ?? '') ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn-sm attach-category-btn" data-task-id="<?= $view->e($uid) ?>">Добавить категорию</button>
    </div>

    <div class="task-subtasks" data-subtask-total="<?= count($subtasks) ?>">
        <div class="subtasks-header">
            <span class="task-subtasks__label">Подзадачи (<span class="task-subtasks__percent"><?= $completion ?></span>%)</span>
            <button type="button" class="btn-sm add-subtask-btn" data-task-id="<?= $view->e($uid) ?>">+ Добавить</button>
        </div>
        <div class="task-subtasks__progress" role="progressbar" aria-label="Прогресс подзадач" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $completion ?>">
            <span class="task-subtasks__progress-bar" style="width: <?= $completion ?>%"></span>
        </div>
        <ul class="subtasks-list">
            <?php foreach ($subtasks as $subtask): ?>
                <?php if (!is_array($subtask)) { continue; } $subtaskDone = (int) ($subtask['is_completed'] ?? 0) === 1; ?>
                <li class="subtask-item<?= $subtaskDone ? ' completed' : '' ?>" data-subtask-id="<?= $view->e($subtask['id'] ?? '') ?>">
                    <input type="checkbox" class="subtask-toggle" <?= $subtaskDone ? 'checked' : '' ?> data-subtask-id="<?= $view->e($subtask['id'] ?? '') ?>" aria-label="Статус подзадачи">
                    <span class="subtask-title"><?= $view->e($subtask['title'] ?? '') ?></span>
                    <button type="button" class="btn-icon delete-subtask" data-subtask-id="<?= $view->e($subtask['id'] ?? '') ?>" title="Удалить подзадачу"><i class="fa fa-times" aria-hidden="true"></i></button>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="task-edit-panel" data-task-edit-panel="<?= $view->e($uid) ?>" hidden>
        <form action="<?= $view->e($view->route('update_task', ['uid' => $uid])) ?>" method="post" class="task-edit-form">
            <?= $view->csrfInput() ?>
            <div class="form-group"><label>Название</label><input type="text" name="title" maxlength="255" required value="<?= $view->e($title) ?>"></div>
            <div class="form-group"><label>Описание</label><textarea name="description" rows="4" maxlength="10000"><?= $view->e($description) ?></textarea></div>
            <div class="form-row">
                <div class="form-group">
                    <label>Статус</label>
                    <select name="status">
                        <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Ожидает</option>
                        <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>В процессе</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Завершена</option>
                        <option value="cancelled" <?= $status === 'cancelled' ? 'selected' : '' ?>>Отменена</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Приоритет</label>
                    <select name="priority">
                        <option value="low" <?= $priority === 'low' ? 'selected' : '' ?>>Низкий</option>
                        <option value="medium" <?= $priority === 'medium' ? 'selected' : '' ?>>Средний</option>
                        <option value="high" <?= $priority === 'high' ? 'selected' : '' ?>>Высокий</option>
                        <option value="urgent" <?= $priority === 'urgent' ? 'selected' : '' ?>>Срочный</option>
                    </select>
                </div>
                <div class="form-group"><label>Срок выполнения</label><input type="datetime-local" name="due_date" value="<?= $view->e($dueInput) ?>"></div>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn-primary">Сохранить</button>
                <button type="button" class="btn-secondary close-task-edit" data-task-id="<?= $view->e($uid) ?>">Отмена</button>
            </div>
        </form>
    </div>
</div>
