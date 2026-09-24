<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$storageUsers = isset($storage_users) && is_array($storage_users) ? $storage_users : [];
$flash = isset($settings_flash) && is_array($settings_flash) ? $settings_flash : null;
$defaultQuotaBytes = max(0, (int) ($default_quota_bytes ?? 0));
$twoFactorRequired = !empty($two_factor_required);
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$bytesToMb = static fn (mixed $bytes, int $precision = 2): string => (string) round(max(0, (int) $bytes) / 1048576, $precision);

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Хранилище Workspace</span>
            <h1>Системные настройки</h1>
            <p>Общий лимит файлового менеджера, персональные квоты пользователей и installation-wide операции.</p>
        </div>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status"><?= $view->e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Безопасность</span>
                <h2>Двухфакторная аутентификация</h2>
                <p>Пользователи всегда могут включить TOTP для себя. Здесь администратор определяет, обязательно ли наличие 2FA для всех активных аккаунтов.</p>
            </div>
        </div>
        <form action="<?= $view->e($view->route('admin_settings_two_factor')) ?>" method="post" class="custom-fields-form" autocomplete="off">
            <?= $view->csrfInput() ?>
            <div class="custom-field__control">
                <label for="two_factor_required">Политика 2FA</label>
                <select id="two_factor_required" name="two_factor_required">
                    <option value="0"<?= !$twoFactorRequired ? ' selected' : '' ?>>По выбору пользователя</option>
                    <option value="1"<?= $twoFactorRequired ? ' selected' : '' ?>>Обязательно для всех пользователей</option>
                </select>
                <small>
                    При включении обязательного режима пользователи без настроенной 2FA после проверки пароля
                    должны настроить собственный TOTP перед доступом к Workspace. Уже настроенные личные ключи не меняются.
                </small>
            </div>
            <div class="custom-field__control">
                <label for="two_factor_admin_password">Текущий пароль администратора</label>
                <input id="two_factor_admin_password" name="current_password" type="password" autocomplete="current-password" required>
            </div>
            <?php if (!$twoFactorRequired): ?>
                <p><strong>Важно:</strong> если у вашей администраторской учётной записи 2FA ещё не настроена, после включения обязательного режима следующий запрос потребует повторного входа и настройки 2FA.</p>
            <?php else: ?>
                <p>Обязательный режим активен. Пользователи не могут самостоятельно отключить 2FA, пока политика действует.</p>
            <?php endif; ?>
            <button class="admin-action admin-action--primary" type="submit">Сохранить политику 2FA</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">По умолчанию</span><h2>Лимит хранилища</h2><p>Используется для пользователей без персонального override.</p></div>
        </div>
        <form action="<?= $view->e($view->route('admin_settings_default_quota')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="custom-field__control">
                <label for="default_quota_mb">Лимит, МБ</label>
                <input id="default_quota_mb" name="default_quota_mb" type="number" min="10" max="10485760" step="1" value="<?= $view->e(round($defaultQuotaBytes / 1048576)) ?>" required>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить лимит</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">Пользователи</span><h2>Использование хранилища</h2><p>Фактический объём считается из активных файлов, отдельный счётчик usage не хранится.</p></div>
        </div>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Пользователь</th><th>Использовано</th><th>Лимит</th><th>Заполнение</th><th>Персональный лимит</th></tr></thead>
                <tbody>
                <?php foreach ($storageUsers as $storageUser): ?>
                    <?php if (!is_array($storageUser)) { continue; } ?>
                    <tr>
                        <td data-label="Пользователь"><strong>@<?= $view->e($storageUser['username'] ?? '') ?></strong><br><small><?= $view->e($storageUser['email'] ?? '') ?></small></td>
                        <td data-label="Использовано"><?= $view->e($bytesToMb($storageUser['used_bytes'] ?? 0)) ?> МБ</td>
                        <td data-label="Лимит"><?= $view->e($bytesToMb($storageUser['effective_quota'] ?? 0)) ?> МБ</td>
                        <td data-label="Заполнение"><?= $view->e($storageUser['percent'] ?? 0) ?>%</td>
                        <td data-label="Персональный лимит">
                            <form action="<?= $view->e($view->route('admin_settings_user_quota')) ?>" method="post" class="admin-user-actions">
                                <?= $view->csrfInput() ?>
                                <input type="hidden" name="user_id" value="<?= $view->e($storageUser['id'] ?? '') ?>">
                                <input type="number" name="quota_mb" min="10" max="10485760" step="1"
                                       value="<?= !empty($storageUser['has_override']) ? $view->e(round(max(0, (int) ($storageUser['override_quota'] ?? 0)) / 1048576)) : '' ?>"
                                       placeholder="по умолчанию" aria-label="Персональная квота для <?= $view->e($storageUser['username'] ?? '') ?>">
                                <button type="submit" class="admin-action admin-action--secondary">Применить</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Системные настройки',
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
    ],
], $content);
