<?php

declare(strict_types=1);

use App\Services\Files\FileSpaceTransferService;
use Core\MutationCommandStore;

// Профильная конкуренция: восемь независимых PHP-процессов и соединений MySQL.
// Результат характеризует только этот короткий стендовый прогон, не тысячу сессий.
if (!function_exists('proc_open')) {
    throw new RuntimeException('Для конкурентного сценария требуется proc_open');
}
$raceHome = sys_get_temp_dir() . '/fsp_race_' . bin2hex(random_bytes(8));
$racePrivate = $raceHome . '/private';
filespaceCheck(mkdir($racePrivate, 0700, true), 'создан изолированный каталог для конкурентных загрузок');
$spaceId = (int) $pdo->query("SELECT id FROM file_spaces WHERE uid='global'")->fetchColumn();

$createRaceFolder = static function (string $name) use ($folders, $admin, $pdo, $spaceId): int {
    $version = (int) $pdo->query('SELECT version FROM file_spaces WHERE id=' . $spaceId)->fetchColumn();
    $result = $folders->createFolder(
        $admin, null, $name, $version, 'fsp-race-folder-' . bin2hex(random_bytes(8))
    );
    return (int) $result['folder_id'];
};
$sourceFolder = $createRaceFolder('Конкуренция-источник');
$targetFolder = $createRaceFolder('Конкуренция-назначение');
$replayFolder = $createRaceFolder('Конкуренция-повторы');

$folderVersion = static function (int $id) use ($pdo): int {
    $stmt = $pdo->prepare('SELECT version FROM file_space_nodes WHERE id=:id');
    $stmt->execute([':id' => $id]);
    return (int) $stmt->fetchColumn();
};
$nodeVersion = $folderVersion;

/**
 * Запускает процессы синхронно от общего барьера; читает не только ответы,
 * но и реальные коды завершения, чтобы ошибки дочерних процессов не терялись.
 *
 * @param array<string,mixed> $config
 * @return list<array<string,mixed>>
 */
