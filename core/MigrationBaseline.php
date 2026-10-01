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
