<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Запускает только объявленные модулем фоновые проверки. Не включает runtime,
 * не регистрирует маршруты и не зависит от активной лицензии модуля.
 *
 * Отсутствие, ошибка или устаревшая политика запрещает обезличивание.
 */
final class BackgroundAccountRetention
{
    /** @var array<string,BackgroundAccountRetentionGuard>|null */
    private ?array $guards = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $modulesRoot,
        private readonly ?ModuleRegistry $registry = null
    ) {
    }

    public function blockingReason(int $userId): ?string
    {
        if ($userId <= 0) {
            return 'account_guard_invalid_user';
        }

        try {
            $guards = $this->guards();
        } catch (Throwable $error) {
            return 'account_guard_registry_unavailable';
        }

        foreach ($guards as $moduleId => $guard) {
            try {
                $reason = $guard->blockingReason($userId);
            } catch (Throwable $error) {
                return 'account_guard_unavailable:' . $moduleId;
            }
            if ($reason !== null) {
                if ($reason === '' || preg_match('/^[a-z][a-z0-9_:-]{1,95}$/D', $reason) !== 1) {
                    return 'account_guard_invalid_response:' . $moduleId;
                }
                return $reason;
            }
        }
        return null;
    }

    /** @return array<string,BackgroundAccountRetentionGuard> */
    private function guards(): array
    {
        if ($this->guards !== null) {
            return $this->guards;
        }

        // Только проверка manifest. ModuleRuntimeLoader здесь не вызывается:
        // отключённые и лишённые лицензии пакеты сохраняют владение данными.
        $registry = $this->registry ?? ModuleRegistry::discover($this->modulesRoot, Version::VERSION);
        $guards = [];
        foreach ($registry->all() as $moduleId => $manifest) {
            $entrypoint = $manifest->backgroundAccountGuard();
            if ($entrypoint === 'none') {
                continue;
            }

            if ($entrypoint === null) {
                if ($manifest->databaseTables() !== []) {
                    throw new RuntimeException('Не объявлена фоновая проверка: ' . $moduleId);
                }
                continue;
            }

            $moduleRoot = realpath(dirname($manifest->manifestPath()));
            if ($moduleRoot === false || is_link(dirname($manifest->manifestPath()))) {
                throw new RuntimeException('Недоступен каталог модуля: ' . $moduleId);
            }
            $entry = $moduleRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entrypoint);
            $resolved = realpath($entry);
            if (
                $resolved === false
                || !is_file($resolved)
                || is_link($entry)
                || !str_starts_with($resolved, $moduleRoot . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('Отсутствует безопасная фоновая проверка: ' . $moduleId);
            }

            // Требуется конкретный интерфейс ядра. Сервис получает только
            // контролируемый DatabaseManager, не подключает обычный runtime.
            $database = $this->db;
            $guard = require $resolved;
            if (
                !$guard instanceof BackgroundAccountRetentionGuard
                || $guard->moduleId() !== $moduleId
            ) {
                throw new RuntimeException('Некорректный фоновый сервис: ' . $moduleId);
            }
            $guards[$moduleId] = $guard;
        }
        return $this->guards = $guards;
    }
}
