<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$taskRows = isset($tasks) && is_array($tasks) ? $tasks : [];
$categoryRows = isset($categories) && is_array($categories) ? $categories : [];
$statsData = isset($stats) && is_array($stats) ? $stats : [];
$pager = isset($pagination) && is_array($pagination) ? $pagination : [];
$filter = isset($currentFilter) ? (string) $currentFilter : 'all';
$sort = isset($currentSort) ? (string) $currentSort : 'created_at';
$direction = isset($currentDirection) && strtolower((string) $currentDirection) === 'asc' ? 'asc' : 'desc';
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$tasksRoute = $view->route('tasks');
$filterLabels = [
    'all' => 'Все',
    'today' => 'Сегодня',
    'week' => 'Неделя',
    'pending' => 'Ожидают',
    'in_progress' => 'В процессе',
    'overdue' => 'Просрочены',
    'completed' => 'Завершены',
    'cancelled' => 'Отменены',
];

ob_start();
?>
<section class="content-header"><h1>Задачи</h1></section>

<section class="tasks">
    <div class="tasks__stats">
        <div class="stat-card stat-pending"><span class="stat-value"><?= (int) ($statsData['pending'] ?? 0) ?></span><span class="stat-label">Ожидает</span></div>
        <div class="stat-card stat-in_progress"><span class="stat-value"><?= (int) ($statsData['in_progress'] ?? 0) ?></span><span class="stat-label">В процессе</span></div>
        <div class="stat-card stat-completed"><span class="stat-value"><?= (int) ($statsData['completed'] ?? 0) ?></span><span class="stat-label">Завершено</span></div>
        <div class="stat-card stat-overdue"><span class="stat-value"><?= (int) ($statsData['overdue'] ?? 0) ?></span><span class="stat-label">Просрочено</span></div>
    </div>

    <div class="tasks__controls">
        <div class="tasks__filters">
            <?php foreach ($filterLabels as $code => $label): ?>
                <a href="<?= $view->e($tasksRoute . '?filter=' . rawurlencode($code)) ?>" class="filter-btn<?= $filter === $code ? ' active' : '' ?>"><?= $view->e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <form method="get" action="<?= $view->e($tasksRoute) ?>" class="tasks__sort-form">
            <input type="hidden" name="filter" value="<?= $view->e($filter) ?>">
            <label>
                Сортировка
                <select name="sort">
                    <option value="created_at" <?= $sort === 'created_at' ? 'selected' : '' ?>>По созданию</option>
                    <option value="updated_at" <?= $sort === 'updated_at' ? 'selected' : '' ?>>По изменению</option>
                    <option value="due_date" <?= $sort === 'due_date' ? 'selected' : '' ?>>По сроку</option>
                    <option value="priority" <?= $sort === 'priority' ? 'selected' : '' ?>>По приоритету</option>
                    <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>По статусу</option>
                    <option value="title" <?= $sort === 'title' ? 'selected' : '' ?>>По названию</option>
                </select>
            </label>
            <select name="direction" aria-label="Направление сортировки">
                <option value="desc" <?= $direction === 'desc' ? 'selected' : '' ?>>↓</option>
                <option value="asc" <?= $direction === 'asc' ? 'selected' : '' ?>>↑</option>
            </select>
            <button type="submit" class="btn-secondary">Применить</button>
        </form>

        <a class="btn-secondary tasks__shared-boards-link" data-shared-task-boards-link href="<?= $view->e($view->route('task_boards')) ?>"><i class="fa fa-users" aria-hidden="true"></i> Общие доски</a>
        <button class="btn-primary" id="open-create-task" type="button">+ Новая задача</button>
    </div>

    <div class="tasks__category-create">
        <form action="<?= $view->e($view->route('create_category')) ?>" method="post" class="category-create-form">
            <?= $view->csrfInput() ?>
            <label>Новая категория<input type="text" name="name" maxlength="120" required placeholder="Например: Проект"></label>
            <label>Цвет<input type="color" name="color" value="#3498db"></label>
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

    <div id="create-task-modal" class="modal">
        <div class="modal-content">
            <button class="close-modal" type="button" aria-label="Закрыть">&times;</button>
            <h2>Новая задача</h2>
            <form action="<?= $view->e($view->route('task_create')) ?>" method="post" class="task-form">
                <?= $view->csrfInput() ?>
                <div class="form-group"><label for="title">Название *</label><input type="text" id="title" name="title" maxlength="255" required placeholder="Введите название задачи"></div>
                <div class="form-group"><label for="description">Описание</label><textarea id="description" name="description" rows="4" maxlength="10000" placeholder="Описание задачи (необязательно)"></textarea></div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="priority">Приоритет</label>
                        <select id="priority" name="priority"><option value="low">Низкий</option><option value="medium" selected>Средний</option><option value="high">Высокий</option><option value="urgent">Срочный</option></select>
                    </div>
                    <div class="form-group"><label for="due_date">Срок выполнения</label><input type="datetime-local" id="due_date" name="due_date"></div>
                </div>
                <div class="form-actions"><button type="submit" class="btn-primary">Создать задачу</button><button type="button" class="btn-secondary close-modal">Отмена</button></div>
            </form>
        </div>
    </div>

    <div class="tasks__list">
        <?php if ($taskRows !== []): ?>
            <?php foreach ($taskRows as $taskRow): ?>
                <?php if (!is_array($taskRow)) { continue; } ?>
                <?= $view->partial('@tasks/elements/task_item/index', ['task' => $taskRow, 'user' => $currentUser, 'categories' => $categoryRows]) ?>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="tasks__empty"><p>Здесь пока нет задач. Создайте первую задачу!</p></div>
        <?php endif; ?>
    </div>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Задачи',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'pagination' => $pager,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('tasks', 'style.css'),
        $view->moduleAsset('tasks', 'hardening.css'),
        $view->moduleAsset('tasks', 'kanban.css'),
        $view->moduleAsset('tasks', 'kanban-handle.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('tasks', 'tasks-page.js'),
        $view->moduleAsset('tasks', 'tasks-kanban.js'),
        $view->moduleAsset('tasks', 'task-boards-nav.js'),
    ],
], $content);
