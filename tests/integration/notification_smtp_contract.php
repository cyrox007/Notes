<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryChannel.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationDeliveryResult.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationEmailRecipientResolver.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/NotificationSecretResolver.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpTransport.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpTransportException.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpNotificationChannel.php';
require_once dirname(__DIR__, 2) . '/app/services/notifications/EnvironmentNotificationSecretResolver.php';

use App\Services\Notifications\EnvironmentNotificationSecretResolver;
use App\Services\Notifications\NotificationEmailRecipientResolver;
use App\Services\Notifications\NotificationSecretResolver;
use App\Services\Notifications\SmtpNotificationChannel;
use App\Services\Notifications\SmtpTransport;
use App\Services\Notifications\SmtpTransportException;

function smtpContractAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class ContractEmailRecipientResolver implements NotificationEmailRecipientResolver
{
    public function __construct(private ?string $email = 'user@example.test') {}

    public function emailForUser(int $userId): ?string
    {
        return $userId > 0 ? $this->email : null;
    }
}

final class ContractSecretResolver implements NotificationSecretResolver
{
    public function __construct(
        private ?string $secret = 'test-secret',
        private bool $fail = false
    ) {}

    public function resolve(?string $reference): ?string
    {
        if ($this->fail) {
            throw new RuntimeException('Секрет временно недоступен');
        }
        smtpContractAssert($reference === 'env:SMTP_TEST_PASSWORD', 'SMTP должен использовать только secret_ref задания');
        return $this->secret;
    }
}

final class ContractSmtpTransport implements SmtpTransport
{
    /** @var array<string,mixed>|null */
    public ?array $call = null;
    public ?SmtpTransportException $failure = null;

    public function send(
        array $settings,
        string $recipientEmail,
        string $subject,
        string $textBody,
        string $htmlBody,
        #[\SensitiveParameter] ?string $password
    ): void {
        if ($this->failure instanceof SmtpTransportException) {
            throw $this->failure;
        }
        $this->call = compact(
            'settings',
            'recipientEmail',
            'subject',
            'textBody',
            'htmlBody',
            'password'
        );
    }
}

function smtpContractJob(array $overrides = []): array
{
    return $overrides + [
        'recipient_user_id' => 7,
        'title' => 'Напоминание <важное>',
        'body' => "Событие скоро начнётся\nНе забудьте проверить детали.",
        'target_path' => '/calendar/events/42',
        'secret_ref' => 'env:SMTP_TEST_PASSWORD',
        'config_json' => json_encode([
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.test',
            'from_email' => 'notifications@example.test',
            'from_name' => 'Workspace Organizer',
            'timeout_seconds' => 8,
            'client_name' => 'workspace.example.test',
        ], JSON_THROW_ON_ERROR),
    ];
}

$transport = new ContractSmtpTransport();
$channel = new SmtpNotificationChannel(
    new ContractEmailRecipientResolver(),
    new ContractSecretResolver(),
    $transport,
    'https://workspace.example.test/'
);
$result = $channel->deliver(smtpContractJob());
smtpContractAssert($result->success === true && $result->code === 'smtp_sent', 'Корректное SMTP-задание должно завершаться успешно');
smtpContractAssert($transport->call !== null, 'SMTP-транспорт должен быть вызван');
smtpContractAssert($transport->call['recipientEmail'] === 'user@example.test', 'Адрес должен разрешаться непосредственно перед отправкой');
smtpContractAssert($transport->call['password'] === 'test-secret', 'Пароль должен приходить из resolver секретов');
smtpContractAssert(!array_key_exists('password', $transport->call['settings']), 'Пароль не должен попадать в SMTP settings');
smtpContractAssert(str_contains($transport->call['textBody'], 'https://workspace.example.test/calendar/events/42'), 'Текст письма должен содержать безопасную внутреннюю ссылку');
smtpContractAssert(str_contains($transport->call['htmlBody'], '&lt;важное&gt;'), 'HTML письма должен экранировать заголовок');

$badConfig = smtpContractJob([
    'config_json' => json_encode([
        'host' => 'smtp.example.test',
        'from_email' => 'notifications@example.test',
        'password' => 'нельзя-хранить-в-бд',
    ], JSON_THROW_ON_ERROR),
]);
$badResult = $channel->deliver($badConfig);
smtpContractAssert($badResult->success === false && $badResult->code === 'smtp_config_invalid', 'Пароль внутри config_json должен блокироваться');

$missingRecipientTransport = new ContractSmtpTransport();
$missingRecipient = new SmtpNotificationChannel(
    new ContractEmailRecipientResolver(null),
    new ContractSecretResolver(),
    $missingRecipientTransport
);
$missingResult = $missingRecipient->deliver(smtpContractJob());
smtpContractAssert($missingResult->code === 'smtp_recipient_unavailable' && $missingResult->retryable === false, 'Недоступный адрес получателя не должен повторяться');
smtpContractAssert($missingRecipientTransport->call === null, 'Без адреса транспорт вызываться не должен');

$secretFailure = new SmtpNotificationChannel(
    new ContractEmailRecipientResolver(),
    new ContractSecretResolver(null, true),
    new ContractSmtpTransport()
);
$secretResult = $secretFailure->deliver(smtpContractJob());
smtpContractAssert($secretResult->code === 'smtp_secret_unavailable' && $secretResult->retryable === true, 'Недоступный секрет должен идти в retry');

$temporaryTransport = new ContractSmtpTransport();
$temporaryTransport->failure = new SmtpTransportException('SMTP временно недоступен', 'smtp_temporary', true);
$temporaryChannel = new SmtpNotificationChannel(
    new ContractEmailRecipientResolver(),
    new ContractSecretResolver(),
    $temporaryTransport
);
$temporaryResult = $temporaryChannel->deliver(smtpContractJob());
smtpContractAssert($temporaryResult->code === 'smtp_temporary' && $temporaryResult->retryable === true, 'Временная SMTP-ошибка должна сохранять retry');

putenv('NOTIFICATION_SMTP_CONTRACT_SECRET=contract-value');
$environmentSecrets = new EnvironmentNotificationSecretResolver();
smtpContractAssert(
    $environmentSecrets->resolve('env:NOTIFICATION_SMTP_CONTRACT_SECRET') === 'contract-value',
    'Environment resolver должен читать секрет только по env: ссылке'
);
$rawSecretRejected = false;
try {
    $environmentSecrets->resolve('contract-value');
} catch (InvalidArgumentException) {
    $rawSecretRejected = true;
}
smtpContractAssert($rawSecretRejected, 'Открытое значение вместо secret_ref должно отклоняться');
putenv('NOTIFICATION_SMTP_CONTRACT_SECRET');

$root = dirname(__DIR__, 2);
$transportSource = file_get_contents($root . '/app/services/notifications/SocketSmtpTransport.php');
$channelSource = file_get_contents($root . '/app/services/notifications/SmtpNotificationChannel.php');
smtpContractAssert(is_string($transportSource) && str_contains($transportSource, '#[\\SensitiveParameter] ?string $password'), 'Пароль SMTP должен быть защищён SensitiveParameter');
smtpContractAssert(is_string($transportSource) && !str_contains($transportSource, 'mail('), 'SMTP-канал не должен использовать PHP mail()');
smtpContractAssert(is_string($channelSource) && str_contains($channelSource, "['password', 'pass', 'secret', 'token', 'api_key']"), 'config_json должен блокировать открытые секреты');

fwrite(STDOUT, "[OK] контракт SMTP-канала уведомлений 1.1.0\n");
