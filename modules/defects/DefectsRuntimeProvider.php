<?php

declare(strict_types=1);

namespace Modules\Defects;

use App\Middlewares\ApiLoginRequired;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\LoginRequared;
use Core\ModuleRuntimeProvider;
use Core\Router;
use Modules\Defects\Controllers\DefectsApiController;
use Modules\Defects\Controllers\DefectsPageController;

final class DefectsRuntimeProvider implements ModuleRuntimeProvider
{
    public function moduleId(): string
    {
        return 'defects';
    }

    public function boot(): void
    {
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.defects' => new DefectsCapability()];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/defects')
            ->add('GET', '/', [DefectsPageController::class, 'index'], [LoginRequared::class], 'defects')
            ->endGroup();

        $router->group('/api/defects')
            ->add('GET', '/', [DefectsApiController::class, 'list'], [ApiLoginRequired::class], 'defects_list')
            ->add('POST', '/', [DefectsApiController::class, 'create'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_create')
            ->add('POST', '/import/preview', [DefectsApiController::class, 'previewImport'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_import_preview')
            ->add('POST', '/import/apply', [DefectsApiController::class, 'applyImport'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_import_apply')
            ->add('GET', '/report', [DefectsApiController::class, 'report'], [ApiLoginRequired::class], 'defects_report')
            ->add('GET', '/references', [DefectsApiController::class, 'references'], [ApiLoginRequired::class], 'defects_references')
            ->add('POST', '/references', [DefectsApiController::class, 'createReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_reference_create')
            ->add('GET', '/{int:id}', [DefectsApiController::class, 'show'], [ApiLoginRequired::class], 'defects_show')
            ->add('POST', '/{int:id}/close', [DefectsApiController::class, 'close'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_close')
            ->endGroup();
    }
}
