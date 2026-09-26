<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';

use JsonException;
use RuntimeException;

/**
 * Read-only verification boundary for live updater candidates and rollback code
 * snapshots. It performs no live filesystem mutation.
 */
final class UpdateCandidateVerifier
{
    /** @param list<string> $preservedRoots */
    public function __construct(
        private readonly string $appRoot,
        private readonly array $preservedRoots
    ) {}

    /**
     * @return array{candidate_dir:string,target_version:string,target_version_code:int,tree_sha256:string,files:int,total_bytes:int,top_level:list<string>,file_map:array<string,array{size:int,sha256:string}>}
     */
    public function verifyCandidateTree(string $candidateDir): array
    {
        $input = $candidateDir;
        $candidateDir = realpath($candidateDir);
        if (!is_string($candidateDir) || !is_dir($candidateDir) || is_link($input)) {
            throw new RuntimeException('Release candidate directory cannot be resolved safely');
        }
        $candidateDir = UpdatePath::normalize($candidateDir);
        if (UpdatePath::inside($candidateDir, $this->appRoot)) {
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
        $fileMap = [];
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
            $fileMap[$relative] = [
                'size' => $size,
                'sha256' => (string) $expectedFiles[$relative]['sha256'],
            ];
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
        ksort($fileMap, SORT_STRING);

        return [
            'candidate_dir' => $candidateDir,
            'target_version' => (string) ($tree['target_version'] ?? ''),
            'target_version_code' => (int) ($tree['target_version_code'] ?? 0),
            'tree_sha256' => $treeHash,
            'files' => count($seen),
            'total_bytes' => $bytes,
            'top_level' => $tops,
            'file_map' => $fileMap,
        ];
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
    public function loadCodeManifest(string $backupDir): array
    {
        $input = $backupDir;
        $backupDir = realpath($backupDir);
        if (!is_string($backupDir) || !is_dir($backupDir) || is_link($input)) {
            throw new RuntimeException('Updater rollback backup directory is missing or unsafe');
        }
        $backupDir = UpdatePath::normalize($backupDir);
        if (UpdatePath::inside($backupDir, $this->appRoot)) {
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
        if (!UpdatePath::safeRelative($manifestName)) {
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
            if (!UpdatePath::safeRelative($relative)) {
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
    public function verifyLiveAgainstBackup(array $backup): void
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
    public function topLevelsFromEntries(array $entries): array
    {
        $tops = [];
        foreach ($entries as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            if (!UpdatePath::safeRelative($relative)) {
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
    public function verifyPreparedCandidate(string $prepared, string $candidate, array $candidateTops): void
    {
        foreach ($candidateTops as $top) {
            $this->compareEntries($candidate . '/' . $top, $prepared . '/' . $top, $top);
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

    private function isPreservedRoot(string $name): bool
    {
        return in_array($name, $this->preservedRoots, true);
    }

    private function isPreservedEnvName(string $name): bool
    {
        return $name === '.env' || str_starts_with($name, '.env.');
    }
}
