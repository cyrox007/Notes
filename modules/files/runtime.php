<?php
declare(strict_types=1);

$moduleRoot = __DIR__;
foreach ([
    '/FilesCapability.php',
    '/models/FileModel.php',
    '/services/FileLifecycleService.php',
    '/middlewares/RequireFilesUse.php',
    '/middlewares/EnforceFileUploadPolicy.php',
    '/middlewares/EnforceFileFolderPolicy.php',
    '/controllers/FileController.php',
    '/controllers/FileDeleteController.php',
    '/controllers/FileQuotaController.php',
    '/FilesRuntimeProvider.php',
] as $relativePath) {
    $path = $moduleRoot . $relativePath;
    if (!is_file($path)) {
        throw new RuntimeException('Files module runtime file is missing: ' . $relativePath);
    }
    require_once $path;
}

return new \Modules\Files\FilesRuntimeProvider();
