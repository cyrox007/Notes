<?php
declare(strict_types=1);

namespace Modules\Files;

use App\Controllers\FileController;
use App\Controllers\FileDeleteController;
use App\Controllers\FileQuotaController;
use App\Controllers\FileShareController;
use App\Controllers\FileSpaceController;
use App\Controllers\FileSpaceAdminController;
use App\Controllers\FileSpaceTransferController;
use App\Controllers\FileSpaceObjectController;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\RequireAdminSettingsManage;
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
    private FilesCalendarLinkProvider $calendarLinks;

    public function __construct()
    {
        $this->capability = new FilesCapability();
        $this->calendarLinks = new FilesCalendarLinkProvider();
    }

    public function moduleId(): string
    {
        return 'files';
    }

    public function boot(): void
    {
        // Классы модуля загружаются явно через runtime.php.
    }

    /** @return array<string,object> */
    public function capabilities(): array
    {
        return [
            'workspace.files' => $this->capability,
            'workspace.files.calendar-links' => $this->calendarLinks,
        ];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/files')
            ->add('GET', '/', [FileController::class, 'index'], [LoginRequared::class, RequireFilesUse::class], 'files')
            ->add('GET', '/spaces/global/', [FileSpaceController::class, 'index'], [LoginRequared::class, RequireFilesUse::class], 'files_global')
            ->add('POST', '/spaces/global/folders/', [FileSpaceController::class, 'createFolder'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_global_create_folder')
            ->add('POST', '/spaces/global/acl/', [FileSpaceController::class, 'setAcl'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_global_acl')
            ->add('POST', '/spaces/global/upload/', [FileSpaceTransferController::class, 'upload'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class, UploadRateLimit::class, EnforceFileUploadPolicy::class], 'files_global_upload')
            ->add('GET', '/spaces/global/download/{int:fileId}/', [FileSpaceTransferController::class, 'download'], [LoginRequared::class, RequireFilesUse::class], 'files_global_download')
            ->add('POST', '/spaces/global/node/{int:nodeId}/', [FileSpaceObjectController::class, 'mutate'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_global_object_mutate')
            ->add('POST', '/spaces/global/node/{int:fileId}/replace/', [FileSpaceObjectController::class, 'replace'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class, UploadRateLimit::class, EnforceFileUploadPolicy::class], 'files_global_object_replace')
            ->add('GET', '/quota/', [FileQuotaController::class, 'usage'], [LoginRequared::class, RequireFilesUse::class], 'files_quota')
            ->add('GET', '/folder/{int:folderId}/', [FileController::class, 'folder'], [LoginRequared::class, RequireFilesUse::class], 'files_folder')
            ->add('POST', '/create-folder/', [FileController::class, 'createFolder'], [LoginRequared::class, RequireFilesUse::class, EnforceFileFolderPolicy::class, StorageMutationLock::class], 'files_create_folder')
            ->add('POST', '/upload/', [FileController::class, 'uploadFile'], [LoginRequared::class, RequireFilesUse::class, UploadRateLimit::class, EnforceFileUploadPolicy::class, StorageQuotaLimit::class], 'files_upload')
            ->add('POST', '/delete/', [FileDeleteController::class, 'delete'], [LoginRequared::class, RequireFilesUse::class], 'files_delete')
            ->add('POST', '/rename/', [FileController::class, 'rename'], [LoginRequared::class, RequireFilesUse::class, StorageMutationLock::class], 'files_rename')
            ->add('GET', '/published/{str:uid}/', [FileController::class, 'publishedFile'], [LoginRequared::class, RequireFilesUse::class], 'files_published')
            ->add('GET', '/get/{int:fileId}/', [FileController::class, 'getFile'], [LoginRequared::class, RequireFilesUse::class], 'files_get')
            ->add('GET', '/shares/', [FileShareController::class, 'index'], [LoginRequared::class, RequireFilesUse::class], 'files_shares')
            ->add('POST', '/share/{str:uid}', [FileShareController::class, 'create'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_share')
            ->add('POST', '/unshare/{str:uid}', [FileShareController::class, 'revoke'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_unshare')
            ->add('POST', '/shares/{int:shareId}/revoke', [FileShareController::class, 'revokeShare'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_share_revoke')
            ->add('POST', '/shares/{int:shareId}/expiry', [FileShareController::class, 'updateExpiry'], [LoginRequared::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_share_expiry')
            ->add('GET', '/shared/{str:token}', [FileShareController::class, 'open'], [], 'files_shared')
            ->add('GET', '/shared/{str:token}/folder/{str:uid}', [FileShareController::class, 'folder'], [], 'files_shared_folder')
            ->add('GET', '/shared/{str:token}/file/{str:uid}', [FileShareController::class, 'sharedFile'], [], 'files_shared_file')
            ->endGroup();
        $router->group('/admin/files')
            ->add('GET', '/', [FileSpaceAdminController::class, 'index'],
                [LoginRequared::class, RequireAdminSettingsManage::class, RequireFilesUse::class], 'files_admin')
            ->add('POST', '/groups/create', [FileSpaceAdminController::class, 'createGroup'],
                [LoginRequared::class, RequireAdminSettingsManage::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_admin_group_create')
            ->add('POST', '/groups/member', [FileSpaceAdminController::class, 'groupMember'],
                [LoginRequared::class, RequireAdminSettingsManage::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_admin_group_member')
            ->add('POST', '/groups/delete', [FileSpaceAdminController::class, 'deleteGroup'],
                [LoginRequared::class, RequireAdminSettingsManage::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_admin_group_delete')
            ->add('POST', '/acl', [FileSpaceAdminController::class, 'setAcl'],
                [LoginRequared::class, RequireAdminSettingsManage::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_admin_acl')
            ->add('POST', '/retention', [FileSpaceAdminController::class, 'retention'],
                [LoginRequared::class, RequireAdminSettingsManage::class, RequireFilesUse::class, CSRFMiddleware::class], 'files_admin_retention')
            ->endGroup();
    }
}
