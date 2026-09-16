<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use mysqli;
use mysqli_result;
use RuntimeException;
use Throwable;

/**
 * Controlled filesystem switch + rollback engine used only while updater
 * maintenance is owned by the calling transaction.
 *
 * Release-owned top-level entries are switched by rename, not copied over live
 * files one-by-one. Customer/mutable roots stay in place. A verified external
 * code snapshot is authoritative for rollback; MySQL is restored from the
 * verified SQL snapshot created before the destructive boundary.
 */
final class UpdateLiveApplier
{
    /** @var list<string> */
    private const PRESERVED_ROOTS = [
        '.git',
        'vendor',
        'cache',
        'compile',
        'uploads',
        'notes-private-storage',
        '.logs',
    ];

    private string $appRoot;
    private string $parentRoot;

    public function __construct(?string $appRoot = null)
    {
        $app = realpath($appRoot ?? dirname(__DIR__));
        if (!is_string($app) || !is_dir($app) || is_link($app)) {
            throw new RuntimeException('Live application root cannot be resolved safely');
        }
        $this->appRoot = $this->normalize($app);
        $parent = realpath(dirname($app));
        if (!is_string($parent) || !is_dir($parent) || !is_writable($parent)) {
            throw new RuntimeException('Live application parent must be writable for controlled code switch');
        }
        $this->parentRoot = $this->normalize($parent);
    }

