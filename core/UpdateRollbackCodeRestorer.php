<?php

declare(strict_types=1);

namespace Core;

require_once __DIR__ . '/UpdatePath.php';
require_once __DIR__ . '/UpdateFileMutator.php';

use JsonException;
use RuntimeException;

/**
 * Восстанавливает release-owned live-код из проверенного снимка до обновления.
 *
 * После destructive boundary единственным источником истины для rollback
 * является внешний backup. Candidate для восстановления не требуется.
 * Каталоги верхнего уровня не переименовываются: лишние файлы удаляются,
 * а сохранённые файлы возвращаются по одному через UpdateFileMutator.
 */
final class UpdateRollbackCodeRestorer
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
    private UpdateFileMutator $mutator;

    public function __construct(string $appRoot)
    {
        $real = realpath($appRoot);
        if (!is_string($real) || !is_dir($real) || is_link($appRoot)) {
            throw new RuntimeException('Не удалось безопасно определить live-root для rollback updater');
        }

        $this->appRoot = UpdatePath::normalize($real);
        $this->mutator = new UpdateFileMutator($this->appRoot, self::PRESERVED_ROOTS);
    }

    /** @return array<string,mixed> */
    public function restore(string $transactionId, string $backupDir): array
    {
        $this->validateTransactionId($transactionId);
        $backup = $this->loadVerifiedCodeBackup($backupDir, $transactionId);
        $backupMap = $this->backupMap($backup['entries']);
        $liveMap = $this->mutator->releaseFiles();

        $delete = array_values(array_diff(array_keys($liveMap), array_keys($backupMap)));
        usort($delete, [$this, 'deepestFirst']);

        foreach ($delete as $relative) {
            $this->mutator->delete($relative, $transactionId);
        }

        $restore = array_keys($backupMap);
        usort($restore, [$this, 'shallowestFirst']);

        foreach ($restore as $relative) {
            $entry = $backupMap[$relative];
            $source = $backup['backup_dir']
                . DIRECTORY_SEPARATOR
                . 'code'
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            $this->mutator->replaceVerified(
                $source,
                $relative,
                (int) $entry['size'],
                (string) $entry['sha256'],
                (int) $entry['mode'],
                $transactionId
            );
        }

        $this->verifyExactLiveSnapshot($backupMap);

        return [
            'entries' => $restore,
            'deleted_files' => $delete,
            'restored_files' => count($restore),
            'source' => 'verified_backup',
            'candidate_required' => false,
            'file_level' => true,
            'restored_at' => time(),
        ];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return array<string,array{path:string,sha256:string,size:int,mode:int}>
     */
    private function backupMap(array $entries): array
    {
        $map = [];

        foreach ($entries as $entry) {
            $relative = (string) ($entry['path'] ?? '');
            if (!$this->mutator->isReleaseOwned($relative)) {
                throw new RuntimeException('Backup updater содержит защищённый или небезопасный путь: ' . $relative);
            }
            if (isset($map[$relative])) {
                throw new RuntimeException('Backup updater содержит повторяющийся путь: ' . $relative);
            }

            $map[$relative] = [
                'path' => $relative,
                'sha256' => (string) ($entry['sha256'] ?? ''),
                'size' => (int) ($entry['size'] ?? -1),
                'mode' => (int) ($entry['mode'] ?? 0644),
            ];
        }

        ksort($map, SORT_STRING);
        return $map;
    }

    /**
     * @param array<string,array{path:string,sha256:string,size:int,mode:int}> $backupMap
     */
    private function verifyExactLiveSnapshot(array $backupMap): void
    {
        $liveMap = $this->mutator->releaseFiles();

        if (array_keys($liveMap) !== array_keys($backupMap)) {
            $extra = array_values(array_diff(array_keys($liveMap), array_keys($backupMap)));
            $missing = array_values(array_diff(array_keys($backupMap), array_keys($liveMap)));
            throw new RuntimeException(
                'Пофайловый rollback не восстановил точный набор release-owned файлов'
                . ($extra !== [] ? '; лишний: ' . $extra[0] : '')
                . ($missing !== [] ? '; отсутствует: ' . $missing[0] : '')
            );
        }

        foreach ($backupMap as $relative => $entry) {
            $live = $liveMap[$relative] ?? null;
            if (
                !is_array($live)
                || (int) $live['size'] !== (int) $entry['size']
                || !hash_equals((string) $entry['sha256'], (string) $live['sha256'])
            ) {
                throw new RuntimeException('Пофайловый rollback не прошёл итоговую проверку: ' . $relative);
            }
        }
    }

    /** @return array{backup_dir:string,entries:list<array<string,mixed>>} */
    private function loadVerifiedCodeBackup(string $backupDir, string $transactionId): array
    {
        $input = $backupDir;
        $real = realpath($backupDir);
        if (!is_string($real) || !is_dir($real) || is_link($input)) {
            throw new RuntimeException('Каталог rollback backup отсутствует или небезопасен');
        }

        $real = UpdatePath::normalize($real);
        if (UpdatePath::inside($real, $this->appRoot)) {
            throw new RuntimeException('Rollback backup должен находиться вне live-tree приложения');
        }

        $backupJson = $real . DIRECTORY_SEPARATOR . 'backup.json';
        $backupBytes = is_file($backupJson) && !is_link($backupJson)
            ? file_get_contents($backupJson)
            : false;

        try {
            $backup = is_string($backupBytes)
                ? json_decode($backupBytes, true, 64, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Manifest rollback backup содержит некорректный JSON', 0, $e);
        }

        if (
            !is_array($backup)
            || array_is_list($backup)
            || ($backup['schema'] ?? null) !== 1
            || !is_array($backup['code'] ?? null)
        ) {
            throw new RuntimeException('Manifest rollback backup неполон');
        }
        if (!hash_equals($transactionId, (string) ($backup['transaction_id'] ?? ''))) {
            throw new RuntimeException('Rollback backup принадлежит другой транзакции');
        }
        if (!hash_equals($this->appRoot, UpdatePath::normalize((string) ($backup['application_root'] ?? '')))) {
            throw new RuntimeException('Rollback backup принадлежит другому live-root');
        }

        $manifestName = (string) ($backup['code']['manifest'] ?? '');
        if (!UpdatePath::safeRelative($manifestName)) {
            throw new RuntimeException('Путь manifest снимка кода в rollback backup некорректен');
        }

        $manifestPath = $real . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $manifestName);
        $manifestBytes = is_file($manifestPath) && !is_link($manifestPath)
            ? file_get_contents($manifestPath)
            : false;
        $manifestHash = is_file($manifestPath) && !is_link($manifestPath)
            ? hash_file('sha256', $manifestPath)
            : false;

        try {
            $manifest = is_string($manifestBytes)
                ? json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR)
                : null;
        } catch (JsonException $e) {
            throw new RuntimeException('Manifest снимка кода содержит некорректный JSON', 0, $e);
        }

        if (
            !is_array($manifest)
            || array_is_list($manifest)
            || ($manifest['schema'] ?? null) !== 1
            || !is_array($manifest['entries'] ?? null)
            || !is_string($manifestHash)
            || !hash_equals((string) ($backup['code']['manifest_sha256'] ?? ''), $manifestHash)
        ) {
            throw new RuntimeException('Manifest снимка кода rollback backup не прошёл проверку');
        }

        /** @var list<array<string,mixed>> $entries */
        $entries = array_values($manifest['entries']);
        if ($entries === []) {
            throw new RuntimeException('Снимок кода rollback backup пуст');
        }

        $totalBytes = 0;
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Запись снимка кода rollback backup повреждена');
            }

            $relative = (string) ($entry['path'] ?? '');
            if (!UpdatePath::safeRelative($relative)) {
                throw new RuntimeException('Снимок кода rollback backup содержит небезопасный путь');
            }

            $path = $real
                . DIRECTORY_SEPARATOR
                . 'code'
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            $hash = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;

            if (
                !is_int($size)
                || $size !== (int) ($entry['size'] ?? -1)
                || !is_string($hash)
                || !hash_equals((string) ($entry['sha256'] ?? ''), $hash)
            ) {
                throw new RuntimeException('Снимок rollback backup не прошёл SHA-256: ' . $relative);
            }

            $totalBytes += $size;
        }

        if (
            count($entries) !== (int) ($manifest['files'] ?? -1)
            || $totalBytes !== (int) ($manifest['bytes'] ?? -1)
            || count($entries) !== (int) ($backup['code']['files'] ?? -1)
            || $totalBytes !== (int) ($backup['code']['bytes'] ?? -1)
        ) {
            throw new RuntimeException('Агрегаты снимка кода rollback backup не совпадают');
        }

        return ['backup_dir' => $real, 'entries' => $entries];
    }

    private function deepestFirst(string $left, string $right): int
    {
        $depth = substr_count($right, '/') <=> substr_count($left, '/');
        return $depth !== 0 ? $depth : strcmp($left, $right);
    }

    private function shallowestFirst(string $left, string $right): int
    {
        $depth = substr_count($left, '/') <=> substr_count($right, '/');
        return $depth !== 0 ? $depth : strcmp($left, $right);
    }

    private function validateTransactionId(string $transactionId): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', trim($transactionId)) !== 1) {
            throw new RuntimeException('Некорректный идентификатор updater-транзакции для rollback');
        }
    }
}
