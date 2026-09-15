<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\NoteController;
use App\Controllers\NoteAttachmentController;
use App\Controllers\NoteShareController;
use App\Controllers\TaskController;
use App\Controllers\ProfileController;
use App\Controllers\PublicProfileController;
use App\Controllers\FileController;
use App\Controllers\FileDeleteController;
use App\Controllers\FileQuotaController;
use App\Controllers\Admin\AdminController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\RegistrationSettingsController;
use App\Controllers\Admin\UserProvisioningController;
use App\Controllers\Admin\RoleManagementController;
use App\Controllers\MessagerController;
use App\Controllers\MessengerGroupController;
use App\Controllers\MessengerVoiceController;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireAdminAccess;
use App\Middlewares\RequireAdminUsersManage;
use App\Middlewares\RequireAdminSettingsManage;
use App\Middlewares\RequireAdminRolesManage;
use App\Middlewares\AuthRateLimit;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\UploadRateLimit;
use App\Middlewares\StorageQuotaLimit;
use App\Middlewares\StorageMutationLock;
use App\Middlewares\EnforceFileUploadPolicy;
use App\Middlewares\EnforceFileFolderPolicy;
use Core\Router;

$router = Router::getInstance();

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth')
    ->add('GET', '/login', [AuthController::class, 'login'], [], 'authpage')
    ->add('POST', '/login', [AuthController::class, 'sigin'], [AuthRateLimit::class])
    ->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout')
    ->add('GET', '/registration', [AuthController::class, 'registration'], [], 'registration')
    ->add('GET', '/registration/{str:invite_code}', [AuthController::class, 'registration'], [], 'registration_invite')
    ->add('POST', '/registration', [AuthController::class, 'registration'], [AuthRateLimit::class, CSRFMiddleware::class], 'register_submit')
    ->endGroup();

$router->group('/notes')
    ->add('GET', '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes')
    ->add('POST', '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create')
    ->add('GET', '/{str:uid}/edit', [NoteController::class, 'edit'], [LoginRequared::class], 'edit_page')
    ->add('POST', '/{str:uid}/edit', [NoteController::class, 'update'], [LoginRequared::class], 'update_note')
    ->add('POST', '/{str:uid}/delete', [NoteController::class, 'delete'], [LoginRequared::class], 'delete_note')
    ->add('POST', '/upload/{str:uid}', [NoteAttachmentController::class, 'upload'], [LoginRequared::class, UploadRateLimit::class], 'note_attachment_upload')
    ->add('POST', '/attachment/delete/{int:attachmentId}', [NoteAttachmentController::class, 'delete'], [LoginRequared::class], 'note_attachment_delete')
    ->add('GET', '/attachment/{str:fileUid}', [NoteAttachmentController::class, 'download'], [LoginRequared::class], 'note_attachment_download')
    ->add('POST', '/share/{str:uid}', [NoteShareController::class, 'create'], [LoginRequared::class], 'note_share')
    ->add('POST', '/unshare/{str:uid}', [NoteShareController::class, 'unshare'], [LoginRequared::class], 'note_unshare')
    ->add('GET', '/shared/{str:token}', [NoteShareController::class, 'view'], [], 'note_shared_view')
    ->add('GET', '/shared/{str:token}/attachment/{str:fileUid}', [NoteAttachmentController::class, 'sharedDownload'], [], 'note_shared_attachment')
    ->endGroup();

$router->group('/tasks')
    ->add('GET', '/', [TaskController::class, 'index'], [LoginRequared::class], 'tasks')
    ->add('POST', '/', [TaskController::class, 'create'], [LoginRequared::class], 'task_create')
    ->add('POST', '/{str:uid}/update', [TaskController::class, 'update'], [LoginRequared::class], 'update_task')
    ->add('POST', '/{str:uid}/delete', [TaskController::class, 'delete'], [LoginRequared::class], 'delete_task')
    ->add('POST', '/{str:taskUid}/subtask', [TaskController::class, 'addSubtask'], [LoginRequared::class], 'add_subtask')
    ->add('POST', '/subtask/{int:subtaskId}/toggle', [TaskController::class, 'toggleSubtask'], [LoginRequared::class], 'toggle_subtask')
    ->add('POST', '/subtask/{int:subtaskId}/delete', [TaskController::class, 'deleteSubtask'], [LoginRequared::class], 'delete_subtask')
    ->add('POST', '/category', [TaskController::class, 'createCategory'], [LoginRequared::class], 'create_category')
    ->add('POST', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'attachCategory'], [LoginRequared::class], 'attach_category')
    ->add('DELETE', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'detachCategory'], [LoginRequared::class], 'detach_category')
    ->endGroup();

