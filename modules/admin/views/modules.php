<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$moduleRows = isset($modules) && is_array($modules) ? $modules : [];
$flash = isset($admin_modules_flash) && is_array($admin_modules_flash) ? $admin_modules_flash : null;
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';

$stateLabel = static fn (string $state): string => match ($state) {
    'enabled' => 'Включён',
    'disabled' => 'Отключён',
    'degraded' => 'Ограничен',
    'incompatible' => 'Несовместим',
    'quarantined' => 'Карантин',
    'installed' => 'Установлен',
    'discovered' => 'Обнаружен',
    'uninstalled' => 'Удалён',
    default => $state !== '' ? $state : 'Неизвестно',
};

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Состав Workspace</span>
            <h1>Модули</h1>
            <p>Здесь отображаются только модули, которые явно разрешены текущей лицензией. Отключённый модуль сохраняет данные, но не регистрирует маршруты, интерфейс и межмодульные возможности.</p>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status"><?= $view->e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Жизненный цикл</span>
                <h2>Разрешённые модули</h2>
                <p>Выключение не удаляет таблицы, файлы и настройки модуля. При повторном включении прежние данные остаются доступны.</p>
            </div>
        </div>

        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead>
                    <tr>
                        <th>Модуль</th>
                        <th>Версия</th>
                        <th>Состояние</th>
                        <th>Лицензия</th>
                        <th>Действие</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($moduleRows as $module): ?>
                    <?php if (!is_array($module)) { continue; } ?>
                    <?php
                        $moduleId = (string) ($module['id'] ?? '');
                        $configured = (string) ($module['configured_state'] ?? '');
                        $effective = (string) ($module['effective_state'] ?? '');
                        $required = !empty($module['required']);
                        $canToggle = in_array($configured, ['enabled', 'disabled'], true) && !$required;
                        $nextEnabled = $configured !== 'enabled';
                    ?>
                    <tr>
                        <td data-label="Модуль">
                            <strong><?= $view->e($module['name'] ?? $moduleId) ?></strong><br>
                            <small><?= $view->e($moduleId) ?></small>
                            <?php if ($required): ?><br><small>Обязательный системный модуль</small><?php endif; ?>
                        </td>
                        <td data-label="Версия"><?= $view->e($module['version'] ?? '') ?></td>
                        <td data-label="Состояние">
                            <strong><?= $view->e($stateLabel($effective)) ?></strong>
                            <?php if ($effective !== $configured): ?>
                                <br><small>Настройка: <?= $view->e($stateLabel($configured)) ?></small>
                            <?php endif; ?>
                            <?php if (!empty($module['last_error'])): ?>
                                <br><small><?= $view->e($module['last_error']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td data-label="Лицензия"><code><?= $view->e($module['license_feature'] ?? '') ?></code></td>
                        <td data-label="Действие">
                            <?php if ($required): ?>
                                <span class="admin-user-actions__locked">Отключение запрещено</span>
                            <?php elseif ($canToggle): ?>
                                <form action="<?= $view->e($view->route('admin_modules_state')) ?>" method="post" class="admin-user-actions"
                                      data-confirm-message="<?= $nextEnabled ? 'Включить модуль?' : 'Отключить модуль во всей установке?' ?>"
                                      data-confirm-title="<?= $nextEnabled ? 'Включение модуля' : 'Отключение модуля' ?>"
                                      data-confirm-text="<?= $nextEnabled ? 'Включить' : 'Отключить' ?>">
                                    <?= $view->csrfInput() ?>
                                    <input type="hidden" name="module_id" value="<?= $view->e($moduleId) ?>">
                                    <input type="hidden" name="enabled" value="<?= $nextEnabled ? '1' : '0' ?>">
                                    <button type="submit" class="admin-action <?= $nextEnabled ? 'admin-action--primary' : 'admin-action--danger' ?>">
                                        <?= $nextEnabled ? 'Включить' : 'Отключить' ?>
                                    </button>
                                </form>
                            <?php else: ?>
                                <span class="admin-user-actions__locked">Требуется устранить состояние модуля</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($moduleRows === []): ?>
                    <tr><td colspan="5">В текущей лицензии нет модулей, доступных для управления.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Модули',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [
        $view->moduleAsset('admin', 'style.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('admin', 'admin-settings-nav.js'),
        $view->moduleAsset('admin', 'admin-page.js'),
    ],
], $content);
