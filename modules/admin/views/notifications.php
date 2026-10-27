<?php

declare(strict_types=1);

/** @var \Core\NativeViewRenderer $view */
$details = is_array($notification_settings ?? null) ? $notification_settings : [];
$mail = is_array($details['smtp'] ?? null) ? $details['smtp'] : [];
$smtpReadiness = is_array($details['smtp_readiness'] ?? null) ? $details['smtp_readiness'] : [];
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
$scheduler = is_array($reminder_scheduler ?? null) ? $reminder_scheduler : [];
$deliveryWorker = is_array($notification_worker ?? null) ? $notification_worker : [];
$schedulerStates = [
    'healthy' => 'Работает',
    'running' => 'Выполняется',
    'late' => 'Нет свежих запусков',
    'interrupted' => 'Возможное прерывание',
    'failed' => 'Ошибка обработки',
    'never_started' => 'Не запускался',
    'unavailable' => 'Недоступен',
    'disabled' => 'Модуль отключён',
    'unknown' => 'Неизвестно',
];
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

    <section class="admin-panel-card admin-notification-panel--wide admin-notification-panel--smtp">
        <div class="admin-panel-card__header">
            <div>
                <h2>Электронная почта — SMTP</h2>
                <p>Пароль хранится только в переменной окружения сервера. В базу сохраняется её имя, без значения секрета.</p>
            </div>
        </div>
        <p>
            <strong>Состояние:</strong>
            <?= empty($mail['enabled']) ? 'отключён' : 'включён' ?> ·
            <?= trim((string) ($config['username'] ?? '')) === ''
                ? 'без SMTP-авторизации (пароль не требуется)'
                : (empty($mail['secret_configured']) ? 'секрет не указан'
                    : (!empty($mail['secret_available']) ? 'секрет доступен' : 'секрет не найден в окружении')) ?>
        </p>
        <div role="status" aria-live="polite">
            <strong>Проверка готовности:</strong>
            <?php if (($smtpReadiness['state'] ?? '') === 'blocked'): ?>
                Обнаружены препятствия для отправки.
            <?php else: ?>
                Явных препятствий в настройках не найдено. Это не подтверждает доставку письма.
            <?php endif; ?>
            <?php if (!empty($smtpReadiness['blockers']) || !empty($smtpReadiness['warnings'])): ?>
                <ul>
                    <?php foreach (array_merge($smtpReadiness['blockers'] ?? [], $smtpReadiness['warnings'] ?? []) as $finding): ?>
                        <li><?= $view->e((string) ($finding['message'] ?? '')) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <p>Проверяется окружение веб-процесса. У отдельного обработчика оно может отличаться.
                Итоговый признак работоспособности — тестовое письмо и подтверждение получения адресатом.</p>
        </div>
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

    <section class="admin-panel-card admin-notification-panel--wide">
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

    <section class="admin-panel-card" aria-labelledby="delivery-worker-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Внешняя доставка</span>
                <h2 id="delivery-worker-title">Состояние отдельного обработчика уведомлений</h2>
                <p>Последний завершённый проход команды обработки очереди. Это не проверка установки cron или получения сообщений.</p>
            </div>
        </div>
        <p role="status">
            <strong>Состояние: <?= $view->e($schedulerStates[(string) ($deliveryWorker['state'] ?? 'unknown')] ?? 'Неизвестно') ?>.</strong>
            <?= $view->e($deliveryWorker['message'] ?? 'Нет сведений.') ?>
        </p>
        <?php if (!empty($deliveryWorker['last_success_utc'])): ?>
            <p>Последний успешный проход (UTC):
                <strong><?= $view->e($deliveryWorker['last_success_utc']) ?></strong>.
                <?php if (isset($deliveryWorker['minutes_since_success'])): ?>
                    Давность: <?= (int) $deliveryWorker['minutes_since_success'] ?> мин.
                <?php endif; ?>
            </p>
        <?php endif; ?>
        <p>При расписании раз в 15 минут отсутствие успешного прохода более 45 минут требует проверки
            системного задания, пути PHP и журнала сервера. Резервный HTTP-проход не заменяет этот показатель.</p>
    </section>

    <section class="admin-panel-card" aria-labelledby="tasks-scheduler-title">
        <div class="admin-panel-card__header">
            <div>
                <span class="admin-panel-card__kicker">Фоновая обработка</span>
                <h2 id="tasks-scheduler-title">Планировщик напоминаний Tasks</h2>
                <p>Здесь показаны фактические запуски, а не предполагаемое состояние cron или планировщика Windows.</p>
            </div>
        </div>
        <p role="status">
            <strong>Состояние: <?= $view->e($schedulerStates[(string) ($scheduler['state'] ?? 'unknown')] ?? 'Неизвестно') ?>.</strong>
            <?= $view->e($scheduler['message'] ?? 'Состояние пока не определено.') ?>
        </p>
        <?php if (!empty($scheduler['last_run_utc'])): ?>
            <p>Последний запуск (UTC): <strong><?= $view->e($scheduler['last_run_utc']) ?></strong>.
                Завершён (UTC): <?= $view->e($scheduler['last_finish_utc'] ?: 'не зафиксировано') ?>.
                <?php if (isset($scheduler['minutes_since_start'])): ?>
                    С момента запуска: <?= (int) $scheduler['minutes_since_start'] ?> мин.
                <?php endif; ?>
            </p>
            <div class="admin-notification-counts">
                <span>Утренних сводок: <strong><?= (int) ($scheduler['morning_published'] ?? 0) ?></strong></span>
                <span>Личных напоминаний: <strong><?= (int) ($scheduler['personal_published'] ?? 0) ?></strong></span>
                <span>Командных напоминаний: <strong><?= (int) ($scheduler['board_published'] ?? 0) ?></strong></span>
                <span>Внешних заданий: <strong><?= (int) ($scheduler['delivery_claimed'] ?? 0) ?></strong></span>
                <span>Принято каналами: <strong><?= (int) ($scheduler['delivery_sent'] ?? 0) ?></strong></span>
                <span>Ожидают повтора: <strong><?= (int) ($scheduler['delivery_retry'] ?? 0) ?></strong></span>
                <span>Ошибок доставки: <strong><?= (int) ($scheduler['delivery_failed'] ?? 0) ?></strong></span>
            </div>
            <?php if (!empty($scheduler['failed_stage'])): ?>
                <p>Этап ошибки: <code><?= $view->e($scheduler['failed_stage']) ?></code></p>
            <?php endif; ?>
            <p>За 24 часа: проходов — <strong><?= (int) ($scheduler['runs_24h'] ?? 0) ?></strong>;
                принято каналами — <?= (int) ($scheduler['sent_24h'] ?? 0) ?>;
                повторов — <?= (int) ($scheduler['retry_24h'] ?? 0) ?>;
                ошибок — <?= (int) ($scheduler['failed_24h'] ?? 0) ?>.</p>
        <?php endif; ?>
        <p>Расписание: каждые 15 минут. Если новых проходов нет более 45 минут,
            требуется проверить служебное задание и журнал сервера. Успешная отправка не гарантирует,
            что адресат прочитал или получил сообщение.</p>
        <p>Для установки или проверки расписания используйте серверные сценарии
            <code>tools/scheduler/tasks-reminders-linux.sh</code> (Linux)
            или <code>tools/scheduler/tasks-reminders-windows.ps1</code> (Windows).
            Приложение не создаёт системные задания без решения администратора сервера.</p>
        <a class="admin-action admin-action--secondary"
           href="<?= $view->e($view->route('admin_notifications')) ?>">Обновить состояние</a>
    </section>

    <section class="admin-panel-card admin-notification-panel--wide">
        <div class="admin-panel-card__header">
            <div>
                <h2>Ручная приёмка получения</h2>
                <p>Для контролируемого адресата. Запись делает администратор после личной проверки почтового ящика или устройства; статус внешнего канала сам по себе не доказывает получение.</p>
            </div>
        </div>
        <?php $review = is_array($receipt_review ?? null) ? $receipt_review : []; ?>
        <?php if (!empty($review['unavailable'])): ?>
            <p role="status">Список заданий временно недоступен. Проверьте миграции уведомлений.</p>
        <?php else: ?>
            <?php $reviewPages = is_array($review['pagination'] ?? null) ? $review['pagination'] : []; ?>
            <?php $attentionPage = max(1, (int) ($reviewPages['attention']['page'] ?? 1)); ?>
            <?php $unverifiedPage = max(1, (int) ($reviewPages['unverified']['page'] ?? 1)); ?>
            <?php foreach ([
                'attention' => ['title' => 'Требуют внимания', 'empty' => 'Ошибок и сообщений, не найденных адресатами, нет.'],
                'unverified' => ['title' => 'Ожидают подтверждения', 'empty' => 'Проверок получения пока не требуется.'],
            ] as $group => $labels): ?>
                <h3><?= $view->e($labels['title']) ?></h3>
                <?php $items = is_array($review[$group] ?? null) ? $review[$group] : []; ?>
                <?php if ($items === []): ?>
                    <p><?= $view->e($labels['empty']) ?></p>
                <?php else: ?>
                    <div class="admin-users-table-wrap">
                        <table class="admin-users-table">
                            <thead><tr><th>Задание</th><th>Канал</th><th>Состояние</th><th>Попыток</th><th>Обновлено (UTC)</th><th>Действие</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>№<?= (int) $item['id'] ?></td>
                                        <td><?= $view->e($item['channel']) ?></td>
                                        <td><?= $view->e($item['receipt_state'] === 'not_received'
                                            ? 'Не найдено адресатом'
                                            : ($item['state'] === 'sent' ? 'Принято каналом' : 'Ошибка доставки')) ?></td>
                                        <td><?= (int) $item['attempts'] ?></td>
                                        <td><?= $view->e($item['updated_utc']) ?></td>
                                        <td><a href="<?= $view->e($view->route('admin_notifications')) ?>?inspect_job=<?= (int) $item['id'] ?>&amp;attention_page=<?= $attentionPage ?>&amp;unverified_page=<?= $unverifiedPage ?>">История и проверка</a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php $paging = is_array($reviewPages[$group] ?? null) ? $reviewPages[$group] : []; ?>
                <?php $pageNo = max(1, (int) ($paging['page'] ?? 1)); ?>
                <nav aria-label="<?= $view->e($labels['title']) ?> — страницы">
                    <span>Страница <?= $pageNo ?></span>
                    <?php if (!empty($paging['has_previous'])): ?>
                        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('admin_notifications')) ?>?attention_page=<?= $group === 'attention' ? $pageNo - 1 : $attentionPage ?>&amp;unverified_page=<?= $group === 'unverified' ? $pageNo - 1 : $unverifiedPage ?>">Предыдущая страница</a>
                    <?php endif; ?>
                    <?php if (!empty($paging['has_next'])): ?>
                        <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route('admin_notifications')) ?>?attention_page=<?= $group === 'attention' ? $pageNo + 1 : $attentionPage ?>&amp;unverified_page=<?= $group === 'unverified' ? $pageNo + 1 : $unverifiedPage ?>">Следующая страница</a>
                    <?php endif; ?>
                </nav>
            <?php endforeach; ?>
            <p>На каждой странице не более 20 заданий. В списки не попадают имена, адреса и содержимое уведомлений.</p>
        <?php endif; ?>
        <form method="get" action="<?= $view->e($view->route('admin_notifications')) ?>" class="custom-fields-form">
            <input type="hidden" name="attention_page" value="<?= (int) ($review['pagination']['attention']['page'] ?? 1) ?>">
            <input type="hidden" name="unverified_page" value="<?= (int) ($review['pagination']['unverified']['page'] ?? 1) ?>">
            <label class="custom-field__control">Номер задания для безопасного просмотра
                <input type="number" name="inspect_job" min="1" step="1" required
                       value="<?= $view->e((string) ($_GET['inspect_job'] ?? '')) ?>">
            </label>
            <button class="admin-action admin-action--secondary" type="submit">Проверить состояние и попытки</button>
        </form>
        <?php $inspected = is_array($receipt_inspection ?? null) ? $receipt_inspection : null; ?>
        <?php if ($inspected !== null): ?>
            <?php if (($inspected['status'] ?? '') === 'ok'): ?>
                <div role="status">
                    <p>Задание №<?= (int) $inspected['job_id'] ?>.
                       Канал: <?= $view->e($inspected['channel']) ?>.
                       Состояние очереди: <strong><?= $view->e($inspected['queue_state']) ?></strong>.
                       Принятие транспортом: <?= !empty($inspected['transport_accepted']) ? 'да' : 'нет' ?>.
                       Результат личной проверки:
                       <strong><?= $view->e([
                           'received' => 'получено (подтверждено оператором)',
                           'not_received' => 'не найдено (сообщил оператор)',
                           'not_verified' => 'не проверялось',
                       ][$inspected['recipient_receipt_status']] ?? 'неизвестно') ?></strong>.
                    </p>
                    <p>Создано (UTC): <?= $view->e($inspected['created_utc']) ?>.
                       Принято каналом (UTC): <?= $view->e($inspected['accepted_at_utc'] ?? 'не подтверждено') ?>.
                       Попыток: <?= (int) $inspected['attempts_total'] ?>.</p>
                    <table class="admin-table">
                        <thead><tr><th>Попытка</th><th>Состояние</th><th>Код</th><th>Время UTC</th></tr></thead>
                        <tbody>
                            <?php foreach ($inspected['history'] as $attempt): ?>
                                <tr>
                                    <td><?= (int) $attempt['number'] ?></td>
                                    <td><?= $view->e($attempt['state']) ?></td>
                                    <td><?= $view->e($attempt['code']) ?></td>
                                    <td><?= $view->e($attempt['at_utc']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p role="status">Задание не найдено или журнал недоступен. Проверьте номер и миграции.</p>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($inspected !== null && ($inspected['status'] ?? '') === 'ok'): ?>
        <form action="<?= $view->e($view->route('admin_notifications_receipt')) ?>" method="post"
              data-notification-ajax-form class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <div class="admin-notification-fields">
                <label class="custom-field__control">Номер задания доставки
                    <input type="number" name="job_id" min="1" step="1" required readonly
                           value="<?= (int) $inspected['job_id'] ?>">
                </label>
                <label class="custom-field__control">Результат личной проверки
                    <select name="receipt" required>
                        <option value="received">Сообщение найдено у адресата</option>
                        <option value="not_received">Сообщение не найдено</option>
                    </select>
                </label>
                <label class="custom-field__control">Текущий пароль администратора
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button class="admin-action admin-action--secondary" type="submit">Сохранить результат приёмки</button>
        </form>
        <?php else: ?>
            <p>Сначала выберите задание в одном из списков или введите его номер для просмотра истории.</p>
        <?php endif; ?>
        <p>Свидетельство оператора может быть исправлено повторной проверкой. Адрес, содержание уведомления и секреты в отчёт не включаются.</p>
    </section>

    <section class="admin-panel-card admin-notification-panel--wide">
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
                        <td><?= $view->e($job['last_result_code'] ?? '—') ?>
                            <?php if (!empty($job['guidance'])): ?>
                                <small><?= $view->e($job['guidance']) ?></small>
                            <?php endif; ?>
                        </td>
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
                        <td><code><?= $view->e($attempt['result_code'] ?? '—') ?></code>
                            <?php if (!empty($attempt['guidance'])): ?>
                                <small><?= $view->e($attempt['guidance']) ?></small>
                            <?php endif; ?>
                        </td>
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
                <h2>SMS через Headwind GSM</h2>
                <p>Workspace передаёт SMS напрямую Windows-драйверу Headwind GSM на этом сервере. Внешний адрес и API-ключ не нужны. Модем и SIM-карта настраиваются в Headwind GSM.</p>
            </div>
        </div>
        <p role="status"><?= $view->e($sms_environment['message'] ?? 'Для SMS необходимы Windows, расширение PHP com_dotnet и установленный Headwind GSM.') ?></p>
        <form action="<?= $view->e($view->route('admin_notifications_external')) ?>" method="post"
              data-notification-ajax-form class="custom-fields-form">
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="sms">
            <div class="admin-notification-fields">
                <label class="custom-field__control">Получатель Notes
                    <input type="number" name="owner_user_id" min="1" required>
                </label>
                <label class="custom-field__control">Телефон E.164
                    <input type="tel" name="phone" pattern="\+[1-9][0-9]{7,14}" required placeholder="+79990000000">
                </label>
                <label class="custom-field__control">Канал
                    <select name="enabled"><option value="1">Включить</option><option value="0">Отключить</option></select>
                </label>
                <label class="custom-field__control">Пароль администратора
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
            </div>
            <button type="submit" class="admin-action admin-action--primary">Сохранить SMS</button>
        </form>
        <p>Драйвер подтверждает принятие SMS в исходящие, а не доставку на телефон. Тексты переписки в SMS не включаются.</p>
        <form action="<?= $view->e($view->route('admin_notifications_test')) ?>" method="post"
              data-notification-ajax-form>
            <?= $view->csrfInput() ?>
            <input type="hidden" name="channel" value="sms">
            <button type="submit" class="admin-action admin-action--secondary">Пробная SMS на мой номер</button>
        </form>
    </section>

    <section class="admin-panel-card">
        <h2>Настроенные адресаты доставки</h2>
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
                        <td><?= ($entry['channel'] ?? '') === 'sms' && ($entry['settings']['transport'] ?? '') === 'headwind'
                            ? 'Не требуется (Headwind GSM)' : (!empty($entry['has_secret']) ? 'Задан в окружении' : 'Нет') ?></td>
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
    'module_styles' => [
        $view->moduleAsset('admin', 'style.css'),
        $view->moduleAsset('admin', 'notification-settings.css'),
    ],
    'module_scripts' => [
        $view->moduleAsset('admin', 'admin-settings-nav.js'),
        $view->moduleAsset('admin', 'notification-settings.js'),
    ],
], $content);
