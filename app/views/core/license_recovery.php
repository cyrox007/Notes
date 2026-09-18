<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$licenseState = isset($license) && is_array($license) ? $license : [];
$flash = isset($license_flash) && is_array($license_flash) ? $license_flash : null;
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$isValid = !empty($licenseState['valid']);
$canManage = !empty($licenseState['can_manage']);
$trustConfigured = !empty($licenseState['trust_configured']);
$hasToken = !empty($licenseState['has_token']);

ob_start();
?>
<section aria-labelledby="license-recovery-title">
    <p><a href="<?= $view->e($view->route('main')) ?>">← Вернуться в рабочее пространство</a></p>
    <h1 id="license-recovery-title">Лицензия установки</h1>
    <p>Это системная recovery-страница Core. Она остаётся доступной при отключённом модуле Admin.</p>

    <?php if ($flash !== null): ?>
        <p role="status"><strong><?= $view->e($flash['message'] ?? '') ?></strong></p>
    <?php endif; ?>

    <h2>Installation ID</h2>
    <p><code><?= $view->e($licenseState['installation_id'] ?? '') ?></code></p>

    <h2>Состояние</h2>
    <p><strong><?= $isValid ? 'Лицензия действительна' : 'Лицензия не подтверждена' ?></strong></p>
    <p><?= $view->e($licenseState['message'] ?? 'Состояние лицензии неизвестно') ?></p>
    <dl>
        <dt>Код</dt><dd><?= $view->e($licenseState['code'] ?? 'unknown') ?></dd>
        <dt>License ID</dt><dd><?= $view->e($licenseState['license_id'] ?? '—') ?></dd>
        <dt>Редакция</dt><dd><?= $view->e($licenseState['edition'] ?? '—') ?></dd>
        <dt>Ключ подписи</dt><dd><?= $view->e($licenseState['key_id'] ?? '—') ?></dd>
    </dl>

    <?php if (!$trustConfigured): ?>
        <p role="alert"><strong>Активация недоступна:</strong> в сборке не настроен production public key проверки лицензий.</p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <h2>Активация</h2>
        <form action="<?= $view->e($view->route('system_license_activate')) ?>" method="post">
            <?= $view->csrfInput() ?>
            <p>
                <label for="license_token">Подписанный лицензионный ключ</label><br>
                <textarea id="license_token" name="license_token" rows="6" maxlength="16384"
                    autocomplete="off" autocapitalize="off" spellcheck="false"
                    <?= $trustConfigured ? 'required' : 'disabled' ?>></textarea>
            </p>
            <button type="submit" <?= $trustConfigured ? '' : 'disabled' ?>>Проверить и активировать</button>
        </form>

        <?php if ($hasToken): ?>
            <h2>Удаление ключа</h2>
            <form action="<?= $view->e($view->route('system_license_clear')) ?>" method="post">
                <?= $view->csrfInput() ?>
                <button type="submit">Удалить сохранённый ключ</button>
            </form>
        <?php endif; ?>
    <?php else: ?>
        <p>Просмотр доступен администратору, но изменить installation-wide лицензию может только суперадминистратор.</p>
    <?php endif; ?>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Лицензия установки',
    'sitename' => $siteName,
    'version' => $workspaceVersion,
    'base_url' => $baseUrl,
    'base_path' => $base_path ?? '',
    'user' => $currentUser,
    'workspaceAccess' => $access,
    'socket_ticket' => $socket_ticket ?? '',
    'socket_url' => $socket_url ?? '',
], $content);