    /**
     * Fail closed when a configured mutable path is nested under a release-owned
     * top-level directory. Such a path cannot survive a directory-level switch.
     *
     * @param list<string> $paths
     */
    public function assertMutablePathsSwitchSafe(array $paths): void
    {
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            $real = realpath($path);
            if (!is_string($real)) {
                continue;
            }
            $real = $this->normalize($real);
            if (!$this->inside($real, $this->appRoot) || $real === $this->appRoot) {
                continue;
            }
            $relative = ltrim(substr($real, strlen($this->appRoot)), '/');
            $top = explode('/', $relative, 2)[0] ?? '';
            if (!$this->isPreservedRoot($top)) {
                throw new RuntimeException(
                    "Configured mutable path {$real} is nested below release-owned root {$top}; move it outside the application tree before live update"
                );
            }
        }
    }

    /**
     * Re-hash every candidate file and prove there are no extra filesystem
     * entries that were not represented by the signed/extracted tree contract.
     *
     * @return array{candidate_dir:string,target_version:string,target_version_code:int,tree_sha256:string,files:int,total_bytes:int,top_level:list<string>}
     */
    public function verifyCandidateTree(string $candidateDir): array
    {
        $input = $candidateDir;
        $candidateDir = realpath($candidateDir);
        if (!is_string($candidateDir) || !is_dir($candidateDir) || is_link($input)) {
            throw new RuntimeException('Release candidate directory cannot be resolved safely');
        }
        $candidateDir = $this->normalize($candidateDir);
        if ($this->inside($candidateDir, $this->appRoot)) {
            throw new RuntimeException('Release candidate must remain outside the live application tree');
        }

        $treePath = $candidateDir . '/.workspace-release-tree.json';
        if (!is_file($treePath) || is_link($treePath)) {
            throw new RuntimeException('Release candidate tree manifest is missing or unsafe');
        }
        $treeBytes = file_get_contents($treePath);
        try {
            $tree = is_string($treeBytes) ? json_decode($treeBytes, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Release candidate tree manifest is invalid JSON', 0, $e);
        }
        if (!is_array($tree) || array_is_list($tree) || ($tree['schema'] ?? null) !== 1 || !is_array($tree['files'] ?? null)) {
            throw new RuntimeException('Release candidate tree manifest failed schema validation');
        }

        $expectedFiles = $tree['files'];
        $seen = [];
        $bytes = 0;
        $topLevel = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($candidateDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                throw new RuntimeException('Release candidate contains unsupported filesystem entry');
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($candidateDir) + 1));
            if ($relative === '.workspace-release-tree.json') {
                continue;
            }
            if (!isset($expectedFiles[$relative]) || !is_array($expectedFiles[$relative])) {
                throw new RuntimeException("Release candidate contains untracked file: {$relative}");
            }
            $size = $file->getSize();
            $hash = hash_file('sha256', $file->getPathname());
            if (
                !is_int($size)
                || $size !== (int) ($expectedFiles[$relative]['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($expectedFiles[$relative]['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException("Release candidate verification failed: {$relative}");
            }
            $seen[$relative] = true;
            $bytes += $size;
            $top = explode('/', $relative, 2)[0];
            if (!$this->isPreservedRoot($top) && !$this->isPreservedEnvName($top)) {
                $topLevel[$top] = true;
            }
        }
        if (count($seen) !== count($expectedFiles)) {
            $missing = array_values(array_diff(array_keys($expectedFiles), array_keys($seen)));
            throw new RuntimeException('Release candidate is missing tracked file: ' . (string) ($missing[0] ?? 'unknown'));
        }
        if (count($seen) !== (int) ($tree['file_count'] ?? -1) || $bytes !== (int) ($tree['total_bytes'] ?? -1)) {
            throw new RuntimeException('Release candidate aggregate verification failed');
        }

        $treeHash = hash_file('sha256', $treePath);
        if (!is_string($treeHash)) {
            throw new RuntimeException('Cannot hash release candidate tree manifest');
        }
        $tops = array_keys($topLevel);
        sort($tops, SORT_STRING);
        return [
            'candidate_dir' => $candidateDir,
            'target_version' => (string) ($tree['target_version'] ?? ''),
            'target_version_code' => (int) ($tree['target_version_code'] ?? 0),
            'tree_sha256' => $treeHash,
            'files' => count($seen),
            'total_bytes' => $bytes,
            'top_level' => $tops,
        ];
    }

    /**
     * Prepare all candidate entries on the same filesystem as the live root.
     * This happens before live_mutation_started is recorded and cannot affect
     * application routing/runtime.
     *
     * @return array{scratch_dir:string,new_dir:string,old_dir:string,entries:list<string>}
     */
    public function prepareCodeSwitch(string $transactionId, string $candidateDir, string $backupDir): array
    {
        $this->validateTransactionId($transactionId);
        $candidate = $this->verifyCandidateTree($candidateDir);
        $backup = $this->loadCodeManifest($backupDir);
        $candidateTops = $candidate['top_level'];
        $backupTops = $this->topLevelsFromEntries($backup['entries']);
        $entries = array_values(array_unique(array_merge($candidateTops, $backupTops)));
        $entries = array_values(array_filter($entries, fn (string $name): bool => !$this->isPreservedRoot($name) && !$this->isPreservedEnvName($name)));
        sort($entries, SORT_STRING);
        if ($entries === []) {
            throw new RuntimeException('Updater live switch has no release-owned entries');
        }

        $scratch = $this->scratchPath('apply', $transactionId);
        if (file_exists($scratch) || is_link($scratch)) {
            throw new RuntimeException('Updater live switch scratch already exists; use recovery before retrying apply');
        }
        $oldUmask = umask(0077);
        $made = @mkdir($scratch, 0700, false);
        umask($oldUmask);
        if (!$made || !is_dir($scratch)) {
            throw new RuntimeException('Cannot create updater live switch scratch directory');
        }
        $newDir = $scratch . '/new';
        $oldDir = $scratch . '/old';
        if (!mkdir($newDir, 0700) || !mkdir($oldDir, 0700)) {
            $this->removeTree($scratch);
            throw new RuntimeException('Cannot initialize updater live switch scratch directories');
        }

        try {
            foreach ($candidateTops as $name) {
                if ($this->isPreservedRoot($name) || $this->isPreservedEnvName($name)) {
                    continue;
                }
                $source = $candidate['candidate_dir'] . '/' . $name;
                if (!file_exists($source) && !is_link($source)) {
                    throw new RuntimeException("Candidate top-level entry disappeared during preparation: {$name}");
                }
                $this->copyEntry($source, $newDir . '/' . $name);
            }
            $this->writePlan($scratch . '/plan.json', [
                'schema' => 1,
                'transaction_id' => $transactionId,
                'mode' => 'apply',
                'application_root' => $this->appRoot,
                'candidate_dir' => $candidate['candidate_dir'],
                'candidate_tree_sha256' => $candidate['tree_sha256'],
                'entries' => $entries,
            ]);
            $this->verifyPreparedCandidate($newDir, $candidate['candidate_dir'], $candidateTops);
        } catch (Throwable $e) {
            $this->removeTree($scratch);
            throw $e;
        }

        return ['scratch_dir' => $scratch, 'new_dir' => $newDir, 'old_dir' => $oldDir, 'entries' => $entries];
    }

    /** @param array{scratch_dir:string,new_dir:string,old_dir:string,entries:list<string>} $plan @return array<string,mixed> */
    public function switchPrepared(array $plan): array
    {
        $scratch = $this->normalize((string) ($plan['scratch_dir'] ?? ''));
        if (!is_dir($scratch) || is_link($scratch) || dirname($scratch) !== $this->parentRoot) {
            throw new RuntimeException('Updater live switch scratch path is unsafe');
        }
        $newDir = $this->normalize((string) ($plan['new_dir'] ?? ''));
        $oldDir = $this->normalize((string) ($plan['old_dir'] ?? ''));
        if ($newDir !== $scratch . '/new' || $oldDir !== $scratch . '/old' || !is_dir($newDir) || !is_dir($oldDir)) {
            throw new RuntimeException('Updater live switch scratch layout is invalid');
        }

        $switched = [];
        foreach (($plan['entries'] ?? []) as $entry) {
            $entry = (string) $entry;
            if (!$this->safeTopLevelName($entry) || $this->isPreservedRoot($entry) || $this->isPreservedEnvName($entry)) {
                throw new RuntimeException('Updater switch plan contains unsafe top-level entry');
            }
            $live = $this->appRoot . '/' . $entry;
            $old = $oldDir . '/' . $entry;
            $new = $newDir . '/' . $entry;
            $hadLive = file_exists($live) || is_link($live);
            $hasNew = file_exists($new) || is_link($new);

            if ($hadLive) {
                if (file_exists($old) || is_link($old) || !@rename($live, $old)) {
                    throw new RuntimeException("Cannot move live release entry into transaction scratch: {$entry}");
                }
            }
            if ($hasNew && !@rename($new, $live)) {
                if ($hadLive && !file_exists($live) && !is_link($live)) {
                    @rename($old, $live);
                }
                throw new RuntimeException("Cannot activate candidate release entry: {$entry}");
            }
            $switched[] = $entry;
        }

        return ['scratch_dir' => $scratch, 'entries' => $switched, 'switched_at' => time()];
    }

    /**
     * Restore release-owned code from the verified external snapshot. Mutable
     * paths and .env stay untouched.
     *
     * @return array<string,mixed>
     */
    public function restoreCode(string $transactionId, string $backupDir, string $candidateDir): array
    {
        $this->validateTransactionId($transactionId);
        $backup = $this->loadCodeManifest($backupDir);
        $candidate = $this->verifyCandidateTree($candidateDir);
        $backupTops = $this->topLevelsFromEntries($backup['entries']);
        $entries = array_values(array_unique(array_merge($backupTops, $candidate['top_level'])));
        $entries = array_values(array_filter($entries, fn (string $name): bool => !$this->isPreservedRoot($name) && !$this->isPreservedEnvName($name)));
        sort($entries, SORT_STRING);

        $scratch = $this->scratchPath('rollback', $transactionId);
        if (is_dir($scratch) && !is_link($scratch)) {
            $this->removeTree($scratch);
        } elseif (file_exists($scratch) || is_link($scratch)) {
            throw new RuntimeException('Updater rollback scratch path is unsafe');
        }
        $oldUmask = umask(0077);
        $made = @mkdir($scratch, 0700, false);
        umask($oldUmask);
        if (!$made) {
            throw new RuntimeException('Cannot create updater rollback scratch directory');
        }
        $restoreDir = $scratch . '/restore';
        $failedDir = $scratch . '/failed-release';
        if (!mkdir($restoreDir, 0700) || !mkdir($failedDir, 0700)) {
            $this->removeTree($scratch);
            throw new RuntimeException('Cannot initialize updater rollback scratch directories');
        }

        $backupCodeRoot = $backup['backup_dir'] . '/code';
        try {
            foreach ($backupTops as $name) {
                $source = $backupCodeRoot . '/' . $name;
                if (!file_exists($source) && !is_link($source)) {
                    throw new RuntimeException("Verified code backup is missing top-level entry: {$name}");
                }
                $this->copyEntry($source, $restoreDir . '/' . $name);
            }

            foreach ($entries as $entry) {
                $live = $this->appRoot . '/' . $entry;
                $failed = $failedDir . '/' . $entry;
                $restore = $restoreDir . '/' . $entry;
                if (file_exists($live) || is_link($live)) {
                    if (!@rename($live, $failed)) {
                        throw new RuntimeException("Cannot quarantine failed release entry during rollback: {$entry}");
                    }
                }
                if ((file_exists($restore) || is_link($restore)) && !@rename($restore, $live)) {
                    throw new RuntimeException("Cannot restore rollback code entry: {$entry}");
                }
            }
            $this->verifyLiveAgainstBackup($backup);
        } catch (Throwable $e) {
            throw $e;
        }

        return ['scratch_dir' => $scratch, 'entries' => $entries, 'restored_at' => time()];
    }

    /**
     * Restore the complete MySQL schema/data snapshot, including removal of
     * objects introduced by a failed migration.
     *
     * @param array<string,mixed> $databaseMetadata
     * @return array<string,mixed>
     */
    public function restoreDatabase(mysqli $db, string $backupDir, array $databaseMetadata): array
    {
        $backupDirReal = realpath($backupDir);
        if (!is_string($backupDirReal) || !is_dir($backupDirReal) || is_link($backupDir)) {
            throw new RuntimeException('Rollback backup directory cannot be resolved safely');
        }
        $backupDirReal = $this->normalize($backupDirReal);
        $relative = (string) ($databaseMetadata['path'] ?? '');
        if (!$this->safeRelativePath($relative)) {
            throw new RuntimeException('Rollback database dump path is invalid');
        }
        $dumpPath = $backupDirReal . '/' . $relative;
        if (!is_file($dumpPath) || is_link($dumpPath)) {
            throw new RuntimeException('Rollback database dump is missing or unsafe');
        }
        $size = filesize($dumpPath);
        $hash = hash_file('sha256', $dumpPath);
        if (
            !is_int($size)
            || $size !== (int) ($databaseMetadata['bytes'] ?? -1)
            || !is_string($hash)
            || !hash_equals((string) ($databaseMetadata['sha256'] ?? ''), $hash)
        ) {
            throw new RuntimeException('Rollback database dump failed final SHA-256/size verification');
        }
        $sql = file_get_contents($dumpPath);
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('Rollback database dump cannot be read');
        }

        $this->dropCurrentDatabaseObjects($db);
        foreach ($this->parseSqlStatements($sql) as $statement) {
            $result = $db->query($statement);
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            $this->drainResults($db);
        }
        $verified = $this->verifyRestoredDatabase($db, $databaseMetadata);
        return ['dump_sha256' => $hash, 'dump_bytes' => $size] + $verified + ['restored_at' => time()];
    }

    /** @return array{version:string,version_code:int} */
    public function readLiveVersion(): array
    {
        $path = $this->appRoot . '/core/Version.php';
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new RuntimeException('Live Version.php cannot be read');
        }
        if (!preg_match("/public const VERSION = '([^']+)'/", $source, $version)
            || !preg_match('/public const VERSION_CODE = ([0-9]+);/', $source, $code)) {
            throw new RuntimeException('Live Version.php contract cannot be parsed');
        }
        return ['version' => $version[1], 'version_code' => (int) $code[1]];
    }

    /** @return array{backup_dir:string,entries:list<array<string,mixed>>} */
    private function loadCodeManifest(string $backupDir): array
    {
        $input = $backupDir;
        $backupDir = realpath($backupDir);
        if (!is_string($backupDir) || !is_dir($backupDir) || is_link($input)) {
            throw new RuntimeException('Updater rollback backup directory is missing or unsafe');
        }
        $backupDir = $this->normalize($backupDir);
        if ($this->inside($backupDir, $this->appRoot)) {
            throw new RuntimeException('Updater rollback backup must remain outside the live application tree');
        }
        $backupJson = $backupDir . '/backup.json';
        $bytes = is_file($backupJson) && !is_link($backupJson) ? file_get_contents($backupJson) : false;
        try {
            $backup = is_string($bytes) ? json_decode($bytes, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Updater rollback backup manifest is invalid JSON', 0, $e);
        }
        if (!is_array($backup) || !is_array($backup['code'] ?? null)) {
            throw new RuntimeException('Updater rollback backup manifest is incomplete');
        }
        $manifestName = (string) ($backup['code']['manifest'] ?? '');
        if (!$this->safeRelativePath($manifestName)) {
            throw new RuntimeException('Updater code backup manifest path is invalid');
        }
        $manifestPath = $backupDir . '/' . $manifestName;
        $manifestBytes = is_file($manifestPath) && !is_link($manifestPath) ? file_get_contents($manifestPath) : false;
        $manifestHash = is_file($manifestPath) ? hash_file('sha256', $manifestPath) : false;
        try {
            $manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Updater code backup manifest is invalid JSON', 0, $e);
        }
        if (
            !is_array($manifest)
            || !is_array($manifest['entries'] ?? null)
            || !is_string($manifestHash)
            || !hash_equals((string) ($backup['code']['manifest_sha256'] ?? ''), $manifestHash)
        ) {
            throw new RuntimeException('Updater code backup manifest verification failed');
        }
        /** @var list<array<string,mixed>> $entries */
        $entries = array_values($manifest['entries']);
        foreach ($entries as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            if (!$this->safeRelativePath($relative)) {
                throw new RuntimeException('Updater code backup contains unsafe relative path');
            }
            $path = $backupDir . '/code/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (
                !is_int($size)
                || $size !== (int) ($entry['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($entry['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException("Updater code rollback snapshot verification failed: {$relative}");
            }
        }
        return ['backup_dir' => $backupDir, 'entries' => $entries];
    }

    /** @param array{backup_dir:string,entries:list<array<string,mixed>>} $backup */
    private function verifyLiveAgainstBackup(array $backup): void
    {
        foreach ($backup['entries'] as $entry) {
            $relative = (string) $entry['path'];
            $path = $this->appRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (
                !is_int($size)
                || $size !== (int) ($entry['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($entry['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException("Restored live code failed backup verification: {$relative}");
            }
        }
    }

    /** @param list<array<string,mixed>> $entries @return list<string> */
    private function topLevelsFromEntries(array $entries): array
    {
        $tops = [];
        foreach ($entries as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            if (!$this->safeRelativePath($relative)) {
                throw new RuntimeException('Unsafe code snapshot path');
            }
            $top = explode('/', $relative, 2)[0];
            if (!$this->isPreservedRoot($top) && !$this->isPreservedEnvName($top)) {
                $tops[$top] = true;
            }
        }
        $result = array_keys($tops);
        sort($result, SORT_STRING);
        return $result;
    }

    /** @param list<string> $candidateTops */
    private function verifyPreparedCandidate(string $prepared, string $candidate, array $candidateTops): void
    {
        foreach ($candidateTops as $top) {
            $source = $candidate . '/' . $top;
            $copy = $prepared . '/' . $top;
            $this->compareEntries($source, $copy, $top);
        }
    }

    private function compareEntries(string $expected, string $actual, string $label): void
    {
        if (is_link($expected) || is_link($actual)) {
            throw new RuntimeException("Updater prepared release refuses symlink: {$label}");
        }
        if (is_file($expected)) {
            if (!is_file($actual)) {
                throw new RuntimeException("Prepared release is missing file: {$label}");
            }
            $a = hash_file('sha256', $expected);
            $b = hash_file('sha256', $actual);
            if (!is_string($a) || !is_string($b) || !hash_equals($a, $b)) {
                throw new RuntimeException("Prepared release verification failed: {$label}");
            }
            return;
        }
        if (!is_dir($expected) || !is_dir($actual)) {
            throw new RuntimeException("Prepared release entry type mismatch: {$label}");
        }
        $expectedItems = array_values(array_diff(scandir($expected) ?: [], ['.', '..']));
        $actualItems = array_values(array_diff(scandir($actual) ?: [], ['.', '..']));
        sort($expectedItems, SORT_STRING);
        sort($actualItems, SORT_STRING);
        if ($expectedItems !== $actualItems) {
            throw new RuntimeException("Prepared release directory contents differ: {$label}");
        }
        foreach ($expectedItems as $item) {
            $this->compareEntries($expected . '/' . $item, $actual . '/' . $item, $label . '/' . $item);
        }
    }

    private function copyEntry(string $source, string $destination): void
    {
        if (is_link($source)) {
            throw new RuntimeException('Updater refuses symlink while preparing code switch');
        }
        if (is_file($source)) {
            $this->ensureDirectory(dirname($destination));
            $input = @fopen($source, 'rb');
            $output = @fopen($destination, 'xb');
            if ($input === false || $output === false) {
                if (is_resource($input)) {
                    fclose($input);
                }
                if (is_resource($output)) {
                    fclose($output);
                }
                throw new RuntimeException('Cannot copy updater release file into switch scratch');
            }
            try {
                $copied = stream_copy_to_stream($input, $output);
                if (!is_int($copied) || !fflush($output)) {
                    throw new RuntimeException('Updater release file copy did not complete');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $mode = fileperms($source);
            @chmod($destination, is_int($mode) ? ($mode & 0777) : 0644);
            return;
        }
        if (!is_dir($source)) {
            throw new RuntimeException('Updater release contains unsupported filesystem entry');
        }
        $this->ensureDirectory($destination);
        $items = scandir($source);
        if (!is_array($items)) {
            throw new RuntimeException('Cannot read updater release directory');
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->copyEntry($source . '/' . $item, $destination . '/' . $item);
        }
    }

    /**
     * Scratch containers stay 0700, but directories that may be renamed into
     * the live release must remain traversable by the web/PHP service account.
     */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            return;
        }
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Updater scratch directory path collides with existing entry');
        }
        $old = umask(0022);
        $ok = @mkdir($path, 0755, true);
        umask($old);
        if (!$ok && !is_dir($path)) {
            throw new RuntimeException('Cannot create updater scratch directory');
        }
        @chmod($path, 0755);
    }

    /** @param array<string,mixed> $payload */
    private function writePlan(string $path, array $payload): void
    {
        $bytes = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot write updater switch plan');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new RuntimeException('Cannot write complete updater switch plan');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    private function dropCurrentDatabaseObjects(mysqli $db): void
    {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            $viewNames = [];
            $views = $db->query("SELECT TABLE_NAME FROM information_schema.views WHERE table_schema=DATABASE() ORDER BY TABLE_NAME");
            while ($row = $views->fetch_assoc()) {
                $viewNames[] = (string) $row['TABLE_NAME'];
            }
            $views->free();
            foreach ($viewNames as $name) {
                $db->query('DROP VIEW IF EXISTS ' . $this->quoteIdentifier($name));
            }

            $eventNames = [];
            $events = $db->query("SELECT EVENT_NAME FROM information_schema.events WHERE event_schema=DATABASE() ORDER BY EVENT_NAME");
            while ($row = $events->fetch_assoc()) {
                $eventNames[] = (string) $row['EVENT_NAME'];
            }
            $events->free();
            foreach ($eventNames as $name) {
                $db->query('DROP EVENT IF EXISTS ' . $this->quoteIdentifier($name));
            }

            $routineNames = [];
            $routines = $db->query("SELECT ROUTINE_NAME,ROUTINE_TYPE FROM information_schema.routines WHERE routine_schema=DATABASE() ORDER BY ROUTINE_NAME");
            while ($row = $routines->fetch_assoc()) {
                $routineNames[] = [(string) $row['ROUTINE_NAME'], strtoupper((string) $row['ROUTINE_TYPE'])];
            }
            $routines->free();
            foreach ($routineNames as [$name, $type]) {
                if (!in_array($type, ['PROCEDURE', 'FUNCTION'], true)) {
                    throw new RuntimeException('Unsupported database routine type during rollback');
                }
                $db->query('DROP ' . $type . ' IF EXISTS ' . $this->quoteIdentifier($name));
            }

            $tables = $db->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
            $names = [];
            while ($row = $tables->fetch_assoc()) {
                $names[] = $this->quoteIdentifier((string) $row['TABLE_NAME']);
            }
            $tables->free();
            if ($names !== []) {
                $db->query('DROP TABLE IF EXISTS ' . implode(',', $names));
            }
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<string> */
    private function parseSqlStatements(string $sql): array
    {
        $delimiter = ';';
        $buffer = '';
        $statements = [];
        $lines = preg_split('/\R/u', $sql);
        if ($lines === false) {
            throw new RuntimeException('Rollback SQL is not valid UTF-8 text');
        }
        foreach ($lines as $line) {
            if (preg_match('/^\s*--/', $line) === 1) {
                continue;
            }
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match) === 1) {
                if (trim($buffer) !== '') {
                    throw new RuntimeException('Rollback SQL changed DELIMITER before statement ended');
                }
                $delimiter = $match[1];
                continue;
            }
            if (trim($line) === '' && trim($buffer) === '') {
                continue;
            }
            $buffer .= $line . "\n";
            $trimmed = rtrim($buffer);
            if ($trimmed === '' || !str_ends_with($trimmed, $delimiter)) {
                continue;
            }
            $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
            $buffer = '';
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
        if (trim($buffer) !== '') {
            throw new RuntimeException('Rollback SQL contains unterminated statement');
        }
        return $statements;
    }

    /** @return array{tables:int,triggers:int} */
    private function verifyRestoredDatabase(mysqli $db, array $metadata): array
    {
        $expected = [];
        foreach (($metadata['table_metadata'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['name'], $row['rows'])) {
                throw new RuntimeException('Rollback database metadata is incomplete');
            }
            $expected[(string) $row['name']] = (int) $row['rows'];
        }
        if ($expected === []) {
            throw new RuntimeException('Rollback database metadata contains no tables');
        }
        $result = $db->query("SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $actualNames = [];
        while ($row = $result->fetch_assoc()) {
            $actualNames[] = (string) $row['TABLE_NAME'];
        }
        $result->free();
        $expectedNames = array_keys($expected);
        sort($expectedNames, SORT_STRING);
        if ($actualNames !== $expectedNames) {
            throw new RuntimeException('Restored database table set does not match rollback snapshot');
        }
        foreach ($expected as $table => $rows) {
            $countResult = $db->query('SELECT COUNT(*) AS c FROM ' . $this->quoteIdentifier($table));
            $actual = (int) ($countResult->fetch_assoc()['c'] ?? -1);
            $countResult->free();
            if ($actual !== $rows) {
                throw new RuntimeException("Restored database row count mismatch for {$table}");
            }
        }
        $triggerResult = $db->query("SELECT COUNT(*) AS c FROM information_schema.triggers WHERE trigger_schema=DATABASE()");
        $triggers = (int) ($triggerResult->fetch_assoc()['c'] ?? -1);
        $triggerResult->free();
        if ($triggers !== (int) ($metadata['triggers'] ?? -2)) {
            throw new RuntimeException('Restored database trigger count does not match rollback snapshot');
        }
        return ['tables' => count($expected), 'triggers' => $triggers];
    }

    private function drainResults(mysqli $db): void
    {
        while ($db->more_results()) {
            $db->next_result();
            if ($result = $db->store_result()) {
                $result->free();
            }
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function scratchPath(string $mode, string $transactionId): string
    {
        $base = basename($this->appRoot);
        return $this->parentRoot . '/.' . $base . '.update-' . $mode . '-' . $transactionId;
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/', trim($transactionId)) !== 1) {
            throw new RuntimeException('Invalid updater transaction id');
        }
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/')) {
            return false;
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return false;
            }
        }
        return true;
    }

    private function safeTopLevelName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, "\0");
    }

    private function isPreservedRoot(string $name): bool
    {
        return in_array($name, self::PRESERVED_ROOTS, true);
    }

    private function isPreservedEnvName(string $name): bool
    {
        return $name === '.env' || str_starts_with($name, '.env.');
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
