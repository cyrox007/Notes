<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpDeliveryGuidance.php';

use App\Services\Notifications\SmtpDeliveryGuidance;

function smtpGuidanceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    'smtp_sent' => 'принял письмо',
    'smtp_connect_failed' => 'порт',
    'smtp_timeout' => 'время ожидания',
    'smtp_connection_lost' => 'Соединение',
    'smtp_starttls_rejected' => 'TLS',
    'smtp_tls_failed' => 'сертификат',
    'smtp_auth_rejected' => 'авторизацию',
    'smtp_secret_unavailable' => 'переменную окружения',
    'smtp_recipient_unavailable' => 'адреса электронной почты',
    'smtp_mail_from_rejected' => 'отправителя',
    'smtp_recipient_rejected' => 'адрес получателя',
    'smtp_temporary' => 'повторит попытку',
    'smtp_message_rejected' => 'содержимое письма',
    'smtp_config_invalid' => 'Настройки SMTP',
    'channel_disabled' => 'отключён',
];

foreach ($cases as $code => $expected) {
    $message = SmtpDeliveryGuidance::forCode($code);
    smtpGuidanceAssert(str_contains($message, $expected),
        'Код ' . $code . ' должен иметь понятное действие оператора');
    smtpGuidanceAssert(strlen($message) <= 320, 'Подсказка должна оставаться краткой');
}

$unknown = '<script>alert("smtp-password")</script>';
$fallback = SmtpDeliveryGuidance::forCode($unknown);
smtpGuidanceAssert(!str_contains($fallback, 'script')
    && !str_contains($fallback, 'smtp-password'), 'Нельзя отражать недоверенный код в ответ');
smtpGuidanceAssert(!str_contains(SmtpDeliveryGuidance::forCode(null), 'smtp-password'),
    'Отсутствие результата не должно раскрывать секреты');

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/services/notifications/NotificationChannelAdministration.php');
$controller = (string) file_get_contents($root . '/modules/admin/controllers/NotificationSettingsController.php');
$view = (string) file_get_contents($root . '/modules/admin/views/notifications.php');
$script = (string) file_get_contents($root . '/modules/admin/assets/notification-settings.js');

smtpGuidanceAssert(str_contains($service, 'SmtpDeliveryGuidance::forCode')
    && str_contains($service, 'ORDER BY a.id DESC LIMIT 40'),
    'История ограничена и получает безопасные рекомендации');
smtpGuidanceAssert(str_contains($controller, "'failed' => 'Пробная доставка завершилась ошибкой")
    && str_contains($controller, "'retry' => 'Пробная доставка временно не удалась")
    && str_contains($controller, "'guidance' =>"),
    'Ответ пробной отправки обязан различать ошибку и повтор');
smtpGuidanceAssert(str_contains($view, '$view->e($job[\'guidance\'])')
    && str_contains($view, '$view->e($attempt[\'guidance\'])'),
    'Административная страница должна безопасно выводить рекомендации');
smtpGuidanceAssert(str_contains($script, "result.state === 'failed'")
    && str_contains($script, "result.state !== 'sent'"),
    'Браузер обязан различать неуспешную и успешную SMTP-передачу');

echo "Проверены безопасные рекомендации SMTP и состояния пробной доставки\n";
