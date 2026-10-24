<?php

declare(strict_types=1);

namespace Modules\WorkPermits;

use App\Middlewares\ApiLoginRequired;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\LoginRequared;
use Core\ModuleRuntimeProvider;
use Core\Router;
use Modules\WorkPermits\Controllers\WorkPermitController;

final class WorkPermitsRuntimeProvider implements ModuleRuntimeProvider
{
    public function moduleId(): string
    {
        return 'work-permits';
    }

    public function boot(): void
    {
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.work-permits' => new WorkPermitsCapability()];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/work-permits')
            ->add('GET', '/', [WorkPermitController::class, 'index'], [LoginRequared::class], 'work_permits')
            ->endGroup();

        $router->group('/api/work-permits')
            ->add('GET', '/', [WorkPermitController::class, 'list'], [ApiLoginRequired::class], 'work_permits_list')
            ->add('GET', '/{int:id}', [WorkPermitController::class, 'show'], [ApiLoginRequired::class], 'work_permits_show')
            ->add('POST', '/', [WorkPermitController::class, 'create'], [ApiLoginRequired::class, CSRFMiddleware::class], 'work_permits_create')
            ->endGroup();
    }
}
