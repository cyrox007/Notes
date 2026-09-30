<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
$storageUsers = isset($storage_users) && is_array($storage_users) ? $storage_users : [];
$flash = isset($settings_flash) && is_array($settings_flash) ? $settings_flash : null;
$defaultQuotaBytes = max(0, (int) ($default_quota_bytes ?? 0));
$uploadLimitBytes = max(0, (int) ($upload_limit_bytes ?? 0));
$uploadDiagnostics = isset($upload_limit_diagnostics) && is_array($upload_limit_diagnostics) ? $upload_limit_diagnostics : [];
$twoFactorRequired = !empty($two_factor_required);
$filesEnabled = !empty($files_enabled);
$siteName = isset($sitename) ? (string) $sitename : 'Workspace Organizer';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$bytesToMb = static fn (mixed $bytes, int $precision = 2): string => (string) round(max(0, (int) $bytes) / 1048576, $precision);
$formatBytes = static function (mixed $bytes): string {
    $value = max(0, (int) $bytes);
    if ($value >= 1073741824) {
        return round($value / 1073741824, 2) . ' ГБ';
    }
    return round($value / 1048576, 2) . ' МБ';
};

ob_start();
?>
<section class="admin-page">
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Хранилище Workspace</span>
            <h1>Системные настройки</h1>
            <p>Общие политики безопасности и параметры включённых системных модулей.</p>
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

    <?php if ($filesEnabled): ?>
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
            <div>
                <span class="admin-panel-card__kicker">Загрузка файлов</span>
                <h2>Максимальный размер одного файла</h2>
                <p>Workspace ограничивает размер файла самостоятельно и одновременно проверяет известные ограничения PHP и веб-сервера.</p>
            </div>
        </div>

        <form action="<?= $view->e($view->route('admin_settings_upload_limit')) ?>" method="post" class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="custom-field__control">
                <label for="max_upload_mb">Лимит Workspace, МБ</label>
                <input id="max_upload_mb" name="max_upload_mb" type="number" min="1" max="10485760" step="1"
                       value="<?= $view->e(round($uploadLimitBytes / 1048576)) ?>" required>
                <small>Это прикладной лимит одного файла. Персональная квота хранилища пользователя проверяется отдельно. Ролевая политика может дополнительно уменьшить допустимый размер для отдельных групп пользователей.</small>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить лимит загрузки</button>
        </form>

        <div class="admin-upload-diagnostics">
            <h3>Диагностика ограничений сервера</h3>
            <div class="admin-users-table-wrap">
                <table class="admin-users-table">
                    <tbody>
                        <tr><th>Workspace</th><td><?= $view->e($formatBytes($uploadDiagnostics['configured_bytes'] ?? $uploadLimitBytes)) ?></td></tr>
                        <tr><th>PHP upload_max_filesize</th><td><?= $view->e($uploadDiagnostics['php_upload_max_filesize'] ?? 'не удалось определить') ?></td></tr>
                        <tr><th>PHP post_max_size</th><td><?= $view->e($uploadDiagnostics['php_post_max_size'] ?? 'не удалось определить') ?></td></tr>
                        <tr><th>Активный php.ini</th><td><code><?= $view->e($uploadDiagnostics['php_ini_file'] ?? 'не удалось определить') ?></code></td></tr>
                        <tr><th>Веб-сервер</th><td><?= $view->e($uploadDiagnostics['web_server_software'] ?? 'не удалось определить') ?></td></tr>
                        <tr>
                            <th>Лимит веб-сервера</th>
                            <td>
                                <?php if (!empty($uploadDiagnostics['web_server_limit_known'])): ?>
                                    <?= ($uploadDiagnostics['web_server_limit_bytes'] ?? null) !== null
                                        ? $view->e($formatBytes($uploadDiagnostics['web_server_limit_bytes']))
                                        : 'отдельный конечный лимит не обнаружен' ?>
                                    <small><?= $view->e($uploadDiagnostics['web_server_limit_source'] ?? '') ?></small>
                                <?php else: ?>
                                    не удалось определить автоматически
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr><th>Известный фактический потолок файла</th><td><?= $view->e($formatBytes($uploadDiagnostics['effective_known_file_bytes'] ?? $uploadLimitBytes)) ?></td></tr>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($uploadDiagnostics['conflict'])): ?>
                <div class="admin-page__flash admin-page__flash--error" role="alert">
                    <strong>Обнаружен конфликт настроек.</strong>
                    <ul>
                        <?php foreach (($uploadDiagnostics['conflicts'] ?? []) as $conflict): ?>
                            <li><?= $view->e($conflict) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php elseif (empty($uploadDiagnostics['web_server_limit_known'])): ?>
                <div class="admin-page__flash" role="status">
                    PHP допускает заданный размер по известным параметрам, но активный лимит веб-сервера не удалось прочитать из приложения.
                    Проверьте конфигурацию ниже.
                </div>
            <?php else: ?>
                <div class="admin-page__flash admin-page__flash--success" role="status">Известные ограничения согласованы с лимитом Workspace.</div>
            <?php endif; ?>

            <details>
                <summary>Как исправить конфликт лимитов</summary>
                <p>После изменения конфигурации перезапустите соответствующий PHP/Web-сервер и снова откройте эту страницу.</p>
                <h4>PHP</h4>
                <p>В активном <code>php.ini</code> установите значения не ниже:</p>
                <pre>upload_max_filesize = <?= $view->e($uploadDiagnostics['recommended_upload_max_filesize'] ?? '') . "\n" ?>post_max_size = <?= $view->e($uploadDiagnostics['recommended_post_max_size'] ?? '') ?></pre>

                <?php $serverType = (string) ($uploadDiagnostics['web_server_type'] ?? 'other'); ?>
                <?php if ($serverType === 'apache'): ?>
                    <h4>Apache</h4>
                    <p>Проверьте <code>LimitRequestBody</code> в конфигурации VirtualHost/Directory или <code>.htaccess</code>. Значение задаётся в байтах и должно быть не меньше полного HTTP-запроса:</p>
                    <pre>LimitRequestBody <?= $view->e($uploadDiagnostics['required_request_bytes'] ?? '') ?></pre>
                <?php elseif ($serverType === 'nginx'): ?>
                    <h4>Nginx</h4>
                    <p>В нужном блоке <code>http</code>, <code>server</code> или <code>location</code> задайте:</p>
                    <pre>client_max_body_size <?= $view->e($uploadDiagnostics['recommended_post_max_size'] ?? '') ?>;</pre>
                <?php elseif ($serverType === 'iis'): ?>
                    <h4>IIS</h4>
                    <p>Проверьте Request Filtering → <code>requestLimits.maxAllowedContentLength</code>. Значение задаётся в байтах и должно быть не меньше:</p>
                    <pre><?= $view->e($uploadDiagnostics['required_request_bytes'] ?? '') ?></pre>
                <?php else: ?>
                    <h4>Веб-сервер или reverse proxy</h4>
                    <p>Проверьте его максимальный размер тела HTTP-запроса. Он должен быть не меньше <?= $view->e($formatBytes($uploadDiagnostics['required_request_bytes'] ?? 0)) ?>.</p>
                <?php endif; ?>

                <p>Если лимит веб-сервера задаётся вне доступной приложению конфигурации, для точной диагностики можно передать его Workspace через переменную окружения <code>WEB_SERVER_MAX_UPLOAD_SIZE</code> в байтах или в формате <code>100M</code>. Эта переменная только сообщает приложению фактический внешний предел и сама конфигурацию сервера не изменяет.</p>
            </details>
        </div>
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
    <?php endif; ?>
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
