<?php

declare(strict_types=1);

namespace Core;

use App\Services\MaintenanceModeService;

/**
 * Операции, специфичные для доверенного bootstrap-обновлятора.
 *
 * Здесь нет загрузки runner-версии приложения: источник версии всегда
 * привязывается к проверенному live-каталогу отдельным методом.
 */
final class UpdateBootstrapSupport
{
    public function database(): \mysqli
    {
        if (!extension_loaded('mysqli')) {
            throw new \RuntimeException('Для bootstrap-обновления требуется расширение PHP mysqli');
        }

        $user = getenv('DBUSER');
        $name = getenv('DBNAME');
        if (!is_string($user) || trim($user) === '' || !is_string($name) || trim($name) === '') {
            throw new \RuntimeException('Параметры подключения к базе данных заполнены не полностью');
        }

        \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $db = new \mysqli(
            (string) (getenv('DBHOST') ?: 'localhost'),
            trim($user),
            (string) (getenv('DBPASS') ?: ''),
            trim($name),
            (int) (getenv('DBPORT') ?: 3306)
        );
        $db->set_charset('utf8mb4');

        return $db;
    }

    /** @return list<string> */
    public function mutablePaths(): array
    {
        $paths = [];
        foreach ([
            'PRIVATE_STORAGE_PATH',
            'UPLOAD_DIR',
            'NOTES_UPLOAD_DIR',
            'MESSENGER_UPLOAD_DIR',
            'RATE_LIMIT_STORAGE_PATH',
            'UPDATE_STAGING_PATH',
            'UPDATE_STATE_PATH',
            'UPDATE_BACKUP_PATH',
            'UPDATE_RELEASE_PATH',
            'WS_PID_FILE',
            'LOG_FILE',
        ] as $name) {
            $value = getenv($name);
            if (!is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);
            if (in_array($name, ['WS_PID_FILE', 'LOG_FILE'], true)) {
                $value = dirname($value);
            }
            $paths[] = $value;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{version:string,version_code:int}
     */
    public function bindSourceVersion(
        string $appRoot,
        string $expectedVersion,
        int $expectedVersionCode,
        UpdateExternalCliSupport $cli
    ): array {
        if (class_exists(Version::class, false)) {
            $cli->fail(
                'Bootstrap-runner загрузил Core\\Version до привязки версии live-установки',
                'unsafe_version_binding',
                2
            );
        }

        $liveVersionFile = $appRoot . '/core/Version.php';
        if (!is_file($liveVersionFile) || is_link($liveVersionFile)) {
            $cli->fail(
                'В live-установке отсутствует безопасный core/Version.php',
                'invalid_source_version',
                2
            );
        }

        require_once $liveVersionFile;
        if (!class_exists(Version::class, false)) {
            $cli->fail(
                'Live core/Version.php не определил Core\\Version',
                'invalid_source_version',
                2
            );
        }

        $version = (string) Version::VERSION;
        $versionCode = (int) Version::VERSION_CODE;
        if (!hash_equals($expectedVersion, $version) || $versionCode !== $expectedVersionCode) {
            $cli->fail(
                sprintf(
                    'Версия live-установки %s (%d) не совпадает с явно разрешённой исходной версией bootstrap',
                    $version,
                    $versionCode
                ),
                'source_version_mismatch',
                2,
                [
                    'actual_source_version' => $version,
                    'actual_source_version_code' => $versionCode,
                ]
            );
        }

        return ['version' => $version, 'version_code' => $versionCode];
    }

    /** @param array<string,mixed> $result */
    public function printResult(UpdateExternalCliSupport $cli, array $result): void
    {
        if ($cli->jsonMode()) {
            $cli->writeJson($result);
            return;
        }

        echo '[OK] ' . (string) ($result['status'] ?? 'bootstrap_completed') . PHP_EOL;
        echo 'Транзакция: ' . (string) ($result['transaction_id'] ?? '') . PHP_EOL;
        if (isset($result['source_version'], $result['target_version'])) {
            echo 'Обновление:   ' . $result['source_version'] . ' -> ' . $result['target_version'] . PHP_EOL;
        }
        echo 'Режим обслуживания: '
            . (!empty($result['maintenance_active']) ? 'активен' : 'снят')
            . PHP_EOL;
    }

    public function releasePreLiveMaintenance(
        ?MaintenanceModeService $maintenance,
        ?UpdateTransactionJournal $journal,
        string $transactionId,
        bool $maintenanceOwned
    ): bool {
        if (!$maintenanceOwned || !$maintenance instanceof MaintenanceModeService) {
            return $maintenanceOwned;
        }

        try {
            $state = $maintenance->state();
            if (!$state['active']) {
                return false;
            }
            if (!$state['valid'] || !hash_equals($transactionId, (string) $state['transaction_id'])) {
                return true;
            }

            if ($journal instanceof UpdateTransactionJournal) {
                $journalState = $journal->load($transactionId);
                if (($journalState['live_mutation_started'] ?? true) !== false) {
                    return true;
                }
            }

            $maintenance->leave($transactionId);
            return false;
        } catch (\Throwable) {
            // При сомнительном состоянии ничего не снимаем: журнал и marker
            // остаются для явного восстановления оператором.
            return $maintenanceOwned;
        }
    }
}
