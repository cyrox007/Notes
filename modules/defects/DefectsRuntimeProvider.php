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
            ->add('GET', '/references/manage', [DefectsApiController::class, 'manageReferences'], [ApiLoginRequired::class], 'defects_references_manage')
            ->add('POST', '/references/{str:kind}/{int:id}/rename', [DefectsApiController::class, 'renameReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_reference_rename')
            ->add('POST', '/references/{str:kind}/{int:id}/archive', [DefectsApiController::class, 'archiveReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_reference_archive')
            ->add('POST', '/references/{str:kind}/{int:id}/restore', [DefectsApiController::class, 'restoreReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_reference_restore')
            ->add('POST', '/references', [DefectsApiController::class, 'createReference'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_reference_create')
            ->add('GET', '/references/{str:kind}/{int:id}/history', [DefectsApiController::class, 'referenceHistory'], [ApiLoginRequired::class], 'defects_reference_history')
            ->add('GET', '/{int:id}/history', [DefectsApiController::class, 'history'], [ApiLoginRequired::class], 'defects_history')
            ->add('GET', '/{int:id}', [DefectsApiController::class, 'show'], [ApiLoginRequired::class], 'defects_show')
            ->add('POST', '/{int:id}/close', [DefectsApiController::class, 'close'], [ApiLoginRequired::class, CSRFMiddleware::class], 'defects_close')
            ->endGroup();
    }
}
