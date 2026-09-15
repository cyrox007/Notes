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
$statusClass = $isValid ? 'success' : (($licenseState['code'] ?? '') === 'unlicensed' ? 'warning' : 'error');
$formatDate = static function (mixed $timestamp): string {
    $value = is_numeric($timestamp) ? (int) $timestamp : 0;
    return $value > 0 ? date('d.m.Y H:i', $value) : '—';
};

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Installation license</span>
            <h1>Лицензия установки</h1>
            <p>Один подписанный ключ привязывается к этой установке. Проверка выполняется локально по Ed25519 и не требует обращения к внешнему серверу.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('adminpanel')) ?>">Назад в админпанель</a>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status"><?= $view->e($flash['message'] ?? '') ?></div>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Идентичность установки</span>
                <h2>Installation ID</h2>
                <p>Этот идентификатор создаётся один раз и сохраняется при обновлениях. Лицензия другой установки не пройдёт проверку.</p>
            </div>
        </div>
        <div class="admin-license-id">
            <code><?= $view->e($licenseState['installation_id'] ?? '') ?></code>
        </div>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Состояние</span>
                <h2><?= $isValid ? 'Лицензия действительна' : 'Лицензия не подтверждена' ?></h2>
                <p><?= $view->e($licenseState['message'] ?? 'Состояние лицензии неизвестно') ?></p>
            </div>
            <span class="admin-license-status admin-license-status--<?= $view->e($statusClass) ?>">
                <?= $view->e($licenseState['code'] ?? 'unknown') ?>
            </span>
        </div>

        <?php if ($isValid): ?>
            <div class="admin-license-grid">
                <div><small>License ID</small><strong><?= $view->e($licenseState['license_id'] ?? '') ?></strong></div>
                <div><small>Редакция</small><strong><?= $view->e($licenseState['edition'] ?? '') ?></strong></div>
                <div><small>Владелец</small><strong><?= $view->e(($licenseState['customer'] ?? '') !== '' ? $licenseState['customer'] : '—') ?></strong></div>
                <div><small>Выпущена</small><strong><?= $view->e($formatDate($licenseState['issued_at'] ?? null)) ?></strong></div>
                <div><small>Действует до</small><strong><?= ($licenseState['expires_at'] ?? null) === null ? 'Бессрочно' : $view->e($formatDate($licenseState['expires_at'])) ?></strong></div>
                <div><small>Ключ подписи</small><strong><?= $view->e($licenseState['key_id'] ?? '') ?></strong></div>
            </div>
            <?php if (!empty($licenseState['features']) && is_array($licenseState['features'])): ?>
                <p><strong>Возможности:</strong> <?= $view->e(implode(', ', array_map('strval', $licenseState['features']))) ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <?php if (!$trustConfigured): ?>
        <section class="admin-panel-card">
            <div class="admin-page__flash admin-page__flash--error" role="status">
                Публичный ключ проверки ещё не встроен в эту сборку. Активация заблокирована до добавления production public key; приватный signing key в приложение не устанавливается никогда.
            </div>
        </section>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Активация</span>
                <h2>Подписанный ключ</h2>
                <p>Ключ проверяется до сохранения. Неверная подпись, другая установка или истёкший срок не заменят уже сохранённую лицензию.</p>
            </div>
        </div>

        <?php if ($canManage): ?>
            <form action="<?= $view->e($view->route('admin_license_activate')) ?>" method="post" class="custom-fields-form">
                <?= $view->csrfInput() ?>
                <div class="custom-field__control">
                    <label for="license_token">Лицензионный ключ</label>
                    <textarea id="license_token" name="license_token" rows="6" maxlength="16384" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="wo1.&lt;kid&gt;.&lt;payload&gt;.&lt;signature&gt;" <?= $trustConfigured ? 'required' : 'disabled' ?>></textarea>
                </div>
                <button class="admin-action admin-action--primary" type="submit" <?= $trustConfigured ? '' : 'disabled' ?>>Проверить и активировать</button>
            </form>

            <?php if ($hasToken): ?>
                <form action="<?= $view->e($view->route('admin_license_clear')) ?>" method="post" class="custom-fields-form" onsubmit="return confirm('Удалить лицензионный ключ из этой установки? Пользовательские данные останутся без изменений.');">
                    <?= $view->csrfInput() ?>
                    <button class="admin-action admin-action--secondary" type="submit">Удалить сохранённый ключ</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p>Просмотр состояния доступен администратору, но активировать или удалить installation-wide лицензию может только суперадминистратор.</p>
        <?php endif; ?>
    </section>
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
