<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/core/UpdateLiveApplier.php';
require_once dirname(__DIR__, 2) . '/core/UpdateRollbackCodeRestorer.php';

function interruptedCodeAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function interruptedCodeWrite(string $path, string $bytes): void
{
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
    interruptedCodeAssert(file_put_contents($path, $bytes) === strlen($bytes), 'Fixture write failed');
    chmod($path, 0644);
}

if (($argv[1] ?? '') === '--worker') {
    $config = json_decode((string) file_get_contents($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    $phase = $argv[3];
    $pause = ($argv[4] ?? '') === '--pause';
    for ($step = 0; $step < 256; ++$step) {
        try {
            $budget = new Core\UpdateStepBudget($pause ? 1 : 64);
            if ($phase === 'apply') {
                (new Core\UpdateLiveApplier($config['live']))->switchPrepared($config['plan'], $budget);
            } else {
                (new Core\UpdateRollbackCodeRestorer($config['live']))->restore(
                    $config['transaction'], $config['backup'], $budget);
            }
            exit(0);
        } catch (Core\UpdateStepPending) {
            if ($pause) {
                interruptedCodeWrite($config['ready'], $phase);
                // Долговечная граница первого файла: родитель убивает настоящий
                // процесс, следующий исполнитель не получает его PHP-состояние.
                while (true) usleep(10000);
            }
        }
    }
    throw new RuntimeException('Worker failed to finish');
}

$temp = sys_get_temp_dir() . '/notes-code-kill-' . bin2hex(random_bytes(6));
$live = $temp . '/live';
$candidate = $temp . '/candidate';
$transaction = 'interrupted-code-001';
$backup = $temp . '/backup/' . $transaction;
$ready = $temp . '/ready';
$worker = null;
mkdir($live, 0755, true);
try {
    $old = ['core/Version.php' => "<?php namespace Core; class Version { const VERSION='old'; const VERSION_CODE=1; }\n"];
    $new = ['core/Version.php' => "<?php namespace Core; class Version { const VERSION='new'; const VERSION_CODE=2; }\n"];
    for ($i = 0; $i < 32; ++$i) {
        $old[sprintf('files/%03d.txt', $i)] = 'old-' . $i;
        $new[sprintf('files/%03d.txt', $i)] = 'new-' . $i;
    }
    $entries = [];
    $files = [];
    foreach ($old as $path => $bytes) {
        interruptedCodeWrite($live . '/' . $path, $bytes);
        interruptedCodeWrite($backup . '/code/' . $path, $bytes);
        $entries[] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'mode' => 0644];
    }
    foreach ($new as $path => $bytes) {
        interruptedCodeWrite($candidate . '/' . $path, $bytes);
        $files[$path] = ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
    }
    $codeBytes = json_encode(['schema' => 1, 'files' => count($entries),
        'bytes' => array_sum(array_column($entries, 'size')), 'excluded_roots' => [], 'entries' => $entries], JSON_THROW_ON_ERROR);
    interruptedCodeWrite($backup . '/code-manifest.json', $codeBytes);
    interruptedCodeWrite($backup . '/backup.json', json_encode([
        'schema' => 1, 'transaction_id' => $transaction, 'created_at' => time(),
        'application_root' => str_replace('\\', '/', (string) realpath($live)),
        'code' => ['path' => 'code', 'manifest' => 'code-manifest.json',
            'manifest_sha256' => hash('sha256', $codeBytes), 'files' => count($entries),
            'bytes' => array_sum(array_column($entries, 'size'))], 'database' => [],
    ], JSON_THROW_ON_ERROR));
    interruptedCodeWrite($candidate . '/.workspace-release-tree.json', json_encode([
        'schema' => 1, 'archive_root' => 'workspace-code-kill', 'target_version' => 'new',
        'target_version_code' => 2, 'files' => $files, 'file_count' => count($files),
        'total_bytes' => array_sum(array_column($files, 'size')),
    ], JSON_THROW_ON_ERROR));
    $preserved = ['uploads/user.txt' => 'user-data', 'update-continuations/entry.php' => '<?php // capability entry'];
    foreach ($preserved as $path => $bytes) interruptedCodeWrite($live . '/' . $path, $bytes);
    $plan = (new Core\UpdateLiveApplier($live))->prepareCodeSwitch($transaction, $candidate, $backup);
    $configPath = $temp . '/config.json';
    interruptedCodeWrite($configPath, json_encode(compact('live', 'backup', 'transaction', 'plan', 'ready'), JSON_THROW_ON_ERROR));
    $start = static function (string $phase, bool $pause) use ($configPath, $temp) {
        $command = [PHP_BINARY, __FILE__, '--worker', $configPath, $phase];
        if ($pause) $command[] = '--pause';
        $process = proc_open($command, [0 => ['pipe', 'r'],
            1 => ['file', $temp . '/worker.log', 'a'], 2 => ['file', $temp . '/worker.log', 'a']], $pipes);
        interruptedCodeAssert(is_resource($process), 'Worker did not start');
        fclose($pipes[0]);
        return $process;
    };
    foreach (['apply' => $new, 'rollback' => $old] as $phase => $expected) {
        @unlink($ready);
        $worker = $start($phase, true);
        $deadline = microtime(true) + 20;
        do {
            clearstatcache(true, $ready);
            if (is_file($ready)) break;
            interruptedCodeAssert(proc_get_status($worker)['running'],
                'Worker stopped before first checkpoint: ' . file_get_contents($temp . '/worker.log'));
            usleep(10000);
        } while (microtime(true) < $deadline);
        interruptedCodeAssert(is_file($ready), 'Worker checkpoint timeout');
        $matches = 0;
        foreach ($expected as $path => $bytes) $matches += file_get_contents($live . '/' . $path) === $bytes ? 1 : 0;
        interruptedCodeAssert($matches > 0 && $matches < count($expected), 'Kill must interrupt a partially changed tree');
        interruptedCodeAssert(proc_terminate($worker, 9), 'Cannot kill worker');
        proc_close($worker);
        $worker = $start($phase, false);
        $exit = proc_close($worker);
        $worker = null;
        interruptedCodeAssert($exit === 0, 'Restart failed: ' . file_get_contents($temp . '/worker.log'));
        foreach ($expected + $preserved as $path => $bytes) {
            interruptedCodeAssert(file_get_contents($live . '/' . $path) === $bytes, 'Unexpected file after restart: ' . $path);
        }
    }
    echo "[OK] Process kill after first durable code checkpoint: apply and rollback resume; user files and HTTP entry survive\n";
} finally {
    if (is_resource($worker)) { proc_terminate($worker, 9); proc_close($worker); }
    Core\UpdatePath::removeTree($temp);
}
