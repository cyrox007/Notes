<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/app/services/MaintenanceModeService.php';

use App\Services\MaintenanceModeService;

$options = getopt('', ['action:', 'transaction:', 'reason:', 'state-root:', 'force', 'json', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php bin/maintenance.php --action=status [--state-root=/external/path] [--json]\n";
    echo "  php bin/maintenance.php --action=enter --transaction=update-... [--reason='Обновление'] [--state-root=/external/path]\n";
    echo "  php bin/maintenance.php --action=leave --transaction=update-... [--state-root=/external/path]\n";
    echo "  php bin/maintenance.php --action=leave --force [--state-root=/external/path]\n";
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
            throw new RuntimeException('--transaction is required for maintenance enter');
        }
        $state = $service->enter($transaction, $reason);
    } elseif ($action === 'leave') {
        $force = isset($options['force']);
        if (!$force && $transaction === '') {
            throw new RuntimeException('--transaction is required unless --force is used');
        }
        $service->leave($transaction, $force);
        $state = $service->state();
    } elseif ($action === 'status') {
        $state = $service->state();
    } else {
        throw new RuntimeException('Unknown maintenance action; use status, enter or leave');
    }

    if ($json) {
        echo json_encode([
            'status' => 'ok',
            'maintenance' => $state,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo $state['active'] ? "Maintenance: ACTIVE\n" : "Maintenance: inactive\n";
        echo 'State valid: ' . ($state['valid'] ? 'yes' : 'NO') . PHP_EOL;
        if ($state['transaction_id'] !== null) {
            echo 'Transaction: ' . $state['transaction_id'] . PHP_EOL;
        }
        if ($state['reason'] !== '') {
            echo 'Reason: ' . $state['reason'] . PHP_EOL;
        }
        if ($state['state_path'] !== null) {
            echo 'State file: ' . $state['state_path'] . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'maintenance_error',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
