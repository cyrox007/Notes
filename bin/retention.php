<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/core/Config.php';

use App\Services\RetentionService;
use Core\CliRuntime;

$options = getopt('', [
    'soft-days:',
    'account-days:',
    'limit:',
    'apply',
    'yes',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование: php bin/retention.php [--soft-days=N] [--account-days=N] [--limit=N] [--json]\n";
    echo "              php bin/retention.php --apply --yes [те же параметры]\n";
    echo "По умолчанию выполняется только просмотр. Физическая очистка soft-delete и обезличивание просроченных заявок на удаление требуют --apply --yes.\n";
    exit(0);
}

$softDays = isset($options['soft-days'])
    ? (int) $options['soft-days']
    : max(1, (int) (getenv('RETENTION_SOFT_DELETE_DAYS') ?: 30));
$accountDays = isset($options['account-days'])
    ? (int) $options['account-days']
    : max(1, (int) (getenv('RETENTION_DEACTIVATED_ACCOUNT_DAYS') ?: 30));
$limit = isset($options['limit']) ? (int) $options['limit'] : 100;
$apply = isset($options['apply']);
$confirmed = isset($options['yes']);
$json = isset($options['json']);

try {
    if ($apply && !$confirmed) {
        throw new RuntimeException('Очистка требует явного подтверждения --yes');
    }
    if (!$apply && $confirmed) {
        throw new RuntimeException('--yes допустим только вместе с --apply');
    }

    $service = new RetentionService();
    $result = $apply
        ? $service->apply($softDays, $accountDays, $limit)
        : $service->preview($softDays, $accountDays, $limit);

    if ($json) {
        CliRuntime::writeJson($result, true);
    } elseif (!$apply) {
        echo "Предварительный просмотр очистки\n";
        echo "Хранение soft-delete: {$result['soft_delete_days']} дн., граница {$result['soft_delete_cutoff']} UTC\n";
        echo "Заявки на удаление пользователя обрабатываются только после индивидуального purge_after; контрольное время {$result['account_due_at']} UTC\n";
        foreach ($result['soft_delete_candidates'] as $type => $count) {
            echo sprintf("  %-28s %d\n", $type, $count);
        }
        echo 'Аккаунтов готово к обезличиванию: ' . $result['account_candidates']['eligible'] . PHP_EOL;
        echo 'Аккаунтов остановлено защитной политикой: ' . $result['account_candidates']['blocked'] . PHP_EOL;
        echo "Данные не изменены. Для очистки повторите команду с --apply --yes.\n";
    } else {
        echo "Очистка выполнена\n";
        foreach ($result['purged'] as $type => $count) {
            echo sprintf("  %-28s %d\n", $type, $count);
        }
        echo 'Файлов удалено: ' . $result['files_deleted'] . PHP_EOL;
        echo 'Файлов уже отсутствовало: ' . $result['files_missing'] . PHP_EOL;
        echo 'Файлов заблокировано защитой: ' . $result['files_blocked'] . PHP_EOL;
        echo 'Ошибок удаления файлов: ' . $result['files_failed'] . PHP_EOL;
        echo 'Аккаунтов обезличено: ' . $result['accounts_purged'] . PHP_EOL;
        echo 'Аккаунтов остановлено защитой: ' . $result['accounts_blocked'] . PHP_EOL;
        echo 'Ошибок очистки аккаунтов: ' . $result['accounts_failed'] . PHP_EOL;
    }

    if ($apply && (
        (int) $result['files_blocked'] > 0
        || (int) $result['files_failed'] > 0
        || (int) $result['accounts_failed'] > 0
    )) {
        exit(3);
    }
    exit(0);
} catch (Throwable $e) {
    CliRuntime::fail($e, $json, 'retention_failed');
}
