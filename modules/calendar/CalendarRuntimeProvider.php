<?php

declare(strict_types=1);

namespace Modules\Calendar;

use App\Middlewares\ApiLoginRequired;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\LoginRequared;
use Core\ModuleRuntimeProvider;
use Core\Router;
use Modules\Calendar\Controllers\CalendarApiController;
use Modules\Calendar\Controllers\CalendarController;
use Modules\Calendar\Controllers\CalendarPlanningApiController;
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
        return ['workspace.calendar' => $this->capability];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/calendar')
            ->add('GET', '/', [CalendarController::class, 'index'], [LoginRequared::class, RequireCalendarUse::class], 'calendar')
            ->endGroup();

        $router->group('/api/calendar/events')
            ->add('GET', '/', [CalendarApiController::class, 'index'], [ApiLoginRequired::class], 'calendar_events')
            ->add('POST', '/', [CalendarApiController::class, 'create'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_event_create')
            ->add('GET', '/{str:uid}', [CalendarApiController::class, 'show'], [ApiLoginRequired::class], 'calendar_event_show')
            ->add('PUT', '/{str:uid}', [CalendarApiController::class, 'update'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_event_update')
            ->add('DELETE', '/{str:uid}', [CalendarApiController::class, 'delete'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_event_delete')
            ->endGroup();

        $router->group('/api/calendar/planning')
            ->add('GET', '/agenda', [CalendarPlanningApiController::class, 'agenda'], [ApiLoginRequired::class], 'calendar_planning_agenda')
            ->add('GET', '/link-targets', [CalendarPlanningApiController::class, 'linkTargets'], [ApiLoginRequired::class], 'calendar_planning_link_targets')
            ->add('GET', '/links', [CalendarPlanningApiController::class, 'links'], [ApiLoginRequired::class], 'calendar_planning_links')
            ->add('PUT', '/links', [CalendarPlanningApiController::class, 'replaceLinks'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_planning_links_update')
            ->add('POST', '/time-blocks', [CalendarPlanningApiController::class, 'createTimeBlock'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_time_block_create')
            ->add('GET', '/time-blocks/{str:uid}', [CalendarPlanningApiController::class, 'showTimeBlock'], [ApiLoginRequired::class], 'calendar_time_block_show')
            ->add('PUT', '/time-blocks/{str:uid}', [CalendarPlanningApiController::class, 'updateTimeBlock'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_time_block_update')
            ->add('DELETE', '/time-blocks/{str:uid}', [CalendarPlanningApiController::class, 'deleteTimeBlock'], [ApiLoginRequired::class, CSRFMiddleware::class], 'calendar_time_block_delete')
            ->endGroup();
    }
}
