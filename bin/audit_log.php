<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use Core\CliRuntime;
use Core\UserActionLog;

$options = getopt('', ['days:', 'limit:', 'apply', 'yes', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование: php bin/audit_log.php [--days=N] [--limit=N] [--json]\n";
    echo "              php bin/audit_log.php --apply --yes [те же параметры]\n";
    echo "По умолчанию выполняется только просмотр. --apply --yes безвозвратно удаляет просроченные записи аудита.\n";
    exit(0);
}

$defaultDaysRaw = trim((string) (getenv('AUDIT_LOG_RETENTION_DAYS') ?: '180'));
$defaultDays = ctype_digit($defaultDaysRaw) ? (int) $defaultDaysRaw : 180;
$days = isset($options['days']) ? (int) $options['days'] : $defaultDays;
$limit = isset($options['limit']) ? (int) $options['limit'] : 1000;
$apply = isset($options['apply']);
$confirmed = isset($options['yes']);
$json = isset($options['json']);

try {
    if ($apply && !$confirmed) {
        throw new RuntimeException('Для очистки журнала аудита требуется явное подтверждение --yes');
    }
    if (!$apply && $confirmed) {
        throw new RuntimeException('--yes допустим только вместе с --apply');
    }

    $log = new UserActionLog();
    $eligible = $log->countOlderThan($days);
    $deleted = $apply ? $log->purgeOlderThan($days, $limit) : 0;
    $result = [
        'status' => 'ok',
        'mode' => $apply ? 'apply' : 'preview',
        'retention_days' => $days,
        'eligible' => $eligible,
        'deleted' => $deleted,
        'limit' => max(1, min(5000, $limit)),
    ];

    if ($json) {
        CliRuntime::writeJson($result, true);
        exit(0);
    }

    if ($apply) {
        echo "Очистка журнала аудита выполнена\n";
        echo "Хранение: {$days} дн.\n";
        echo "Подходило к удалению до очистки: {$eligible}\n";
        echo "Удалено в этом проходе: {$deleted}\n";
        exit(0);
    }

    echo "Предварительный просмотр журнала аудита\n";
    echo "Хранение: {$days} дн.\n";
    echo "Подходящих записей: {$eligible}\n";
    echo "Данные не изменены. Для удаления до {$result['limit']} записей повторите команду с --apply --yes.\n";
} catch (Throwable $e) {
    CliRuntime::fail($e, $json, 'audit_retention_failed');
}
