<?php
declare(strict_types=1);

$moduleRoot = __DIR__;
foreach ([
    '/FilesCapability.php',
    '/FilesCalendarLinkProvider.php',
    '/models/FileModel.php',
    '/services/FileLifecycleService.php',
    '/services/FileShareService.php',
    '/middlewares/RequireFilesUse.php',
    '/middlewares/EnforceFileUploadPolicy.php',
    '/middlewares/EnforceFileFolderPolicy.php',
    '/controllers/FileController.php',
    '/controllers/FileDeleteController.php',
    '/controllers/FileQuotaController.php',
    '/controllers/FileShareController.php',
    '/FilesRuntimeProvider.php',
] as $relativePath) {
    $path = $moduleRoot . $relativePath;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Files module runtime file is missing or unsafe: ' . $relativePath);
    }
    require_once $path;
}

return new \Modules\Files\FilesRuntimeProvider();
