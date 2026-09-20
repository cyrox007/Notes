<?php
declare(strict_types=1);

namespace Modules\Files;

use App\Controllers\FileController;
use App\Controllers\FileDeleteController;
use App\Controllers\FileQuotaController;
use App\Middlewares\EnforceFileFolderPolicy;
use App\Middlewares\EnforceFileUploadPolicy;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireFilesUse;
use App\Middlewares\StorageMutationLock;
use App\Middlewares\StorageQuotaLimit;
use App\Middlewares\UploadRateLimit;
use Core\ModuleRuntimeProvider;
use Core\Router;

final class FilesRuntimeProvider implements ModuleRuntimeProvider
{
    private FilesCapability $capability;

    public function __construct()
    {
        $this->capability = new FilesCapability();
    }

    public function moduleId(): string
    {
        return 'files';
    }

    public function boot(): void
    {
        // Module-owned classes are loaded explicitly by runtime.php.
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return ['workspace.files' => $this->capability];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/files')
            ->add('GET', '/', [FileController::class, 'index'], [LoginRequared::class, RequireFilesUse::class], 'files')
            ->add('GET', '/quota/', [FileQuotaController::class, 'usage'], [LoginRequared::class, RequireFilesUse::class], 'files_quota')
            ->add('GET', '/folder/{int:folderId}/', [FileController::class, 'folder'], [LoginRequared::class, RequireFilesUse::class], 'files_folder')
            ->add('POST', '/create-folder/', [FileController::class, 'createFolder'], [LoginRequared::class, RequireFilesUse::class, EnforceFileFolderPolicy::class, StorageMutationLock::class], 'files_create_folder')
            ->add('POST', '/upload/', [FileController::class, 'uploadFile'], [LoginRequared::class, RequireFilesUse::class, UploadRateLimit::class, EnforceFileUploadPolicy::class, StorageQuotaLimit::class], 'files_upload')
            ->add('POST', '/delete/', [FileDeleteController::class, 'delete'], [LoginRequared::class, RequireFilesUse::class], 'files_delete')
            ->add('POST', '/rename/', [FileController::class, 'rename'], [LoginRequared::class, RequireFilesUse::class, StorageMutationLock::class], 'files_rename')
            ->add('GET', '/get/{int:fileId}/', [FileController::class, 'getFile'], [LoginRequared::class, RequireFilesUse::class], 'files_get')
            ->endGroup();
    }
}
