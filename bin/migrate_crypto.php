<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
if (class_exists(Dotenv\Dotenv::class) && is_file($root . '/.env')) {
    Dotenv\Dotenv::createUnsafeImmutable($root)->safeLoad();
}
require $root . '/core/config.php';
require $root . '/core/DatabaseManager.php';
require $root . '/app/handlers/CryptMethods.php';
require $root . '/app/handlers/MessengerCrypto.php';
require $root . '/app/services/CryptoMigrationService.php';

$options = getopt('', [
    'scope:',
    'dry-run',
    'limit:',
    'after-id:',
    'allow-plaintext-notes',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php bin/migrate_crypto.php [options]\n";
    echo "  --scope=all|messenger|notes   Data set to scan (default all).\n";
    echo "  --dry-run                     Authenticate/decrypt only; do not update DB.\n";
    echo "  --limit=N                     Maximum rows per scope, 1..10000 (default 1000).\n";
    echo "  --after-id=N                  Resume after primary-key ID N.\n";
    echo "  --allow-plaintext-notes       Treat unknown notes marked encrypted as plaintext.\n";
    exit(0);
}

$scope = strtolower((string) ($options['scope'] ?? 'all'));
if (!in_array($scope, ['all', 'messenger', 'notes'], true)) {
    fwrite(STDERR, "Invalid --scope. Use all, messenger or notes.\n");
    exit(2);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 1000;
if ($limit < 1 || $limit > 10000) {
    fwrite(STDERR, "--limit must be between 1 and 10000.\n");
    exit(2);
}
$afterId = isset($options['after-id']) ? (int) $options['after-id'] : 0;
if ($afterId < 0) {
    fwrite(STDERR, "--after-id must be >= 0.\n");
    exit(2);
}
$dryRun = isset($options['dry-run']);
$allowPlaintextNotes = isset($options['allow-plaintext-notes']);

try {
    $service = new \App\Services\CryptoMigrationService(\Core\DatabaseManager::getInstance());
    $output = [
        'dry_run' => $dryRun,
        'scope' => $scope,
        'limit' => $limit,
        'after_id' => $afterId,
        'allow_plaintext_notes' => $allowPlaintextNotes,
        'results' => [],
    ];

    if ($scope === 'all' || $scope === 'messenger') {
        $output['results']['messenger'] = $service->migrateMessenger($dryRun, $limit, $afterId);
    }
    if ($scope === 'all' || $scope === 'notes') {
        $output['results']['notes'] = $service->migrateNotes($dryRun, $limit, $allowPlaintextNotes, $afterId);
    }

    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

    $failed = 0;
    foreach ($output['results'] as $result) {
        $failed += (int) ($result['failed'] ?? 0);
    }
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Crypto migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
