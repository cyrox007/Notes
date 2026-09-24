<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/UpdateArtifactCleaner.php';

use Core\UpdateArtifactCleaner;

function retentionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function retentionWriteJson(string $path, array $payload): void
{
    $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write updater retention fixture journal');
    }
}

function retentionMkdir(string $path): void
{
    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Cannot create updater retention fixture directory');
    }
}

function retentionRemoveTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            retentionRemoveTree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}

$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'notes-updater-retention-' . bin2hex(random_bytes(6));
$app = $tmp . '/app';
$state = $tmp . '/state';
$transactions = $state . '/transactions';
$backups = $tmp . '/backups';
$candidates = $tmp . '/candidates';

foreach ([$app, $transactions, $backups, $candidates] as $dir) {
    retentionMkdir($dir);
}

$now = 1800000000;
$old = $now - (90 * 86400);
$recent = $now - 3600;
$sharedPackage = str_repeat('a', 64);
$oldPackage = str_repeat('b', 64);
$failedPackage = str_repeat('c', 64);

$journals = [
    'update-old-shared' => [
        'state' => 'committed',
        'updated_at' => $old,
        'package_sha256' => $sharedPackage,
    ],
    'update-old-delete' => [
        'state' => 'rollback_verified',
        'updated_at' => $old - 60,
        'package_sha256' => $oldPackage,
    ],
    'update-recent-keep' => [
        'state' => 'committed',
        'updated_at' => $recent,
        'package_sha256' => $sharedPackage,
    ],
    'update-failed-keep' => [
        'state' => 'rollback_failed',
        'updated_at' => $old - 120,
        'package_sha256' => $failedPackage,
    ],
];

foreach ($journals as $id => $fixture) {
    $backupDir = $backups . '/' . $id;
    retentionMkdir($backupDir);
    file_put_contents($backupDir . '/payload.bin', str_repeat('x', 32));
    file_put_contents($backupDir . '/backup.json', "backup:" . $id . "\n");
    $backupManifestHash = hash_file('sha256', $backupDir . '/backup.json');
    retentionAssert(is_string($backupManifestHash), 'cannot hash backup marker fixture');

    $candidateDir = $candidates . '/candidate-' . substr($fixture['package_sha256'], 0, 16);
    if (!is_dir($candidateDir)) {
        retentionMkdir($candidateDir);
        file_put_contents($candidateDir . '/candidate.bin', str_repeat('y', 48));
        file_put_contents(
            $candidateDir . '/.workspace-release-tree.json',
            "candidate:" . $fixture['package_sha256'] . "\n"
        );
    }
    $candidateTreeHash = hash_file('sha256', $candidateDir . '/.workspace-release-tree.json');
    retentionAssert(is_string($candidateTreeHash), 'cannot hash candidate marker fixture');

    retentionWriteJson($transactions . '/' . $id . '.json', [
        'schema' => 1,
        'transaction_id' => $id,
        'state' => $fixture['state'],
        'package_sha256' => $fixture['package_sha256'],
        'updated_at' => $fixture['updated_at'],
        'backups' => [
            'backup_dir' => $backupDir,
            'manifest_sha256' => $backupManifestHash,
        ],
        'candidate' => [
            'candidate_dir' => $candidateDir,
            'tree_sha256' => $candidateTreeHash,
        ],
    ]);
}

try {
    $cleaner = new UpdateArtifactCleaner($state, $backups, $candidates, $app, $now);
    $preview = $cleaner->run(30, 1, false);

    retentionAssert(($preview['status'] ?? null) === 'preview', 'preview status mismatch');
    retentionAssert(($preview['can_apply'] ?? false) === true, 'valid retention plan should be applicable');
    retentionAssert((int) ($preview['terminal_transactions'] ?? -1) === 3, 'terminal transaction count mismatch');
    retentionAssert((int) ($preview['eligible_transactions'] ?? -1) === 2, 'eligible terminal transaction count mismatch');

    $rows = [];
    foreach ($preview['transactions'] as $row) {
        $rows[$row['transaction_id']] = $row;
    }
    retentionAssert(($rows['update-old-shared']['backup']['action'] ?? null) === 'delete', 'old terminal backup should be planned');
    retentionAssert(($rows['update-old-shared']['candidate']['action'] ?? null) === 'keep', 'candidate shared with retained transaction must be protected');
    retentionAssert(($rows['update-old-delete']['candidate']['action'] ?? null) === 'delete', 'unreferenced old candidate should be planned');
    retentionAssert(($rows['update-recent-keep']['eligible'] ?? true) === false, 'newest terminal transaction must be retained');

    $applied = $cleaner->run(30, 1, true);
    retentionAssert(($applied['status'] ?? null) === 'applied', 'apply status mismatch');
    retentionAssert((int) ($applied['deleted_directories'] ?? 0) === 3, 'expected two backups and one candidate deletion');
    retentionAssert(!is_dir($backups . '/update-old-shared'), 'eligible old backup was not deleted');
    retentionAssert(!is_dir($backups . '/update-old-delete'), 'eligible rollback backup was not deleted');
    retentionAssert(is_dir($candidates . '/candidate-' . substr($sharedPackage, 0, 16)), 'shared retained candidate was deleted');
    retentionAssert(!is_dir($candidates . '/candidate-' . substr($oldPackage, 0, 16)), 'unreferenced old candidate was not deleted');
    retentionAssert(is_dir($backups . '/update-recent-keep'), 'recent terminal backup was deleted');
    retentionAssert(is_dir($backups . '/update-failed-keep'), 'rollback_failed backup was deleted');
    retentionAssert(is_file($transactions . '/update-old-delete.json'), 'transaction journal must be preserved after cleanup');

    file_put_contents($transactions . '/broken-journal.json', "{not-json\n");
    $blockedPreview = $cleaner->run(30, 1, false);
    retentionAssert(($blockedPreview['can_apply'] ?? true) === false, 'corrupt journal must block destructive cleanup');

    $blocked = false;
    try {
        $cleaner->run(30, 1, true);
    } catch (RuntimeException) {
        $blocked = true;
    }
    retentionAssert($blocked, 'destructive cleanup must fail closed when any journal is invalid');

    echo "updater artifact retention contract: OK\n";
} finally {
    retentionRemoveTree($tmp);
}
