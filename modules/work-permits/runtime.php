<?php

declare(strict_types=1);

foreach ([
    'services/WorkPermitReferenceService.php',
    'services/WorkPermitCrewService.php',
    'services/WorkPermitTemplateService.php',
    'services/WorkPermitDraftEditService.php',
    'services/WorkPermitChangeService.php',
    'services/WorkPermitDailyService.php',
    'services/WorkPermitIssuanceService.php',
    'services/WorkPermitDraftService.php',
    'controllers/WorkPermitController.php',
    'WorkPermitsCapability.php',
    'WorkPermitsRuntimeProvider.php',
] as $file) {
    $path = __DIR__ . '/' . $file;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Файл модуля нарядов отсутствует или небезопасен: ' . $file);
    }
    require_once $path;
}

return new \Modules\WorkPermits\WorkPermitsRuntimeProvider();
