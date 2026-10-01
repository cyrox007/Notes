<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Определяет миграции, уже входившие в опубликованную стабильную версию.
 *
 * Это нужно для установок, созданных до появления schema_migrations:
 * их схема уже содержит соответствующие изменения, но отдельного журнала
 * применённых миграций ещё нет.
 */
final class MigrationBaseline
{
    /** @var array<int,string> */
    private const LAST_MIGRATION_BY_VERSION = [
        10012 => '20260930_file_upload_limit.sql',
        10013 => '20260930_module_entitlements.sql',
    ];

    public static function activeUpdaterSourceVersionCode(
        string $appRoot,
        int $targetVersionCode
    ): ?int {
        $stateRoot = trim((string) (getenv('UPDATE_STATE_PATH') ?: ''));
        if ($stateRoot === '') {
            $privateRoot = trim((string) (getenv('PRIVATE_STORAGE_PATH') ?: ''));
            if ($privateRoot === '') {
                return null;
            }
            $stateRoot = rtrim($privateRoot, '/\\') . DIRECTORY_SEPARATOR . 'updates';
        }

        $markerPath = rtrim($stateRoot, '/\\') . DIRECTORY_SEPARATOR . 'workspace-maintenance.json';
        $marker = self::readJsonObject($markerPath, 16384);
        if ($marker === null
            || ($marker['schema'] ?? null) !== 1
            || ($marker['mode'] ?? null) !== 'update') {
            return null;
        }

        $transactionId = trim((string) ($marker['transaction_id'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{7,95}$/D', $transactionId) !== 1) {
            return null;
        }

        $journalPath = rtrim($stateRoot, '/\\')
            . DIRECTORY_SEPARATOR . 'transactions'
            . DIRECTORY_SEPARATOR . $transactionId . '.json';
        $journal = self::readJsonObject($journalPath, 262144);
        if ($journal === null
            || ($journal['schema'] ?? null) !== 1
            || !hash_equals($transactionId, (string) ($journal['transaction_id'] ?? ''))
            || ($journal['live_mutation_started'] ?? false) !== true
            || (int) ($journal['target_version_code'] ?? 0) !== $targetVersionCode) {
            return null;
        }

        $state = (string) ($journal['state'] ?? '');
        if (!in_array($state, [
            'live_mutation_started',
            'code_switched',
            'migrations_applied',
            'postcheck_verified',
        ], true)) {
            return null;
        }

        $sourceVersionCode = (int) ($journal['installed_version_code'] ?? 0);
        if ($sourceVersionCode < 1
            || !array_key_exists($sourceVersionCode, self::LAST_MIGRATION_BY_VERSION)) {
            return null;
        }

        return $sourceVersionCode;
    }

    /** @return array<string,mixed>|null */
    private static function readJsonObject(string $path, int $maxBytes): ?array
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }

        $size = filesize($path);
        if (!is_int($size) || $size < 2 || $size > $maxBytes) {
            return null;
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            return null;
        }

        try {
            $value = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($value) && !array_is_list($value) ? $value : null;
    }

    /**
     * @param list<string> $canonical
     * @return list<string>
     */
    public static function appliedNames(array $canonical, int $versionCode): array
    {
        $lastMigration = self::LAST_MIGRATION_BY_VERSION[$versionCode] ?? null;
        if (!is_string($lastMigration)) {
            return [];
        }

        $index = array_search($lastMigration, $canonical, true);
        if ($index === false) {
            throw new RuntimeException(
                'Опубликованный baseline версии ' . $versionCode
                . ' отсутствует в каноническом manifest миграций'
            );
        }

        return array_slice($canonical, 0, (int) $index + 1);
    }
}
