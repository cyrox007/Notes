<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$details = is_array($notification_settings ?? null) ? $notification_settings : [];
$mail = is_array($details['smtp'] ?? null) ? $details['smtp'] : [];
$config = is_array($mail['settings'] ?? null) ? $mail['settings'] : [];
$jobs = is_array($details['jobs'] ?? null) ? $details['jobs'] : [];
$attempts = is_array($details['attempts'] ?? null) ? $details['attempts'] : [];
$externalChannels = is_array($external_channels ?? null) ? $external_channels : [];
$webhook = [];
foreach ($externalChannels as $row) {
    if (($row['channel'] ?? null) === 'webhook' && $webhook === []) {
        $webhook = is_array($row['settings'] ?? null) ? $row['settings'] : [];
    }
}
$counts = is_array($details['counts'] ?? null) ? $details['counts'] : [];
$siteName = isset($sitename) ? (string) $sitename : 'Notes';
$workspaceVersion = isset($version) ? (string) $version : '';
$baseUrl = isset($base_url) ? rtrim((string) $base_url, '/') : '';
$currentUser = isset($user) && is_array($user) ? $user : [];
$access = isset($workspaceAccess) && is_array($workspaceAccess) ? $workspaceAccess : [];
ob_start();
?>
<section class="admin-page admin-notification-settings" data-notification-administration>
    <header class="admin-page__hero">
        <div>
            <span class="admin-page__eyebrow">Надёжная доставка</span>
            <h1>Каналы уведомлений</h1>
            <p>Настройте реальную отправку, проверьте получение пробного сообщения и посмотрите причины ошибок.</p>
        </div>
    </header>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2>Электронная почта — SMTP</h2>
                <p>Пароль хранится только в переменной окружения сервера. В базу сохраняется её имя, без значения секрета.</p>
            </div>
        </div>
        <p>
            <strong>Состояние:</strong>
            <?= empty($mail['enabled']) ? 'отключён' : 'включён' ?> ·
            <?= empty($mail['secret_configured']) ? 'секрет не указан' : (!empty($mail['secret_available']) ? 'секрет доступен' : 'секрет не найден в окружении') ?>
        </p>
        <form action="<?= $view->e($view->route('admin_notifications_smtp')) ?>" method="post"
              class="custom-fields-form" data-notification-ajax-form autocomplete="off">
            <?= $view->csrfInput() ?>
            <div class="admin-notification-fields">
                <label class="custom-field__control">SMTP-сервер
                    <input type="text" name="host" maxlength="253" required
                           value="<?= $view->e($config['host'] ?? '') ?>" placeholder="smtp.example.org">
                </label>
                <label class="custom-field__control">Порт
                    <input type="number" name="port" min="1" max="65535" required
                           value="<?= $view->e($config['port'] ?? 587) ?>">
                </label>
                <label class="custom-field__control">Защита соединения
                    <select name="encryption">
                        <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'TLS с подключения', 'none' => 'Без TLS (только без авторизации)'] as $value => $label): ?>
                            <option value="<?= $view->e($value) ?>"<?= ($config['encryption'] ?? 'tls') === $value ? ' selected' : '' ?>><?= $view->e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="custom-field__control">Имя для авторизации
                    <input type="text" name="username" maxlength="190" autocomplete="off"
                           value="<?= $view->e($config['username'] ?? '') ?>">
                </label>
                <label class="custom-field__control">Адрес отправителя
                    <input type="email" name="from_email" required
                           value="<?= $view->e($config['from_email'] ?? '') ?>">
                </label>
                <label class="custom-field__control">Отображаемое имя
                    <input type="text" name="from_name" maxlength="190" required
                           value="<?= $view->e($config['from_name'] ?? 'Notes') ?>">
                </label>
                <label class="custom-field__control">Секрет окружения
                    <input type="text" name="secret_ref" pattern="env:[A-Z][A-Z0-9_]{1,127}"
                           placeholder="<?= !empty($mail['secret_configured']) ? 'Оставьте пустым, чтобы сохранить прежний' : 'env:NOTES_SMTP_PASSWORD' ?>"
                           autocomplete="off">
                    <small>Укажите имя переменной: <code>env:NOTES_SMTP_PASSWORD</code>. Сам пароль в форму не вводится.</small>
                </label>
                <label class="custom-field__control">Канал
                    <select name="enabled">
                        <option value="1"<?= !empty($mail['enabled']) ? ' selected' : '' ?>>Включён</option>
                        <option value="0"<?= empty($mail['enabled']) ? ' selected' : '' ?>>Отключён</option>
                    </select>
                </label>
                <label class="custom-field__control">Текущий пароль администратора
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить настройки</button>
        </form>
        <p>Для доставки обычных уведомлений пользователь должен разрешить E-mail в настройках своей категории. Обработчик очереди запускается по расписанию; резервный HTTP-проход включён как страховка.</p>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2>Проверка настоящей доставки</h2>
                <p>Пробное уведомление создаётся в общем центре и проходит через штатную очередь. Для электронной почты используется адрес вашей администраторской учётной записи; интервал — не чаще одного раза в минуту для каждого канала.</p>
            </div>
        </div>
        <form action="<?= $view->e($view->route('admin_notifications_test')) ?>" method="post"
              data-notification-ajax-form>
            <?= $view->csrfInput() ?>
            <button class="admin-action admin-action--secondary" type="submit">Отправить пробное письмо</button>
        </form>
        <p><strong>Важно:</strong> статус «отправлено» означает, что SMTP-сервер принял письмо, а не что оно прочитано получателем. Проверьте входящие и спам. При состоянии «ожидание» нужен действующий обработчик очереди.</p>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2>Состояние очереди</h2>
                <p>Последний резервный запуск через HTTP: <?= $view->e($details['last_http_run'] ?: 'не выполнялся') ?>. Отдельный cron из PHP определить надёжно нельзя — проверьте планировщик сервера.</p>
            </div>
        </div>
        <?php if ($counts !== []): ?>
        <div class="admin-notification-counts">
            <?php foreach ($counts as $item): ?>
                <span><?= $view->e(($item['channel'] ?? '—') . ' · ' . ($item['state'] ?? '—')) ?>: <strong><?= (int) ($item['total'] ?? 0) ?></strong></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Задание</th><th>Канал</th><th>Состояние</th><th>Попыток</th><th>Последний код</th></tr></thead>
                <tbody>
                    <?php foreach ($jobs as $job): ?>
                    <tr>
                        <td><?= (int) ($job['id'] ?? 0) ?> · <?= $view->e($job['created_at'] ?? '') ?></td>
                        <td><?= $view->e($job['channel'] ?? '') ?></td>
                        <td><?= $view->e($job['state'] ?? '') ?></td>
                        <td><?= (int) ($job['attempts'] ?? 0) ?></td>
                        <td><?= $view->e($job['last_result_code'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($jobs === []): ?><tr><td colspan="5">Пока нет попыток внешней доставки.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <h3>Последние попытки доставки</h3>
        <p>Здесь отображаются только результат и код ошибки. Адреса получателей, содержимое уведомлений и секреты не раскрываются. Значение «отправлено» подтверждает принятие внешним сервисом, но не получение человеком.</p>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Задание</th><th>Канал</th><th>Попытка</th><th>Результат</th><th>Код причины</th><th>Время (UTC)</th></tr></thead>
                <tbody>
                    <?php foreach ($attempts as $attempt): ?>
                    <tr>
                        <td><?= (int) ($attempt['job_id'] ?? 0) ?></td>
                        <td><?= $view->e($attempt['channel'] ?? '—') ?></td>
                        <td><?= (int) ($attempt['attempt_no'] ?? 0) ?></td>
                        <td><?= $view->e($attempt['result_state'] ?? '—') ?></td>
                        <td><code><?= $view->e($attempt['result_code'] ?? '—') ?></code></td>
                        <td><?= $view->e($attempt['attempted_at'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if ($attempts === []): ?>
                        <tr><td colspan="6">Попыток доставки пока нет. Проверьте настройки каналов, подписки и работу планировщика.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('admin_notifications')) ?>">Обновить состояние</a>
    </section>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2>Подписанный Webhook</h2>
                <p>Только публичный HTTPS-адрес, подпись HMAC-SHA256 и минимальные метаданные. Внешней системе не передаются тексты сообщений и персональные данные.</p>
            </div>
        </div>
        <form action="<?= $view->e($view->route('admin_notifications_external')) ?>" method="post"
              data-notification-ajax-form class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="webhook">
            <div class="admin-notification-fields">
                <label class="custom-field__control">HTTPS-адрес
                    <input type="url" name="url" required placeholder="https://hooks.example.org/notes"
                           value="<?= $view->e($webhook['url'] ?? '') ?>">
                </label>
                <label class="custom-field__control">Секрет подписи
                    <input type="text" name="secret_ref" pattern="env:[A-Z][A-Z0-9_]{1,127}"
                           placeholder="env:NOTES_WEBHOOK_KEY" autocomplete="off">
                    <small>Минимум 32 символа в переменной окружения.</small>
                </label>
                <label class="custom-field__control">Состояние
                    <select name="enabled"><option value="1">Включить</option><option value="0">Отключить</option></select>
                </label>
                <label class="custom-field__control">Пароль администратора
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить Webhook</button>
        </form>
        <form action="<?= $view->e($view->route('admin_notifications_test')) ?>" method="post"
              data-notification-ajax-form><?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="webhook">
            <button type="submit" class="admin-action admin-action--secondary">Проверить Webhook для себя</button>
        </form>
    </section>

    <?php foreach (['telegram' => 'Telegram', 'max' => 'MAX'] as $channelKey => $label): ?>
    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2><?= $view->e($label) ?></h2>
                <p>Адресат закрепляется за определённым пользователем. Общий чат для всех пользователей не используется. Сам текст личных сообщений во внешний сервис не отправляется.</p>
            </div>
        </div>
        <form action="<?= $view->e($view->route('admin_notifications_external')) ?>" method="post"
              data-notification-ajax-form class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="<?= $view->e($channelKey) ?>">
            <div class="admin-notification-fields">
                <label class="custom-field__control">Идентификатор пользователя Notes
                    <input type="number" name="owner_user_id" min="1" required>
                </label>
                <?php if ($channelKey === 'telegram'): ?>
                <label class="custom-field__control">Chat ID Telegram
                    <input type="text" name="chat_id" required placeholder="123456789">
                </label>
                <?php else: ?>
                <label class="custom-field__control">ID пользователя MAX
                    <input type="text" name="max_user_id" required placeholder="123456789">
                </label>
                <?php endif; ?>
                <label class="custom-field__control">Токен бота
                    <input type="text" name="secret_ref" pattern="env:[A-Z][A-Z0-9_]{1,127}"
                           placeholder="env:NOTES_BOT_TOKEN" autocomplete="off">
                    <small>Укажите имя переменной окружения, а не значение токена.</small>
                </label>
                <label class="custom-field__control">Состояние
                    <select name="enabled"><option value="1">Включить</option><option value="0">Отключить</option></select>
                </label>
                <label class="custom-field__control">Пароль администратора
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button class="admin-action admin-action--primary" type="submit">Сохранить <?= $view->e($label) ?></button>
        </form>
        <form action="<?= $view->e($view->route('admin_notifications_test')) ?>" method="post"
              data-notification-ajax-form><?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="<?= $view->e($channelKey) ?>">
            <button type="submit" class="admin-action admin-action--secondary">Пробная доставка себе в <?= $view->e($label) ?></button>
        </form>
    </section>
    <?php endforeach; ?>

    <section class="admin-panel-card">
        <div class="admin-panel-card__header">
            <div>
                <h2>SMS через отдельный HTTPS-шлюз</h2>
                <p>Исходящие уведомления без текстов переписки. Шлюз должен принимать JSON с полями recipient, text и delivery_id; внутренняя локальная сеть и произвольные HTTP-адреса не допускаются.</p>
            </div>
        </div>
        <form action="<?= $view->e($view->route('admin_notifications_external')) ?>" method="post"
              data-notification-ajax-form class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="sms">
            <div class="admin-notification-fields">
                <label class="custom-field__control">Получатель Notes
                    <input type="number" name="owner_user_id" min="1" required>
                </label>
                <label class="custom-field__control">Телефон E.164
                    <input type="tel" name="phone" pattern="\\+[1-9][0-9]{7,14}" required placeholder="+79990000000">
                </label>
                <label class="custom-field__control">Внешний HTTPS-адрес SMS-шлюза
                    <input type="url" name="url" required placeholder="https://sms-gateway.example.org/send">
                </label>
                <label class="custom-field__control">Секрет API шлюза
                    <input type="text" name="secret_ref" pattern="env:[A-Z][A-Z0-9_]{1,127}"
                           placeholder="env:NOTES_SMS_GATEWAY_KEY" autocomplete="off">
                    <small>Пароль должен находиться в переменной окружения PHP и не записывается в БД.</small>
                </label>
                <label class="custom-field__control">Канал
                    <select name="enabled"><option value="1">Включить</option><option value="0">Отключить</option></select>
                </label>
                <label class="custom-field__control">Пароль администратора
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button type="submit" class="admin-action admin-action--primary">Сохранить SMS-шлюз</button>
        </form>
        <form action="<?= $view->e($view->route('admin_notifications_test')) ?>" method="post"
              data-notification-ajax-form>
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="sms">
            <button type="submit" class="admin-action admin-action--secondary">Пробная SMS на мой номер</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <h2>Настроенные внешние адресаты</h2>
        <?php if ($externalChannels === []): ?>
        <p>Дополнительных адресатов пока нет.</p>
        <?php else: ?>
        <div class="admin-users-table-wrap">
            <table class="admin-users-table">
                <thead><tr><th>Канал</th><th>Пользователь</th><th>Назначение</th><th>Включён</th><th>Секрет</th></tr></thead>
                <tbody>
                <?php foreach ($externalChannels as $entry): ?>
                    <tr>
                        <td><?= $view->e($entry['channel'] ?? '') ?></td>
                        <td><?= $view->e($entry['owner_user_id'] ?? 'системный') ?></td>
                        <td><?= $view->e(($entry['settings']['chat_id'] ?? $entry['settings']['user_id'] ?? $entry['settings']['phone'] ?? $entry['settings']['url'] ?? '—')) ?></td>
                        <td><?= !empty($entry['enabled']) ? 'Да' : 'Нет' ?></td>
                        <td><?= !empty($entry['has_secret']) ? 'Задан в окружении' : 'Нет' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="admin-panel-card">
        <h2>Браузерные Push-уведомления</h2>
        <p>Пробное уведомление направляется только на устройства, привязанные к вашей учётной записи. Сначала разрешите уведомления браузера и зарегистрируйте устройство в пользовательских настройках.</p>
        <form action="<?= $view->e($view->route('admin_notifications_test')) ?>" method="post"
              data-notification-ajax-form>
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="browser">
            <button type="submit" class="admin-action admin-action--secondary">Проверить Push на моих устройствах</button>
        </form>
        <p>Приём Push-сервисом не гарантирует появление уведомления на устройстве. Проверьте разрешения браузера и показ при закрытой вкладке.</p>
    </section>
    <p data-notification-feedback role="status" aria-live="polite" hidden></p>
</section>
<?php
$content = (string) ob_get_clean();
echo $view->layout('core/base', [
    'title' => 'Каналы уведомлений',
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
        $view->moduleAsset('admin', 'notification-settings.js'),
    ],
], $content);
