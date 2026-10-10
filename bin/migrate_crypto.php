<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/CliRuntime.php';
$root = \Core\CliRuntime::loadEnvironment();
\Core\CliRuntime::registerAutoloader($root);
require_once $root . '/app/handlers/CryptMethods.php';
require_once $root . '/modules/messenger/handlers/MessengerCrypto.php';
require_once $root . '/app/services/CryptoMigrationService.php';

use Core\CliRuntime;

$options = getopt('', [
    'scope:',
    'dry-run',
    'limit:',
    'after-id:',
    'allow-plaintext-notes',
    'help',
]);

if (isset($options['help'])) {
    echo "Использование: php bin/migrate_crypto.php [параметры]\n";
    echo "  --scope=all|messenger|notes   Набор данных для обработки (по умолчанию all).\n";
    echo "  --dry-run                     Только проверить расшифровку, без изменения БД.\n";
    echo "  --limit=N                     Максимум строк на область, 1..10000 (по умолчанию 1000).\n";
    echo "  --after-id=N                  Продолжить после первичного ключа N.\n";
    echo "  --allow-plaintext-notes       Считать неизвестные зашифрованные заметки открытым текстом.\n";
    exit(0);
}

$scope = strtolower((string) ($options['scope'] ?? 'all'));
if (!in_array($scope, ['all', 'messenger', 'notes'], true)) {
    fwrite(STDERR, "Некорректный --scope. Используйте all, messenger или notes.\n");
    exit(2);
}

$limit = isset($options['limit']) ? (int) $options['limit'] : 1000;
if ($limit < 1 || $limit > 10000) {
    fwrite(STDERR, "--limit должен быть в диапазоне 1..10000.\n");
    exit(2);
}
$afterId = isset($options['after-id']) ? (int) $options['after-id'] : 0;
if ($afterId < 0) {
    fwrite(STDERR, "--after-id должен быть не меньше 0.\n");
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

    CliRuntime::writeJson($output, true);

    $failed = 0;
    foreach ($output['results'] as $result) {
        $failed += (int) ($result['failed'] ?? 0);
    }
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    CliRuntime::fail($e, false, 'crypto_migration_failed');
}
