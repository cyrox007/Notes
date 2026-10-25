<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/notifications/SmtpOperationalReadiness.php';

use App\Services\Notifications\SmtpOperationalReadiness;

function readinessAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('SMTP: ' . $message);
    }
}

$settings = [
    'host' => 'smtp.private.example',
    'port' => 587,
    'encryption' => 'tls',
    'username' => 'hidden-operator',
    'from_email' => 'hidden@example.org',
];
$config = ['enabled' => 1, 'config_json' => json_encode($settings, JSON_THROW_ON_ERROR)];
$codes = static fn (array $rows): array => array_column($rows, 'code');

$missing = SmtpOperationalReadiness::assess(null, false, 0, 'never_started');
readinessAssert($missing['state'] === 'blocked'
    && in_array('smtp_not_configured', $codes($missing['blockers']), true),
    'отсутствующий канал определяется однозначно');

$off = SmtpOperationalReadiness::assess([...$config, 'enabled' => 0], true, 2, 'healthy');
readinessAssert(in_array('smtp_disabled', $codes($off['blockers']), true),
    'выключенный канал не может считаться готовым');

$noSecret = SmtpOperationalReadiness::assess($config, false, 2, 'healthy');
readinessAssert(in_array('smtp_secret_unavailable', $codes($noSecret['blockers']), true),
    'недоступный пароль при SMTP-авторизации считается препятствием');

$anonymous = SmtpOperationalReadiness::assess([
    ...$config,
    'config_json' => json_encode([...$settings, 'username' => ''], JSON_THROW_ON_ERROR),
], false, 2, 'healthy');
readinessAssert($anonymous['blockers'] === [],
    'необязательный пароль нельзя требовать при отправке без авторизации');

$unprotected = SmtpOperationalReadiness::assess([
    ...$config,
    'config_json' => json_encode([...$settings, 'encryption' => 'none'], JSON_THROW_ON_ERROR),
], true, 2, 'healthy');
readinessAssert(in_array('smtp_settings_invalid', $codes($unprotected['blockers']), true),
    'незащищённая авторизация запрещена');

$corrupted = SmtpOperationalReadiness::assess([...$config, 'config_json' => '{oops'], true, 2, 'healthy');
readinessAssert(in_array('smtp_settings_invalid', $codes($corrupted['blockers']), true),
    'повреждённый JSON не должен быть принят');

$ready = SmtpOperationalReadiness::assess($config, true, 3, 'healthy');
readinessAssert($ready['state'] === 'needs_delivery_proof'
    && $ready['blockers'] === [] && $ready['warnings'] === []
    && $ready['delivery_verified'] === false,
    'исправная конфигурация не равна доказанной доставке');

$noScheduler = SmtpOperationalReadiness::assess($config, true, 0, 'late');
readinessAssert(in_array('smtp_worker_unverified', $codes($noScheduler['warnings']), true)
    && in_array('smtp_no_subscriptions', $codes($noScheduler['warnings']), true),
    'отсутствие проходов очереди и подписок объясняется отдельно');

$serialized = json_encode([$ready, $noSecret, $noScheduler], JSON_THROW_ON_ERROR);
readinessAssert(!str_contains($serialized, 'hidden-operator')
    && !str_contains($serialized, 'hidden@example.org')
    && !str_contains($serialized, 'smtp.private.example'),
    'отчёт не должен содержать SMTP-адресов и логинов');

$root = dirname(__DIR__, 2);
$admin = (string) file_get_contents($root . '/app/services/notifications/NotificationChannelAdministration.php');
$view = (string) file_get_contents($root . '/modules/admin/views/notifications.php');
$cli = (string) file_get_contents($root . '/bin/notifications_diagnose.php');
readinessAssert(str_contains($admin, 'SmtpOperationalReadiness::assess(')
    && str_contains($view, '$smtpReadiness')
    && str_contains($cli, "'smtp_readiness' => \$smtpReadiness"),
    'проверка готовности доступна в админке и консольном отчёте');

echo "Готовность SMTP: препятствия, предупреждения и отсутствие утечек проверены\n";
