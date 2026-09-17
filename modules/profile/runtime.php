<?php
declare(strict_types=1);

$moduleRoot = __DIR__;
foreach ([
    '/ProfileCapability.php',
    '/services/ProfileMetricsService.php',
    '/services/ProfilePublicationService.php',
    '/services/UserAvatarService.php',
    '/middlewares/RequireProfileUse.php',
    '/controllers/ProfileController.php',
    '/controllers/PublicProfileController.php',
    '/ProfileRuntimeProvider.php',
] as $relativePath) {
    $path = $moduleRoot . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException('Profile module runtime file is missing: ' . $relativePath);
    }
    require_once $path;
}

return new \Modules\Profile\ProfileRuntimeProvider();
