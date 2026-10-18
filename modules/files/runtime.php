<?php
declare(strict_types=1);

$moduleRoot = __DIR__;
foreach ([
    '/FilesCapability.php',
    '/FilesCalendarLinkProvider.php',
    '/models/FileModel.php',
    '/services/FileLifecycleService.php',
    '/services/FileShareService.php',
    '/services/FileUploadTypes.php',
    '/services/FileSpaceAccessService.php',
    '/services/FileSpaceTransferService.php',
    '/services/FileSpaceFolderService.php',
    '/middlewares/RequireFilesUse.php',
    '/middlewares/EnforceFileUploadPolicy.php',
    '/middlewares/EnforceFileFolderPolicy.php',
    '/controllers/FileController.php',
    '/controllers/FileDeleteController.php',
    '/controllers/FileQuotaController.php',
    '/controllers/FileShareController.php',
    '/controllers/FileSpaceController.php',
    '/controllers/FileSpaceTransferController.php',
    '/FilesRuntimeProvider.php',
] as $relativePath) {
    $path = $moduleRoot . $relativePath;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Files module runtime file is missing or unsafe: ' . $relativePath);
    }
    require_once $path;
}

return new \Modules\Files\FilesRuntimeProvider();