$router->group('/profile')
   ->add('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class], 'profile')
   ->add('GET', '/user/{str:uid}', [PublicProfileController::class, 'view'], [LoginRequared::class], 'profile-public')
   ->add('POST', '/publication', [ProfileController::class, 'setPublication'], [LoginRequared::class], 'profile-publication')
   ->add('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class], 'profile-set')
   ->add('GET', '/avatar/{str:uid}', [ProfileController::class, 'avatar'], [LoginRequared::class], 'profile-avatar')
   ->add('POST', '/avatar/delete', [ProfileController::class, 'removeAvatar'], [LoginRequared::class], 'profile-avatar-delete')
   ->add('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class], 'profile-password-set')
   ->add('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class], 'profile-delete')
   ->endGroup();

$router->group('/files')
    ->add('GET', '/', [FileController::class, 'index'], [LoginRequared::class], 'files')
    ->add('GET', '/quota/', [FileQuotaController::class, 'usage'], [LoginRequared::class], 'files_quota')
    ->add('GET', '/folder/{int:folderId}/', [FileController::class, 'folder'], [LoginRequared::class], 'files_folder')
    ->add('POST', '/create-folder/', [FileController::class, 'createFolder'], [LoginRequared::class, EnforceFileFolderPolicy::class, StorageMutationLock::class], 'files_create_folder')
    ->add('POST', '/upload/', [FileController::class, 'uploadFile'], [LoginRequared::class, UploadRateLimit::class, EnforceFileUploadPolicy::class, StorageQuotaLimit::class], 'files_upload')
    ->add('POST', '/delete/', [FileDeleteController::class, 'delete'], [LoginRequared::class], 'files_delete')
    ->add('POST', '/rename/', [FileController::class, 'rename'], [LoginRequared::class, StorageMutationLock::class], 'files_rename')
    ->add('GET', '/get/{int:fileId}/', [FileController::class, 'getFile'], [LoginRequared::class], 'files_get')
    ->endGroup();

$router->group('/messenger')
    ->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class], 'messenger')
    ->add('POST', '/socket-ticket', [MessagerController::class, 'socketTicket'], [LoginRequared::class], 'messenger_socket_ticket')
    ->add('POST', '/upload', [MessagerController::class, 'uploadFile'], [LoginRequared::class, UploadRateLimit::class], 'messenger_upload')
    ->add('POST', '/voice-upload', [MessengerVoiceController::class, 'upload'], [LoginRequared::class, UploadRateLimit::class], 'messenger_voice_upload')
    ->add('GET', '/media/{str:uid}', [MessagerController::class, 'media'], [LoginRequared::class], 'messenger_media')
    ->add('GET', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'avatar'], [LoginRequared::class], 'messenger_group_avatar')
    ->add('POST', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'uploadAvatar'], [LoginRequared::class, UploadRateLimit::class], 'messenger_group_avatar_upload')
    ->add('POST', '/group-avatar/{str:uid}/delete', [MessengerGroupController::class, 'removeAvatar'], [LoginRequared::class], 'messenger_group_avatar_delete')
    ->endGroup();

$router->group('/admin')
   ->add('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, RequireAdminAccess::class], 'adminpanel')
   ->add('POST', '/', [AdminController::class, 'saveCustomFields'], [LoginRequared::class, RequireAdminUsersManage::class], 'save_custom_fields')
   ->add('POST', '/users/create', [UserProvisioningController::class, 'create'], [LoginRequared::class, RequireAdminUsersManage::class, CSRFMiddleware::class], 'admin_create_user')
   ->add('POST', '/users/toggle-status', [AdminController::class, 'toggleUserStatus'], [LoginRequared::class, RequireAdminUsersManage::class], 'admin_toggle_user')
   ->add('POST', '/users/delete', [AdminController::class, 'deleteUser'], [LoginRequared::class, RequireAdminUsersManage::class], 'admin_delete_user')
   ->add('GET', '/roles', [RoleManagementController::class, 'index'], [LoginRequared::class, RequireAdminRolesManage::class], 'admin_roles')
   ->add('POST', '/roles/create', [RoleManagementController::class, 'create'], [LoginRequared::class, RequireAdminRolesManage::class, CSRFMiddleware::class], 'admin_roles_create')
   ->add('POST', '/roles/update', [RoleManagementController::class, 'update'], [LoginRequared::class, RequireAdminRolesManage::class, CSRFMiddleware::class], 'admin_roles_update')
   ->add('POST', '/roles/policies', [RoleManagementController::class, 'savePolicies'], [LoginRequared::class, RequireAdminRolesManage::class, CSRFMiddleware::class], 'admin_roles_policies')
   ->add('POST', '/roles/assign', [RoleManagementController::class, 'assign'], [LoginRequared::class, RequireAdminRolesManage::class, CSRFMiddleware::class], 'admin_roles_assign')
   ->add('POST', '/roles/delete', [RoleManagementController::class, 'delete'], [LoginRequared::class, RequireAdminRolesManage::class, CSRFMiddleware::class], 'admin_roles_delete')
   ->add('GET', '/registration', [RegistrationSettingsController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_registration')
   ->add('POST', '/registration/mode', [RegistrationSettingsController::class, 'saveMode'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_registration_mode')
   ->add('POST', '/registration/invites/create', [RegistrationSettingsController::class, 'createInvite'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_registration_invite_create')
   ->add('POST', '/registration/invites/revoke', [RegistrationSettingsController::class, 'revokeInvite'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_registration_invite_revoke')
   ->add('GET', '/settings', [SettingsController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_settings')
   ->add('POST', '/settings/default-quota', [SettingsController::class, 'saveDefaultQuota'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_settings_default_quota')
   ->add('POST', '/settings/user-quota', [SettingsController::class, 'saveUserQuota'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_settings_user_quota')
   ->endGroup();

$router->dispatch();