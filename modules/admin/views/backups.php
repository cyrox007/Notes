<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$entries = isset($backups) && is_array($backups) ? $backups : [];
$flash = isset($backup_flash) && is_array($backup_flash) ? $backup_flash : null;
$error = isset($backup_error) ? (string) $backup_error : null;

ob_start();
?>
<section class="admin-page admin-backups" data-admin-backups>
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Данные установки</span>
            <h1>Резервные копии и восстановление</h1>
            <p>Сохраняйте базу данных и приватные файлы без переустановки приложения.</p>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flash['type'] === 'success' ? 'success' : 'error') ?>" role="status">
            <?= $view->e($flash['message'] ?? '') ?>
        </div>
    <?php endif; ?>
    <?php if ($error !== null): ?>
        <div class="admin-page__flash admin-page__flash--error" role="alert">
            <?= $view->e($error) ?>
        </div>
    <?php endif; ?>

    <section class="admin-panel-card" aria-labelledby="admin-backup-create-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Резервирование</span>
                <h2 id="admin-backup-create-title">Создать резервную копию</h2>
                <p>Система временно ограничит запись данных, проверит копию и сохранит её во внешнем приватном каталоге.</p>
            </div>
        </div>
        <form class="admin-backups__form" action="<?= $view->e($view->route('admin_backups_create')) ?>" method="post">
            <?= $view->csrfInput() ?>
            <label>
                Текущий пароль суперпользователя
                <input name="current_password" type="password" autocomplete="current-password" required>
            </label>
            <button type="submit" class="admin-action admin-action--primary" <?= $error !== null ? 'disabled' : '' ?>>
                <i class="fa fa-database" aria-hidden="true"></i> Создать и проверить копию
            </button>
        </form>
    </section>

    <section class="admin-panel-card" aria-labelledby="admin-backup-list-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">История</span>
                <h2 id="admin-backup-list-title">Сохранённые копии</h2>
                <p>Все копии остаются на сервере, вне публичного каталога сайта.</p>
            </div>
        </div>

        <?php if ($entries === []): ?>
            <p class="admin-panel-card__empty">Завершённых резервных копий пока нет.</p>
        <?php else: ?>
            <div class="admin-backups__list">
                <?php foreach ($entries as $entry): ?>
                    <?php
                    $id = (string) ($entry['id'] ?? '');
                    $timestamp = (int) ($entry['created_at'] ?? 0);
                    $date = $timestamp > 0 ? date('d.m.Y H:i', $timestamp) : 'Неизвестно';
                    $fileCount = (int) ($entry['files'] ?? 0);
                    $size = round(max(0, (int) ($entry['bytes'] ?? 0)) / 1048576, 1);
                    ?>
                    <article class="admin-backups__entry">
                        <div class="admin-backups__meta">
                            <strong><?= $view->e($date) ?></strong>
                            <span><?= $view->e($id) ?></span>
                            <small>Версия <?= $view->e($entry['version'] ?? '') ?> · <?= $view->e((string) $fileCount) ?> файлов · ~<?= $view->e((string) $size) ?> МиБ</small>
                        </div>
                        <form action="<?= $view->e($view->route('admin_backups_verify')) ?>" method="post">
                            <?= $view->csrfInput() ?>
                            <input type="hidden" name="backup_id" value="<?= $view->e($id) ?>">
                            <button type="submit" class="admin-action admin-action--secondary">
                                <i class="fa fa-check-circle" aria-hidden="true"></i> Проверить
                            </button>
                        </form>
                        <details class="admin-backups__restore">
                            <summary>Восстановить из этой копии</summary>
                            <p>Существующие записи БД и файлы будут заменены состоянием копии. Перед заменой создаётся отдельная защитная копия текущих данных. Операция доступна только для этой установки и той же версии.</p>
                            <form action="<?= $view->e($view->route('admin_backups_restore')) ?>" method="post" class="admin-backups__form">
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="backup_id" value="<?= $view->e($id) ?>">
                                <label>Текущий пароль
                                    <input name="current_password" type="password" autocomplete="current-password" required>
                                </label>
                                <label>Введите ВОССТАНОВИТЬ
                                    <input name="confirmation" autocomplete="off" required pattern="ВОССТАНОВИТЬ">
                                </label>
                                <button type="submit" class="admin-action admin-action--danger">
                                    Восстановить данные
                                </button>
                            </form>
                        </details>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2>Важные ограничения</h2>
                <p>Операторские копии не заменяют отдельного внешнего хранения.</p>
            </div>
        </div>
        <div class="admin-backups__notice">
            <p>Копии содержат конфиденциальные данные. Переносите их на независимый защищённый носитель средствами администратора сервера. Публичное скачивание из браузера специально не предусмотрено.</p>
            <p>Восстановление данных не меняет исполняемый код и номер версии. Если приложение не запускается или состояние БД/файлов повреждено, потребуется восстановление из консоли на изолированном стенде.</p>
            <p>При недостатке места, ошибках доступа, несовпадении версии или контрольных сумм операция отменяется. Не удаляйте рабочую установку ради восстановления.</p>
        </div>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Резервные копии — админпанель',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
    'module_styles' => [$view->moduleAsset('admin', 'style.css')],
    'module_scripts' => [$view->moduleAsset('admin', 'admin-settings-nav.js')],
], $content);
