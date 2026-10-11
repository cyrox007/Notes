<?php

declare(strict_types=1);

namespace Modules\Calendar;

use Core\ModuleRuntimeProvider;
use Core\Router;
use Throwable;

final class CalendarRuntimeProvider implements ModuleRuntimeProvider
{
    private CalendarCapability $capability;

    public function __construct(?CalendarCapability $capability = null)
    {
        $this->capability = $capability ?? new CalendarCapability();
    }

    public function moduleId(): string
    {
        return 'calendar';
    }

    public function boot(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        try {
            $this->capability->runHttpFallback();
        } catch (Throwable $e) {
            error_log('[Calendar] Резервная обработка напоминаний пропущена: ' . $e::class);
        }
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return [
            'workspace.calendar' => $this->capability,
        ];
    }

    public function registerRoutes(Router $router): void
    {
        // Маршруты UI Calendar будут добавлены отдельным продуктовым этапом.
    }
}
