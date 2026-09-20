<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$rows = isset($auditRows) && is_array($auditRows) ? $auditRows : [];
$filters = isset($auditFilters) && is_array($auditFilters) ? $auditFilters : [];
$pageState = isset($pagination) && is_array($pagination) ? $pagination : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$page = max(1, (int) ($pageState['page'] ?? 1));
$limit = in_array((int) ($pageState['limit'] ?? 50), [20, 50, 100], true) ? (int) $pageState['limit'] : 50;
$total = max(0, (int) ($pageState['total'] ?? count($rows)));
$totalPages = max(1, (int) ($pageState['total_pages'] ?? 1));
$modules = ['core', 'admin', 'notes', 'tasks', 'files', 'messenger', 'profile'];

$auditUrl = static function (int $targetPage) use ($view, $filters, $limit): string {
    $query = [
        'q' => (string) ($filters['q'] ?? ''),
        'actor_id' => (int) ($filters['actor_id'] ?? 0),
        'module_id' => (string) ($filters['module_id'] ?? ''),
        'action' => (string) ($filters['action'] ?? ''),
        'outcome' => (string) ($filters['outcome'] ?? ''),
        'from' => (string) ($filters['from'] ?? ''),
        'to' => (string) ($filters['to'] ?? ''),
        'limit' => $limit,
        'page' => max(1, $targetPage),
    ];
    $query = array_filter($query, static fn (mixed $value): bool => $value !== '' && $value !== 0);
    return $view->route('admin_audit') . ($query !== [] ? '?' . http_build_query($query) : '');
};

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <h1>Журнал действий</h1>
            <p>Метаданные изменяющих операций пользователей. Содержимое заметок, сообщений, файлов, пароли, токены и CSRF-значения сюда не записываются.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('adminpanel')) ?>">← Админпанель</a>
    </header>

    <?php if (!empty($filters['error'])): ?>
        <div class="admin-page__flash admin-page__flash--error" role="alert"><?= $view->e((string) $filters['error']) ?></div>
    <?php endif; ?>

    <section class="admin-panel-card" aria-labelledby="audit-filter-title">
        <div class="admin-panel-card__header">
            <div><h2 id="audit-filter-title">Фильтры</h2><p>Всего найдено: <?= $view->e($total) ?></p></div>
        </div>
        <form action="<?= $view->e($view->route('admin_audit')) ?>" method="get" class="admin-toolbar admin-audit-toolbar" role="search" aria-label="Фильтры журнала действий">
            <input type="hidden" name="page" value="1">
            <label class="admin-toolbar__search" for="audit-q"><span>Поиск</span><input id="audit-q" type="search" name="q" maxlength="120" value="<?= $view->e($filters['q'] ?? '') ?>" placeholder="Пользователь, UID, модуль или действие"></label>
            <div class="admin-toolbar__options">
                <label class="admin-toolbar__field" for="audit-actor"><span>ID пользователя</span><input id="audit-actor" type="number" min="1" name="actor_id" value="<?= !empty($filters['actor_id']) ? $view->e((string) $filters['actor_id']) : '' ?>"></label>
                <label class="admin-toolbar__field" for="audit-module"><span>Модуль</span><select id="audit-module" name="module_id"><option value="">Все</option><?php foreach ($modules as $module): ?><option value="<?= $view->e($module) ?>"<?= ($filters['module_id'] ?? '') === $module ? ' selected' : '' ?>><?= $view->e($module) ?></option><?php endforeach; ?></select></label>
                <label class="admin-toolbar__field" for="audit-action"><span>Действие</span><input id="audit-action" type="text" maxlength="120" name="action" value="<?= $view->e($filters['action'] ?? '') ?>" placeholder="http.note_update"></label>
                <label class="admin-toolbar__field" for="audit-outcome"><span>Результат</span><select id="audit-outcome" name="outcome"><option value="">Все</option><option value="success"<?= ($filters['outcome'] ?? '') === 'success' ? ' selected' : '' ?>>Успех</option><option value="failure"<?= ($filters['outcome'] ?? '') === 'failure' ? ' selected' : '' ?>>Ошибка</option></select></label>
                <label class="admin-toolbar__field" for="audit-from"><span>С даты</span><input id="audit-from" type="date" name="from" value="<?= $view->e($filters['from'] ?? '') ?>"></label>
                <label class="admin-toolbar__field" for="audit-to"><span>По дату</span><input id="audit-to" type="date" name="to" value="<?= $view->e($filters['to'] ?? '') ?>"></label>
                <label class="admin-toolbar__field" for="audit-limit"><span>На странице</span><select id="audit-limit" name="limit"><?php foreach ([20, 50, 100] as $value): ?><option value="<?= $value ?>"<?= $limit === $value ? ' selected' : '' ?>><?= $value ?></option><?php endforeach; ?></select></label>
                <button class="admin-action admin-action--primary" type="submit">Применить</button>
            </div>
        </form>
    </section>

    <section class="admin-panel-card" aria-labelledby="audit-table-title">
        <div class="admin-panel-card__header"><div><h2 id="audit-table-title">События</h2><p>Новые записи сверху.</p></div></div>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Время</th><th>Пользователь</th><th>Модуль</th><th>Действие</th><th>Канал</th><th>Результат</th><th>Контекст</th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="7">Записей по выбранным фильтрам нет.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        if (!is_array($row)) { continue; }
                        $details = is_array($row['details'] ?? null) ? $row['details'] : [];
                        $detailsText = $details === [] ? '—' : (string) json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                        $outcome = (string) ($row['outcome'] ?? 'failure');
                        ?>
                        <tr>
                            <td data-label="Время"><?= $view->e((string) ($row['occurred_at'] ?? '')) ?></td>
                            <td data-label="Пользователь"><strong>@<?= $view->e((string) ($row['actor_username'] ?? 'unknown')) ?></strong><br><small>#<?= $view->e((string) ($row['actor_id'] ?? '—')) ?></small></td>
                            <td data-label="Модуль"><span class="admin-role-badge"><?= $view->e((string) ($row['module_id'] ?? '')) ?></span></td>
                            <td data-label="Действие"><code><?= $view->e((string) ($row['action'] ?? '')) ?></code></td>
                            <td data-label="Канал"><?= $view->e((string) ($row['transport'] ?? '')) ?></td>
                            <td data-label="Результат"><span class="admin-role-badge"><?= $view->e($outcome === 'success' ? 'Успех' : 'Ошибка') ?></span><?php if (!empty($row['status_code'])): ?><br><small>HTTP <?= $view->e((string) $row['status_code']) ?></small><?php endif; ?></td>
                            <td data-label="Контекст"><small><?= $view->e($detailsText) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <nav class="admin-pagination" aria-label="Пагинация журнала действий">
            <a class="admin-pagination__link" href="<?= $view->e($auditUrl(max(1, $page - 1))) ?>"<?= $page <= 1 ? ' aria-disabled="true" tabindex="-1"' : '' ?>>← Назад</a>
            <span class="admin-pagination__summary">Страница <?= $view->e($page) ?> из <?= $view->e($totalPages) ?> · найдено <?= $view->e($total) ?></span>
            <a class="admin-pagination__link" href="<?= $view->e($auditUrl(min($totalPages, $page + 1))) ?>"<?= $page >= $totalPages ? ' aria-disabled="true" tabindex="-1"' : '' ?>>Вперёд →</a>
        </nav>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Журнал действий',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [$view->moduleAsset('admin', 'style.css')],
    'module_scripts' => [
        $view->moduleAsset('admin', 'admin-settings-nav.js'),
    ],
], $content);
