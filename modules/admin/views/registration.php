<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$mode = isset($registration_mode) ? (string) $registration_mode : 'disabled';
$invites = isset($registration_invites) && is_array($registration_invites) ? $registration_invites : [];
$flash = isset($registration_flash) && is_array($registration_flash) ? $registration_flash : null;
$legacyInviteConfigured = !empty($legacy_invite_configured);
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$statusLabels = [
    'active' => 'Активен',
    'revoked' => 'Отозван',
    'expired' => 'Просрочен',
    'exhausted' => 'Исчерпан',
];

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Доступ к Workspace</span>
            <h1>Регистрация пользователей</h1>
            <p>Управляйте публичной регистрацией и приглашениями. Создание пользователей администратором доступно в основном разделе админпанели.</p>
        </div>
        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('adminpanel')) ?>">Назад в админпанель</a>
    </header>

    <?php if ($flash !== null): ?>
        <?php $flashType = in_array(($flash['type'] ?? ''), ['success', 'error'], true) ? (string) $flash['type'] : 'error'; ?>
        <div class="admin-page__flash admin-page__flash--<?= $view->e($flashType) ?>" role="status">
            <?= $view->e($flash['message'] ?? '') ?>
            <?php if (!empty($flash['invite_code'])): ?>
                <div class="custom-field__control custom-field__control--spaced">
                    <label for="new_invite_code">Новый код приглашения</label>
                    <input id="new_invite_code" type="text" readonly value="<?= $view->e($flash['invite_code']) ?>" data-select-on-click>
                    <small>Код хранится в системе только в виде SHA-256 hash и повторно показан не будет.</small>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">Политика доступа</span><h2>Режим регистрации</h2><p>По умолчанию регистрация закрыта. Изменение режима не влияет на уже созданные аккаунты.</p></div>
        </div>
        <form action="<?= $view->e($view->route('admin_registration_mode')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="custom-field__control">
                <label for="registration_mode">Режим</label>
                <select id="registration_mode" name="registration_mode" required>
                    <option value="disabled"<?= $mode === 'disabled' ? ' selected' : '' ?>>Закрыта — пользователей создаёт администратор</option>
                    <option value="open"<?= $mode === 'open' ? ' selected' : '' ?>>Свободная регистрация</option>
                    <option value="invite"<?= $mode === 'invite' ? ' selected' : '' ?>>Только по инвайтам</option>
                </select>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить режим</button>
        </form>
        <?php if ($legacyInviteConfigured): ?>
            <p class="form-hint">Обнаружен legacy <code>REGISTRATION_INVITE_CODE</code> в <code>.env</code>. Он используется только пока новый режим ещё не сохранён в системных настройках. После сохранения режима управляйте приглашениями здесь.</p>
        <?php endif; ?>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">Invite-only</span><h2>Создать приглашение</h2><p>Код показывается один раз. В базе хранится только hash, срок действия и счётчик использований.</p></div>
        </div>
        <form action="<?= $view->e($view->route('admin_registration_invite_create')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="custom-field__grid">
                <div class="custom-field__control"><label for="invite_label">Название</label><input id="invite_label" name="label" type="text" maxlength="100" placeholder="Например, команда разработки"></div>
                <div class="custom-field__control"><label for="invite_max_uses">Использований</label><input id="invite_max_uses" name="max_uses" type="number" min="1" max="1000" value="1" required></div>
                <div class="custom-field__control"><label for="invite_expires_at">Действует до</label><input id="invite_expires_at" name="expires_at" type="datetime-local"></div>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Создать инвайт</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div><span class="admin-panel-card__kicker">Приглашения</span><h2>Управляемые инвайты</h2><p>Отозванные, просроченные и полностью использованные коды больше не допускают регистрацию.</p></div>
        </div>
        <?php if ($invites !== []): ?>
            <div class="admin-users-table-wrap">
                <table class="admin-users-table">
                    <thead><tr><th>Название</th><th>Использовано</th><th>Срок</th><th>Статус</th><th>Действие</th></tr></thead>
                    <tbody>
                    <?php foreach ($invites as $invite): ?>
                        <?php if (!is_array($invite)) { continue; } ?>
                        <?php $status = (string) ($invite['status'] ?? 'exhausted'); ?>
                        <tr>
                            <td data-label="Название"><strong><?= $view->e($invite['label'] ?? '') ?></strong><br><small><?= $view->e($invite['created_at'] ?? '') ?></small></td>
                            <td data-label="Использовано"><?= $view->e($invite['used_count'] ?? 0) ?>/<?= $view->e($invite['max_uses'] ?? 0) ?></td>
                            <td data-label="Срок"><?= !empty($invite['expires_at']) ? $view->e($invite['expires_at']) : 'Без срока' ?></td>
                            <td data-label="Статус"><?= $view->e($statusLabels[$status] ?? 'Исчерпан') ?></td>
                            <td data-label="Действие">
                                <?php if ($status === 'active'): ?>
                                    <form action="<?= $view->e($view->route('admin_registration_invite_revoke')) ?>" method="post">
                                        <?= $view->csrfInput() ?>
                                        <input type="hidden" name="invite_id" value="<?= $view->e($invite['id'] ?? '') ?>">
                                        <button type="submit" class="admin-action admin-action--danger">Отозвать</button>
                                    </form>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p>Управляемых инвайтов пока нет.</p>
        <?php endif; ?>
    </section>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Регистрация пользователей',
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
