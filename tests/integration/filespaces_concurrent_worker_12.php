<?php

declare(strict_types=1);

// Отдельный PHP-процесс для проверки реальной конкуренции подключений к MySQL.
// Этот файл вызывается только родительским приёмочным сценарием Filespaces.
$root = dirname(__DIR__, 2);
require_once $root . '/core/PrefixPDO.php';
require_once $root . '/core/MutationCommandStore.php';
foreach ([
    'FileSpaceStorageGuard', 'FileUploadTypes', 'FileSpaceAccessService',
    'FileSpaceFolderService', 'FileSpaceTransferService', 'FileSpaceObjectService',
] as $service) {
    require_once $root . '/modules/files/services/' . $service . '.php';
}

use App\Services\Files\FileSpaceAccessService;
use App\Services\Files\FileSpaceFolderService;
use App\Services\Files\FileSpaceObjectService;
use App\Services\Files\FileSpaceTransferService;
use Core\MutationCommandStore;
use Core\PrefixPDO;

$configFile = $argv[1] ?? '';
$workerId = isset($argv[2]) ? (int) $argv[2] : -1;
if (!is_file($configFile) || $workerId < 0 || $workerId > 31) {
    fwrite(STDERR, "Неверная конфигурация конкурентного испытания\n");
    exit(2);
}
$config = json_decode((string) file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
$directory = dirname($configFile);
$prefix = (string) $config['run_id'];
$readyPath = $directory . '/' . $prefix . '-' . $workerId . '.ready';
$goPath = $directory . '/' . $prefix . '.go';
$resultPath = $directory . '/' . $prefix . '-' . $workerId . '.json';
$startTime = microtime(true);
try {
    putenv('DBNAME=' . (string) getenv('FILESPACES_DBNAME'));
    $pdo = new PrefixPDO(
        'mysql:host=' . (getenv('DBHOST') ?: '127.0.0.1')
        . ';port=' . (getenv('DBPORT') ?: '3306')
        . ';dbname=' . getenv('FILESPACES_DBNAME') . ';charset=utf8mb4',
        (string) (getenv('DBUSER') ?: 'root'),
        (string) (getenv('DBPASS') ?: ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_TIMEOUT => 8]
    );
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=8');
    $access = new FileSpaceAccessService($pdo);
    $commands = new MutationCommandStore();
    $transfer = new FileSpaceTransferService(
        $pdo, $access, $commands, (string) $config['storage_root'],
        static fn (string $from, string $to): bool => copy($from, $to)
    );
    $folders = new FileSpaceFolderService($pdo, $access, $commands);
    $objects = new FileSpaceObjectService($pdo, $access, $commands);
    $temporaryFile = $directory . '/' . $prefix . '-' . $workerId . '.txt';
    file_put_contents($temporaryFile, 'Параллельное испытание содержимого Files 1.2');
    $file = [
        'name' => 'Параллельный.txt', 'tmp_name' => $temporaryFile,
        'error' => UPLOAD_ERR_OK, 'size' => filesize($temporaryFile),
    ];
    file_put_contents($readyPath, 'готов');
    $deadline = microtime(true) + 15;
    while (!is_file($goPath) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if (!is_file($goPath)) {
        throw new RuntimeException('Истекло ожидание общего старта процессов');
    }

    $mode = (string) $config['mode'];
    $actor = (int) $config['actor'];
    $version = (int) $config['version'];
    $parentVersion = (int) ($config['parent_version'] ?? 1);
    $nodeId = (int) ($config['node_id'] ?? 0);
    $commandId = (string) ($config['command_id'] ?? ($prefix . '-worker-' . $workerId));
    $result = match ($mode) {
        'upload', 'replay_upload' => $transfer->upload(
            $actor, (int) $config['folder_id'], $file, $version, $commandId, 1048576
        ),
        'rename' => $objects->mutate(
            $actor, $nodeId, 'rename', $version, $parentVersion, $commandId,
            ['name' => 'Параллельный-' . $workerId . '.txt']
        ),
        'move' => $objects->mutate(
            $actor, $nodeId, 'move', $version, $parentVersion, $commandId,
            ['parent_id' => (int) $config['destination_id'],
             'expected_destination_version' => (int) $config['destination_version']]
        ),
        'acl' => $folders->setRule(
            $actor, (int) $config['folder_id'], 'user',
            (int) $config['subject_id'], 'history',
            $workerId % 2 === 0 ? 'allow' : 'deny',
            $version, $commandId
        ),
        'delete', 'restore' => $objects->mutate(
            $actor, $nodeId, $mode, $version, $parentVersion, $commandId
        ),
        default => throw new InvalidArgumentException('Неизвестный сценарий конкуренции'),
    };
    $report = ['kind' => 'result', 'value' => $result];
} catch (Throwable $error) {
    $report = [
        'kind' => 'error', 'type' => get_class($error),
        'code' => (int) $error->getCode(),
        'message' => $error->getMessage(),
    ];
} finally {
    if (isset($temporaryFile) && is_file($temporaryFile)) {
        @unlink($temporaryFile);
    }
}
$report['elapsed_ms'] = round((microtime(true) - $startTime) * 1000, 2);
file_put_contents($resultPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
exit(0);