$runRace = static function (array $config, int $workers = 8) use ($raceHome, $racePrivate, $root, $pdo): array {
    $id = 'race_' . bin2hex(random_bytes(6));
    $config['run_id'] = $id;
    $config['storage_root'] = $racePrivate;
    $configPath = $raceHome . '/' . $id . '.config.json';
    $gate = $raceHome . '/' . $id . '.go';
    file_put_contents($configPath, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $running = [];
    $databaseQueriesBefore = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Queries'")->fetch(PDO::FETCH_ASSOC)['Value'];
    $phaseStarted = microtime(true);
    try {
        for ($index = 0; $index < $workers; $index++) {
            $stdout = $raceHome . '/' . $id . '-' . $index . '.out';
            $stderr = $raceHome . '/' . $id . '-' . $index . '.err';
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w'],
            ];
            $process = proc_open([
                PHP_BINARY, $root . '/tests/integration/filespaces_concurrent_worker_12.php',
                $configPath, (string) $index,
            ], $descriptors, $pipes, $root);
            if (!is_resource($process)) {
                throw new RuntimeException('Не удалось запустить независимый процесс Files');
            }
            fclose($pipes[0]);
            $running[] = ['process' => $process, 'index' => $index,
                'stdout' => $stdout, 'stderr' => $stderr];
        }
        $readyUntil = microtime(true) + 20;
        while (microtime(true) < $readyUntil) {
            $ready = 0;
            foreach ($running as $task) {
                if (is_file($raceHome . '/' . $id . '-' . $task['index'] . '.ready')) {
                    $ready++;
                }
            }
            if ($ready === count($running)) {
                break;
            }
            usleep(20000);
        }
        if ($ready !== count($running)) {
            throw new RuntimeException('Не все процессы достигли барьера конкурентной операции');
        }
        file_put_contents($gate, 'старт');
        $deadline = microtime(true) + 35;
        $exitCodes = [];
        while (count($exitCodes) < count($running) && microtime(true) < $deadline) {
            foreach ($running as $task) {
                $idx = $task['index'];
                if (array_key_exists($idx, $exitCodes)) {
                    continue;
                }
                $state = proc_get_status($task['process']);
                if (!$state['running']) {
                    $exitCodes[$idx] = (int) $state['exitcode'];
                }
            }
            usleep(20000);
        }
        if (count($exitCodes) !== count($running)) {
            throw new RuntimeException('Тайм-аут параллельных операций: возможная взаимная блокировка');
        }
        $reports = [];
        foreach ($running as $task) {
            $index = $task['index'];
            $json = $raceHome . '/' . $id . '-' . $index . '.json';
            if ($exitCodes[$index] !== 0 || !is_file($json)) {
                $error = is_file($task['stderr']) ? (string) file_get_contents($task['stderr']) : '';
                throw new RuntimeException('Ошибка процесса #' . $index . ': ' . $error);
            }
            $report = json_decode((string) file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
            $reports[] = ['worker' => $index, ...$report];
        }
        $databaseQueriesAfter = (int) $pdo->query("SHOW GLOBAL STATUS LIKE 'Queries'")->fetch(PDO::FETCH_ASSOC)['Value'];
        $phaseDuration = microtime(true) - $phaseStarted;
        $queryCount = $databaseQueriesAfter - $databaseQueriesBefore;
        $peakMemory = max(array_column($reports, 'php_peak_bytes'));
        echo sprintf("Нагрузка Files: %s; процессы %d; время %.2f с; MySQL запросов %d; пик PHP %.1f МиБ/процесс\n",
            (string) $config['mode'], count($reports), $phaseDuration,
            $queryCount, $peakMemory / 1048576);
        return $reports;
    } finally {
        foreach ($running as $task) {
            $status = proc_get_status($task['process']);
            if ($status['running']) {
                proc_terminate($task['process']);
            }
            proc_close($task['process']);
        }
        foreach (glob($raceHome . '/' . $id . '*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
};

/** @param list<array<string,mixed>> $results */
$assertRace = static function (array $results, int $success, int $conflicts, int $denied = 0): void {
    $applied = 0;
    $stale = 0;
    $rejected = 0;
    foreach ($results as $worker) {
        if ($worker['kind'] === 'result') {
            if (($worker['value']['success'] ?? false) === true) {
                $applied++;
            } elseif (($worker['value']['error'] ?? '') === 'version_conflict') {
                $stale++;
            } else {
                throw new RuntimeException('Неизвестный результат конкурентного запроса');
            }
            continue;
        }
        if ($worker['kind'] === 'error' && $worker['type'] === DomainException::class
            && (int) $worker['code'] === 404) {
            $rejected++;
            continue;
        }
        throw new RuntimeException('Непредвиденная ошибка конкурентного запроса: '
            . ($worker['message'] ?? 'неизвестная ошибка'));
    }
    filespaceCheck($applied === $success && $stale === $conflicts && $rejected === $denied,
        'конкурентная операция нарушила ожидаемую сериализацию/контроль версий');
};

$uploadConfig = [
    'mode' => 'upload', 'actor' => $admin,
    'folder_id' => $sourceFolder, 'version' => $folderVersion($sourceFolder),
];
$uploadResults = $runRace($uploadConfig);
$assertRace($uploadResults, 1, 7);
$uploadWinner = null;
$winnerId = null;
foreach ($uploadResults as $report) {
    if (($report['value']['success'] ?? false) === true) {
        $uploadWinner = $report['worker'];
        $winnerId = (int) $report['value']['file_id'];
        break;
    }
}
filespaceCheck($winnerId !== null && $winnerId > 0, 'определён победитель конкурентной загрузки');
$count = $pdo->prepare('SELECT COUNT(*) FROM file_space_nodes WHERE parent_id=:id AND node_type="file"');
$count->execute([':id' => $sourceFolder]);
filespaceCheck((int) $count->fetchColumn() === 1,
    'в каталоге не создано дублирующих файлов после восьми одновременных загрузок');

$replayConfig = [
    'mode' => 'upload', 'actor' => $admin, 'folder_id' => $replayFolder,
    'version' => $folderVersion($replayFolder),
    'command_id' => 'fsp-race-replay-' . bin2hex(random_bytes(6)),
];
$firstReplay = $runRace($replayConfig, 1);
$assertRace($firstReplay, 1, 0);
$allReplays = $runRace($replayConfig);
filespaceCheck(count(array_filter($allReplays, static fn (array $r): bool =>
    $r['kind'] === 'result' && ($r['value']['replayed'] ?? false) === true
)) === 8, 'восемь конкурентных повторов получили одну сохранённую квитанцию');

$objectVersion = $nodeVersion($winnerId);
$sourceVersion = $folderVersion($sourceFolder);
$rename = $runRace([
    'mode' => 'rename', 'actor' => $admin, 'node_id' => $winnerId,
    'version' => $objectVersion, 'parent_version' => $sourceVersion,
]);
$assertRace($rename, 1, 7);
$renameName = (string) $pdo->query(
    'SELECT name FROM file_space_nodes WHERE id=' . $winnerId
)->fetchColumn();
filespaceCheck(str_starts_with($renameName, 'Параллельный-'),
    'конкурентное переименование оставило одно согласованное имя');

$move = $runRace([
    'mode' => 'move', 'actor' => $admin, 'node_id' => $winnerId,
    'version' => $nodeVersion($winnerId),
    'parent_version' => $folderVersion($sourceFolder),
    'destination_id' => $targetFolder,
    'destination_version' => $folderVersion($targetFolder),
]);
$assertRace($move, 1, 7);
$parent = $pdo->query('SELECT parent_id FROM file_space_nodes WHERE id=' . $winnerId)->fetchColumn();
filespaceCheck((int) $parent === $targetFolder, 'файл перенесён ровно один раз');

$acl = $runRace([
    'mode' => 'acl', 'actor' => $admin, 'folder_id' => $targetFolder,
    'subject_id' => $member, 'version' => $folderVersion($targetFolder),
]);
$assertRace($acl, 1, 7);
$rules = $pdo->prepare(
    "SELECT COUNT(*) FROM file_space_acl WHERE scope_key=:scope
     AND subject_type='user' AND subject_id=:member AND permission_key='history'"
);
$rules->execute([':scope' => 'node:' . $targetFolder, ':member' => $member]);
filespaceCheck((int) $rules->fetchColumn() === 1,
    'конкурентное изменение ACL не создало конфликтующих правил');

$deleted = $runRace([
    'mode' => 'delete', 'actor' => $admin, 'node_id' => $winnerId,
    'version' => $nodeVersion($winnerId),
    'parent_version' => $folderVersion($targetFolder),
]);
$assertRace($deleted, 1, 0, 7);
$deletedFlag = (int) $pdo->query(
    'SELECT is_deleted FROM file_space_nodes WHERE id=' . $winnerId
)->fetchColumn();
filespaceCheck($deletedFlag === 1, 'конкурентное удаление переместило файл в корзину');

$restored = $runRace([
    'mode' => 'restore', 'actor' => $admin, 'node_id' => $winnerId,
    'version' => $nodeVersion($winnerId),
    'parent_version' => $folderVersion($targetFolder),
]);
$assertRace($restored, 1, 0, 7);
$download = (new FileSpaceTransferService($pdo, $access, new MutationCommandStore(), $racePrivate))
    ->download($admin, $winnerId);
filespaceCheck(
    file_get_contents($download['path']) === 'Параллельное испытание содержимого Files 1.2',
    'после конкурентного удаления и восстановления байты файла не повреждены'
);

$phases = [
    'загрузка' => $uploadResults, 'повторы' => $allReplays, 'переименование' => $rename,
    'перемещение' => $move, 'правила' => $acl, 'удаление' => $deleted,
    'восстановление' => $restored,
];
foreach ($phases as $label => $reports) {
    $times = array_column($reports, 'elapsed_ms');
    sort($times, SORT_NUMERIC);
    $p95 = $times[(int) ceil(count($times) * 0.95) - 1];
    echo sprintf("Конкуренция Files 1.2: %s — %d процессов, p95 %.1f мс\n",
        $label, count($reports), $p95);
}

foreach (glob(dirname($download['path']) . '/*') ?: [] as $file) {
    if (is_file($file)) {
        @unlink($file);
    }
}
@rmdir(dirname($download['path']));
@rmdir(dirname(dirname($download['path'])));
@rmdir($racePrivate);
@rmdir($raceHome);
echo "Контракт реальных параллельных операций Files 1.2 выполнен\n";
