<?php

declare(strict_types=1);

$root = __DIR__;
foreach ([
    'services/DefectPriorityPolicy.php',
    'services/DefectReferenceService.php',
    'services/DefectRegistrationService.php',
    'services/DefectRegistryService.php',
    'controllers/DefectsPageController.php',
    'controllers/DefectsApiController.php',
    'DefectsCapability.php',
    'DefectsRuntimeProvider.php',
] as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Не найден или небезопасен файл модуля Дефекты: ' . $file);
    }
    require_once $path;
}

return new \Modules\Defects\DefectsRuntimeProvider();
