<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Оркестратор фоновой очистки. Загружает только объявленные модулями
 * фоновые обработчики и сохраняет ответственность за SQL у владельцев.
 */
final class BackgroundRetentionCleanup
{
    /** @var array<string,ModuleRetentionCleanup>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly string $modulesRoot
    ) {
    }

    /** Проверить готовность всех модульных обработчиков без выполнения удаления. */
    public function verify(): void
    {
        $this->providers();
    }

    /** @return array<string,int> */
    public function preview(string $cutoff, int $limit, RetentionCleanupContext $context): array
    {
        $result = [];
        foreach ($this->providers() as $provider) {
            foreach ($provider->preview($cutoff, $limit, $context) as $key => $count) {
                if (isset($result[$key])) {
                    throw new RuntimeException('Два модуля объявили одинаковый результат очистки: ' . $key);
                }
                $result[$key] = $count;
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $result */
    public function apply(string $cutoff, int $limit, RetentionCleanupContext $context, array &$result): void
    {
        // Сначала проверяем присутствие всех пакетов: нельзя частично удалять
        // данные, когда один из установленных обработчиков недоступен.
        $providers = $this->providers();
        foreach ($providers as $provider) {
            $provider->apply($cutoff, $limit, $context, $result);
        }
    }

    /**
     * Выполняется внутри транзакции платформенного обезличивания.
     * Только модуль-владелец определяет очистку своих вспомогательных данных.
     */
    public function purgeAccountData(int $userId, RetentionCleanupContext $context): void
    {
        foreach ($this->providers() as $provider) {
            if ($provider instanceof ModuleAccountDataCleanup) {
                $provider->purgeAccountData($userId, $context);
            }
        }
    }

    /** @return array<string,ModuleRetentionCleanup> */
    private function providers(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $registry = ModuleRegistry::discover($this->modulesRoot, Version::VERSION);
        $manifests = $registry->all();

        // Отсутствие ранее установленного модуля нельзя принимать за
        // отсутствие его данных или разрешение на физическое удаление.
        $known = $this->db->fetchAll(
            "SELECT module_id FROM module_lifecycle WHERE configured_state NOT IN ('discovered')"
        );
        foreach ($known as $row) {
            $moduleId = (string) $row['module_id'];
            if (!isset($manifests[$moduleId])) {
                throw new RuntimeException('Отсутствует ранее установленный модуль: ' . $moduleId);
            }
        }

        $providers = [];
        foreach ($manifests as $moduleId => $manifest) {
            $entrypoint = $manifest->backgroundRetentionCleanup();
            if ($entrypoint === 'none') {
                continue;
            }
            if ($entrypoint === null) {
                if ($manifest->databaseTables() !== []) {
                    throw new RuntimeException('Не объявлена фоновая очистка модуля: ' . $moduleId);
                }
                continue;
            }

            $root = realpath(dirname($manifest->manifestPath()));
            if ($root === false || is_link(dirname($manifest->manifestPath()))) {
                throw new RuntimeException('Небезопасный каталог модуля: ' . $moduleId);
            }
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entrypoint);
            $resolved = realpath($path);
            if (
                $resolved === false
                || !is_file($resolved)
                || is_link($path)
                || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('Недоступен фоновый обработчик: ' . $moduleId);
            }

            $database = $this->db;
            $provider = require $resolved;
            if (!$provider instanceof ModuleRetentionCleanup || $provider->moduleId() !== $moduleId) {
                throw new RuntimeException('Неверный фоновый обработчик: ' . $moduleId);
            }
            $providers[$moduleId] = $provider;
        }

        return $this->providers = $providers;
    }
}
