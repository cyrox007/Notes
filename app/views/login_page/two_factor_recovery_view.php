<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$codes = isset($recovery_codes) && is_array($recovery_codes) ? $recovery_codes : [];

ob_start();
?>
<div class="login-page">
    <div class="login-page__box">
        <div class="login-page__brand" aria-label="<?= $view->e($siteName) ?>">
            <span class="login-page__brand-mark" aria-hidden="true">W</span>
            <span class="login-page__brand-copy">
                <strong><?= $view->e($siteName) ?></strong>
                <small>Workspace <?= $view->e($workspaceVersion) ?></small>
            </span>
        </div>

        <div class="login-page__card">
            <h1 class="login-page__title">Сохраните резервные коды</h1>
            <p class="login-page__subtitle">
                Эти коды показываются только один раз. Каждый код можно использовать один раз вместо TOTP,
                если приложение-аутентификатор временно недоступно.
            </p>
            <ul>
                <?php foreach ($codes as $code): ?>
                    <?php if (!is_string($code)) { continue; } ?>
                    <li><code><?= $view->e($code) ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p class="login-link"><a href="<?= $view->e($view->route('main')) ?>">Я сохранил коды — открыть Workspace</a></p>
        </div>
    </div>
</div>
<?php
$content = (string) ob_get_clean();
echo $view->layout('login_page/login_layout', [
    'title' => 'Резервные коды 2FA',
    'sitename' => $siteName,
    'base_url' => $base_url ?? '',
], $content);
