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
$licenseCode = (string) ($licenseState['code'] ?? 'unknown');
$statusClass = $isValid ? 'success' : ($licenseCode === 'unlicensed' ? 'warning' : 'error');

ob_start();
?>
<section class="license-recovery" aria-labelledby="license-recovery-title">
    <a class="license-recovery__back" href="<?= $view->e($view->route('main')) ?>">
        <i class="fa fa-arrow-left" aria-hidden="true"></i>
        Вернуться в рабочее пространство
    </a>

    <header class="license-recovery__hero">
        <span class="license-recovery__kicker">Core recovery</span>
        <h1 id="license-recovery-title">Лицензия установки</h1>
        <p>Системная recovery-страница Core. Она остаётся доступной даже при отключённом модуле Admin и позволяет восстановить installation-wide лицензию.</p>
    </header>

    <?php if ($flash !== null): ?>
        <div class="license-recovery__flash" role="status">
            <strong><?= $view->e($flash['message'] ?? '') ?></strong>
        </div>
    <?php endif; ?>

    <section class="license-recovery__card" aria-labelledby="license-installation-title">
        <div class="license-recovery__card-header">
            <div>
                <span class="license-recovery__kicker">Идентичность установки</span>
                <h2 id="license-installation-title">Installation ID</h2>
                <p>Этот идентификатор привязывает подписанный лицензионный ключ к конкретной установке.</p>
            </div>
        </div>
        <div class="license-recovery__installation">
            <code><?= $view->e($licenseState['installation_id'] ?? '') ?></code>
        </div>
    </section>

    <section class="license-recovery__card" aria-labelledby="license-state-title">
        <div class="license-recovery__card-header">
            <div>
                <span class="license-recovery__kicker">Состояние</span>
                <h2 id="license-state-title"><?= $isValid ? 'Лицензия действительна' : 'Лицензия не подтверждена' ?></h2>
                <p><?= $view->e($licenseState['message'] ?? 'Состояние лицензии неизвестно') ?></p>
            </div>
            <span class="license-recovery__status license-recovery__status--<?= $view->e($statusClass) ?>">
                <?= $view->e($licenseCode) ?>
            </span>
        </div>

        <dl class="license-recovery__grid">
            <div class="license-recovery__metric">
                <dt>Код</dt>
                <dd><?= $view->e($licenseCode) ?></dd>
            </div>
            <div class="license-recovery__metric">
                <dt>License ID</dt>
                <dd><?= $view->e($licenseState['license_id'] ?? '—') ?></dd>
            </div>
            <div class="license-recovery__metric">
                <dt>Редакция</dt>
                <dd><?= $view->e($licenseState['edition'] ?? '—') ?></dd>
            </div>
            <div class="license-recovery__metric">
                <dt>Ключ подписи</dt>
                <dd><?= $view->e($licenseState['key_id'] ?? '—') ?></dd>
            </div>
        </dl>

        <?php if (!$trustConfigured): ?>
            <div class="license-recovery__alert" role="alert">
                <strong>Активация недоступна:</strong>
                в сборке не настроен production public key проверки лицензий.
            </div>
        <?php endif; ?>
    </section>

    <section class="license-recovery__card" aria-labelledby="license-activation-title">
        <div class="license-recovery__card-header">
            <div>
                <span class="license-recovery__kicker">Активация</span>
                <h2 id="license-activation-title">Подписанный лицензионный ключ</h2>
                <p>Ключ проверяется до сохранения. Неверная подпись или ключ другой установки не заменят текущее состояние.</p>
            </div>
        </div>

        <?php if ($canManage): ?>
            <form class="license-recovery__form" action="<?= $view->e($view->route('system_license_activate')) ?>" method="post">
                <?= $view->csrfInput() ?>
                <div class="license-recovery__field">
                    <label for="license_token">Лицензионный ключ</label>
                    <textarea
                        id="license_token"
                        name="license_token"
                        rows="6"
                        maxlength="16384"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        placeholder="wo1.&lt;kid&gt;.&lt;payload&gt;.&lt;signature&gt;"
                        <?= $trustConfigured ? 'required' : 'disabled' ?>
                    ></textarea>
                </div>
                <div class="license-recovery__actions">
                    <button class="license-recovery__button" type="submit" <?= $trustConfigured ? '' : 'disabled' ?>>
                        Проверить и активировать
                    </button>
                </div>
            </form>

            <?php if ($hasToken): ?>
                <form class="license-recovery__form" action="<?= $view->e($view->route('system_license_clear')) ?>" method="post">
                    <?= $view->csrfInput() ?>
                    <div class="license-recovery__actions">
                        <button class="license-recovery__button license-recovery__button--secondary" type="submit">
                            Удалить сохранённый ключ
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p class="license-recovery__notice">Просмотр доступен администратору, но изменить installation-wide лицензию может только суперадминистратор.</p>
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
    'module_styles' => [
        $baseUrl . '/assets/css/license-recovery.css',
    ],
], $content);
