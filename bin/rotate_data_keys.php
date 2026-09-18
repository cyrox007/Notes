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
require_once $root . '/core/config.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/app/services/DataKeyRotationService.php';

use App\Services\DataKeyRotationService;
use App\Services\MaintenanceModeService;
use Core\DatabaseManager;

$options = getopt('', [
    'transaction:',
    'scope:',
    'old-unique-key-file:',
    'new-unique-key-file:',
    'old-msg-key-file:',
    'new-msg-key-file:',
    'state-root:',
    'batch-size:',
    'max-batches:',
    'rollback',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php bin/rotate_data_keys.php --transaction=ID --scope=all|notes|messenger [options]\n";
    echo "  --old-unique-key-file=PATH  Current UNIQUE_KEY in a chmod 600 file.\n";
    echo "  --new-unique-key-file=PATH  Replacement UNIQUE_KEY in a chmod 600 file.\n";
    echo "  --old-msg-key-file=PATH     Current MSG_SECRET_KEY in a chmod 600 file.\n";
    echo "  --new-msg-key-file=PATH     Replacement MSG_SECRET_KEY in a chmod 600 file.\n";
    echo "  --batch-size=N              Transaction batch size 1..5000 (default 250).\n";
    echo "  --max-batches=N             Stop cleanly after N batches; 0 means complete all.\n";
    echo "  --state-root=PATH           External resumable state directory.\n";
    echo "  --rollback                  Re-encrypt new-key rows back to the old keys.\n";
    echo "  --json                      Machine-readable result.\n";
    echo "\nRaw key values are intentionally not accepted on the command line.\n";
    exit(0);
}

$json = isset($options['json']);
$transaction = trim((string) ($options['transaction'] ?? ''));
$scope = strtolower(trim((string) ($options['scope'] ?? 'all')));
$batchSize = isset($options['batch-size']) ? (int) $options['batch-size'] : 250;
$maxBatches = isset($options['max-batches']) ? (int) $options['max-batches'] : 0;
$rollback = isset($options['rollback']);
$stateRoot = trim((string) ($options['state-root'] ?? ''));

function rotationSecretFile(?string $path, string $label): string
{
    $path = trim((string) $path);
    if ($path === '') {
        throw new RuntimeException("{$label} is required");
    }
    if (is_link($path)) {
        throw new RuntimeException("{$label} must not be a symlink");
    }
    $resolved = realpath($path);
    if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
        throw new RuntimeException("{$label} is missing or unreadable");
    }
    $size = filesize($resolved);
    if (!is_int($size) || $size < 32 || $size > 4096) {
        throw new RuntimeException("{$label} must contain 32..4096 bytes");
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        $perms = fileperms($resolved);
        if (is_int($perms) && (($perms & 0077) !== 0)) {
            throw new RuntimeException("{$label} permissions are too broad; chmod 600 is required");
        }
    }
    $bytes = file_get_contents($resolved);
    $secret = is_string($bytes) ? trim($bytes) : '';
    if (strlen($secret) < 32) {
        throw new RuntimeException("{$label} must contain at least 32 non-whitespace characters");
    }
    return $secret;
}

try {
    if ($transaction === '') {
        throw new RuntimeException('--transaction is required and must match active maintenance mode');
    }
    if (!in_array($scope, ['all', 'notes', 'messenger'], true)) {
        throw new RuntimeException('--scope must be all, notes or messenger');
    }

    $secrets = [];
    if ($scope === 'all' || $scope === 'notes') {
        $secrets['old_unique'] = rotationSecretFile($options['old-unique-key-file'] ?? null, '--old-unique-key-file');
        $secrets['new_unique'] = rotationSecretFile($options['new-unique-key-file'] ?? null, '--new-unique-key-file');
    }
    if ($scope === 'all' || $scope === 'messenger') {
        $secrets['old_msg'] = rotationSecretFile($options['old-msg-key-file'] ?? null, '--old-msg-key-file');
        $secrets['new_msg'] = rotationSecretFile($options['new-msg-key-file'] ?? null, '--new-msg-key-file');
    }

    $service = new DataKeyRotationService(
        DatabaseManager::getInstance(),
        new MaintenanceModeService(),
        $stateRoot !== '' ? $stateRoot : null,
        $root
    );
    $result = $service->run(
        $transaction,
        $scope,
        $secrets,
        $batchSize,
        $maxBatches,
        $rollback
    );

    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    } else {
        echo 'Data-key rotation: ' . ($result['complete'] ? 'COMPLETE' : 'INCOMPLETE; resume required') . PHP_EOL;
        echo 'Direction: ' . $result['direction'] . PHP_EOL;
        echo 'Scope: ' . $result['scope'] . PHP_EOL;
        echo 'Batches this run: ' . $result['batches_this_run'] . PHP_EOL;
        echo 'State: ' . $result['state_path'] . PHP_EOL;
        if ($result['complete']) {
            echo "Verification: OK\n";
            if (!$rollback) {
                echo "Keep maintenance active. Update UNIQUE_KEY/MSG_SECRET_KEY in the secret manager or .env, restart HTTP/WS workers, verify, then leave maintenance.\n";
            } else {
                echo "Rollback verification: OK. The database is readable with the original keys again.\n";
            }
        }
    }
    exit(0);
} catch (Throwable $e) {
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'error' => 'data_key_rotation_failed',
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, '[FAIL] ' . $e->getMessage() . PHP_EOL);
    }
    exit(1);
}
