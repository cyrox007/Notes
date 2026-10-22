<?php

declare(strict_types=1);

/**
 * Испытание долговечности журнала при гибели отдельных PHP-процессов.
 * Не заменяет возврат файлов и БД штатным обновлятором.
 */
require_once dirname(__DIR__, 2) . '/core/UpdateTransactionJournal.php';
require_once dirname(__DIR__, 2) . '/core/UpdateTransactionStateMachine.php';

use Core\UpdateTransactionJournal;
use Core\UpdateTransactionStateMachine;

function assertCrashState(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Прерывание обновления: ' . $message);
    }
}

function stateMachine(string $root): UpdateTransactionStateMachine
{
    return new UpdateTransactionStateMachine($root . '/state', $root . '/live');
}

function runInterruptedProcess(string $root, string $transaction, string $phase, int $expectedCode): void
{
    $runner = [PHP_BINARY, __FILE__, $phase, $root, $transaction];
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($runner, $descriptors, $pipes, dirname(__DIR__, 2));
    assertCrashState(is_resource($process), 'не запущен отдельный процесс');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    assertCrashState($exit === $expectedCode,
        'дочерний процесс не прерван в ожидаемой точке: ' . $exit . ' ' . $output . ' ' . $errors);
}

function removeCrashTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }
    $items = scandir($path);
    if (!is_array($items)) {
        throw new RuntimeException('Не удалось просмотреть тестовые файлы');
    }
    foreach ($items as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $next = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($next) && !is_link($next)) {
            removeCrashTree($next);
        } else {
            unlink($next);
        }
    }
    rmdir($path);
}

$phase = $argv[1] ?? '';
if ($phase === 'crash-switch' || $phase === 'crash-rollback') {
    $root = (string) ($argv[2] ?? '');
    $transaction = (string) ($argv[3] ?? '');
    $machine = stateMachine($root);
    if ($phase === 'crash-switch') {
        $machine->markLiveMutationStarted($transaction, ['phase' => 'code']);
        $machine->markCodeSwitched($transaction, ['files' => 1]);
        exit(73); // Имитация смерти PHP-процесса до применения миграций.
    }
    $machine->markRollbackStarted($transaction, ['reason' => 'test_interruption']);
    $machine->markCodeRestored($transaction, ['files' => 1]);
    exit(74); // Процесс исчез после контрольной точки возврата кода.
}

assertCrashState(function_exists('proc_open'), 'proc_open недоступен');
$root = sys_get_temp_dir() . '/notes-rollback-test-' . bin2hex(random_bytes(8));
foreach (['live', 'candidate', 'backup', 'state'] as $dir) {
    assertCrashState(mkdir($root . '/' . $dir, 0700, true), 'не создан тестовый каталог');
}

try {
    $transaction = 'crash-test-11000';
    $manifest = $root . '/candidate/tree.json';
    file_put_contents($manifest, json_encode([
        'target_version' => '1.2.0',
        'target_version_code' => 12000,
        'file_count' => 1,
        'total_bytes' => 4,
    ], JSON_THROW_ON_ERROR));

    $backupManifest = $root . '/backup/backup.json';
    file_put_contents($backupManifest, json_encode(['transaction_id' => $transaction], JSON_THROW_ON_ERROR));

    $journal = new UpdateTransactionJournal($root . '/state', $root . '/live');
    $journal->initialize([
        'transaction_id' => $transaction,
        'installed_version' => '1.1.0',
        'installed_version_code' => 11000,
        'target_version' => '1.2.0',
        'target_version_code' => 12000,
        'package_sha256' => hash('sha256', 'test-package'),
        'stage_dir' => $root . '/candidate',
    ]);
    $journal->recordBackups($transaction, [
        'backup_dir' => $root . '/backup',
        'manifest_path' => $backupManifest,
        'manifest_sha256' => hash_file('sha256', $backupManifest),
        'code' => ['files' => 1],
        'database' => ['tables' => 1],
    ]);

    $machine = stateMachine($root);
    $machine->recordCandidate($transaction, [
        'candidate_dir' => $root . '/candidate',
        'tree_manifest' => $manifest,
        'tree_sha256' => hash_file('sha256', $manifest),
        'target_version' => '1.2.0',
        'target_version_code' => 12000,
        'files' => 1,
        'total_bytes' => 4,
    ]);
    $machine->markPreflightVerified($transaction, ['health_status' => 'ok']);

    runInterruptedProcess($root, $transaction, 'crash-switch', 73);
    $reopened = stateMachine($root)->load($transaction);
    assertCrashState($reopened['state'] === 'code_switched'
        && $reopened['live_mutation_started'] === true,
        'после гибели процесса не сохранена граница изменения');

    $rejected = false;
    try {
        stateMachine($root)->markCommitted($transaction, []);
    } catch (RuntimeException) {
        $rejected = true;
    }
    assertCrashState($rejected, 'повреждённую транзакцию разрешено завершить без отката');

    runInterruptedProcess($root, $transaction, 'crash-rollback', 74);
    $restarted = stateMachine($root);
    assertCrashState($restarted->load($transaction)['state'] === 'code_restored',
        'после повторной гибели процесса потеряна точка восстановления кода');

    $restarted->markDatabaseRestored($transaction, ['tables' => 1]);
    $restarted->markRollbackVerified($transaction, ['version' => '1.1.0']);
    $final = stateMachine($root)->load($transaction);
    assertCrashState($final['state'] === 'rollback_verified'
        && $final['live_mutation_started'] === true,
        'после возобновления откат не завершился подтверждённым состоянием');
    $history = array_column($final['history'], 'state');
    foreach (['code_switched', 'rollback_started', 'code_restored',
              'database_restored', 'rollback_verified'] as $step) {
        assertCrashState(in_array($step, $history, true), 'в истории отсутствует ' . $step);
    }
    echo "Журнал пережил два прерывания PHP; восстановление возобновлено по контрольным точкам\n";
} finally {
    removeCrashTree($root);
}
