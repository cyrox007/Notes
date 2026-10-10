<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;
use Core\CliRuntime;

$options = getopt('', ['action:', 'transaction:', 'reason:', 'state-root:', 'force', 'json', 'help']);
if (isset($options['help'])) {
    echo "Использование:\n";
    echo "  php bin/maintenance.php --action=status [--state-root=/внешний/путь] [--json]\n";
    echo "  php bin/maintenance.php --action=enter --transaction=update-... [--reason='Обновление'] [--state-root=/внешний/путь]\n";
    echo "  php bin/maintenance.php --action=leave --transaction=update-... [--state-root=/внешний/путь]\n";
    echo "  php bin/maintenance.php --action=leave --force [--state-root=/внешний/путь]\n";
    exit(0);
}

$json = isset($options['json']);
$action = strtolower(trim((string) ($options['action'] ?? 'status')));
$transaction = trim((string) ($options['transaction'] ?? ''));
$reason = trim((string) ($options['reason'] ?? 'Обновление приложения'));
$stateRoot = trim((string) ($options['state-root'] ?? ''));

try {
    $service = new MaintenanceModeService($stateRoot !== '' ? $stateRoot : null, $root);

    if ($action === 'enter') {
        if ($transaction === '') {
            throw new RuntimeException('Для входа в режим обслуживания требуется --transaction');
        }
        $state = $service->enter($transaction, $reason);
    } elseif ($action === 'leave') {
        $force = isset($options['force']);
        if (!$force && $transaction === '') {
            throw new RuntimeException('Укажите --transaction либо используйте --force');
        }
        $service->leave($transaction, $force);
        $state = $service->state();
    } elseif ($action === 'status') {
        $state = $service->state();
    } else {
        throw new RuntimeException('Неизвестное действие maintenance; используйте status, enter или leave');
    }

    if ($json) {
        CliRuntime::writeJson([
            'status' => 'ok',
            'maintenance' => $state,
        ], true);
        exit(0);
    }

    echo $state['active'] ? "Обслуживание: ВКЛЮЧЕНО\n" : "Обслуживание: выключено\n";
    echo 'Состояние корректно: ' . ($state['valid'] ? 'да' : 'НЕТ') . PHP_EOL;
    if ($state['transaction_id'] !== null) {
        echo 'Транзакция: ' . $state['transaction_id'] . PHP_EOL;
    }
    if ($state['reason'] !== '') {
        echo 'Причина: ' . $state['reason'] . PHP_EOL;
    }
    if ($state['state_path'] !== null) {
        echo 'Файл состояния: ' . $state['state_path'] . PHP_EOL;
    }
} catch (Throwable $e) {
    CliRuntime::fail($e, $json, 'maintenance_error');
}
