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
            ->add('POST', '/{int:id}/edit', [WorkPermitController::class, 'edit'], [ApiLoginRequired::class, CSRFMiddleware::class], 'work_permits_edit')
            ->add('GET', '/templates', [WorkPermitController::class, 'templates'], [ApiLoginRequired::class], 'work_permits_templates')
            ->add('GET', '/templates/{int:id}', [WorkPermitController::class, 'template'], [ApiLoginRequired::class], 'work_permits_template')
            ->add('POST', '/templates', [WorkPermitController::class, 'createTemplate'], [ApiLoginRequired::class, CSRFMiddleware::class], 'work_permits_template_create')
            ->add('POST', '/templates/{int:id}/change', [WorkPermitController::class, 'changeTemplate'], [ApiLoginRequired::class, CSRFMiddleware::class], 'work_permits_template_change')
            ->add('GET', '/references/{str:kind}', [WorkPermitController::class, 'references'], [ApiLoginRequired::class], 'work_permits_references')
            ->add('POST', '/references/{str:kind}', [WorkPermitController::class, 'createReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'work_permits_reference_create')
            ->add('POST', '/references/{str:kind}/{int:id}/change', [WorkPermitController::class, 'changeReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'work_permits_reference_change')
            ->endGroup();
    }
}
