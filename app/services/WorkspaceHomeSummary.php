<?php

declare(strict_types=1);

namespace App\Services;

use Core\ModuleRuntimeLoader;
use Core\ProfileContentProvider;
use Throwable;

/**
 * Краткая личная сводка главной страницы через публичные контракты модулей.
 * Не читает таблицы отключённых модулей и не смешивает чужие данные.
 */
final class WorkspaceHomeSummary
{
    /** @return array<string,int> */
    public function forUser(int $userId): array
    {
        if ($userId < 1 || !ModuleRuntimeLoader::isBooted()) {
            return [];
        }

        $registry = ModuleRuntimeLoader::getInstance()->capabilities();
        $result = [];
        foreach (['notes', 'tasks', 'files'] as $module) {
            $name = 'workspace.' . $module;
            if (!$registry->has($name)) {
                continue;
            }

            try {
                $provider = $registry->require($name, ProfileContentProvider::class);
                $metrics = $provider->profileMetrics($userId);
                foreach (['notes_count', 'tasks_count', 'open_tasks_count', 'files_count'] as $key) {
                    if (isset($metrics[$key]) && is_numeric($metrics[$key])) {
                        $result[$key] = max(0, (int) $metrics[$key]);
                    }
                }
            } catch (Throwable $error) {
                // Отказ одного модуля не должен ломать всю главную страницу.
                error_log('Главная: не удалось получить сводку ' . $module . ': ' . $error::class);
            }
        }

        return $result;
    }
}
