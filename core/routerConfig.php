<?php

declare(strict_types=1);

use App\Controllers\MainController;
use App\Controllers\AuthController;
use App\Controllers\TaskController;
use App\Controllers\TaskBoardController;
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
use App\Controllers\Admin\LicenseController;
use App\Controllers\Admin\UpdateController;
use App\Controllers\MessagerController;
use App\Controllers\MessengerGroupController;
use App\Controllers\MessengerVoiceController;
use App\Middlewares\LoginRequared;
use App\Middlewares\RequireAdminAccess;
use App\Middlewares\RequireAdminUsersManage;
use App\Middlewares\RequireAdminSettingsManage;
use App\Middlewares\RequireAdminRolesManage;
use App\Middlewares\RequireTasksUse;
use App\Middlewares\RequireFilesUse;
use App\Middlewares\RequireMessengerUse;
use App\Middlewares\RequireProfileUse;
use App\Middlewares\AuthRateLimit;
use App\Middlewares\CSRFMiddleware;
use App\Middlewares\UploadRateLimit;
use App\Middlewares\StorageQuotaLimit;
use App\Middlewares\StorageMutationLock;
use App\Middlewares\EnforceFileUploadPolicy;
use App\Middlewares\EnforceFileFolderPolicy;
use App\Middlewares\EnforceTaskCreatePolicy;
use App\Middlewares\EnforceMessengerUploadPolicy;
use App\Middlewares\EnforceLicenseMutation;
use Core\Router;

$router = Router::getInstance();
$router->addGlobalMiddleware(EnforceLicenseMutation::class);

$router->add('GET', '/', [MainController::class, 'index'], [LoginRequared::class], 'main');

$router->group('/auth')
    ->add('GET', '/login', [AuthController::class, 'login'], [], 'authpage')
    ->add('POST', '/login', [AuthController::class, 'sigin'], [AuthRateLimit::class])
    ->add('POST', '/logout', [AuthController::class, 'logout'], [LoginRequared::class], 'logout')
    ->add('GET', '/registration', [AuthController::class, 'registration'], [], 'registration')
    ->add('GET', '/registration/{str:invite_code}', [AuthController::class, 'registration'], [], 'registration_invite')
    ->add('POST', '/registration', [AuthController::class, 'registration'], [AuthRateLimit::class, CSRFMiddleware::class], 'register_submit')
    ->endGroup();

$router->group('/tasks')
    ->add('GET', '/', [TaskController::class, 'index'], [LoginRequared::class, RequireTasksUse::class], 'tasks')
    ->add('POST', '/', [TaskController::class, 'create'], [LoginRequared::class, RequireTasksUse::class, EnforceTaskCreatePolicy::class], 'task_create')
    ->add('GET', '/boards', [TaskBoardController::class, 'index'], [LoginRequared::class, RequireTasksUse::class], 'task_boards')
    ->add('POST', '/boards', [TaskBoardController::class, 'createBoard'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_create')
    ->add('POST', '/boards/{str:uid}/members', [TaskBoardController::class, 'saveMembers'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_members')
    ->add('POST', '/boards/{str:uid}/tasks', [TaskBoardController::class, 'createTask'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_task_create')
    ->add('POST', '/boards/task/{str:uid}/update', [TaskBoardController::class, 'updateTask'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_task_update')
    ->add('POST', '/boards/task/{str:uid}/delete', [TaskBoardController::class, 'deleteTask'], [LoginRequared::class, RequireTasksUse::class, CSRFMiddleware::class], 'task_board_task_delete')
    ->add('POST', '/{str:uid}/update', [TaskController::class, 'update'], [LoginRequared::class, RequireTasksUse::class], 'update_task')
    ->add('POST', '/{str:uid}/delete', [TaskController::class, 'delete'], [LoginRequared::class, RequireTasksUse::class], 'delete_task')
    ->add('POST', '/{str:taskUid}/subtask', [TaskController::class, 'addSubtask'], [LoginRequared::class, RequireTasksUse::class], 'add_subtask')
    ->add('POST', '/subtask/{int:subtaskId}/toggle', [TaskController::class, 'toggleSubtask'], [LoginRequared::class, RequireTasksUse::class], 'toggle_subtask')
    ->add('POST', '/subtask/{int:subtaskId}/delete', [TaskController::class, 'deleteSubtask'], [LoginRequared::class, RequireTasksUse::class], 'delete_subtask')
    ->add('POST', '/category', [TaskController::class, 'createCategory'], [LoginRequared::class, RequireTasksUse::class], 'create_category')
    ->add('POST', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'attachCategory'], [LoginRequared::class, RequireTasksUse::class], 'attach_category')
    ->add('DELETE', '/{str:taskUid}/category/{int:categoryId}', [TaskController::class, 'detachCategory'], [LoginRequared::class, RequireTasksUse::class], 'detach_category')
    ->endGroup();

$router->group('/profile')
   ->add('GET', '/', [ProfileController::class, 'index'], [LoginRequared::class, RequireProfileUse::class], 'profile')
   ->add('GET', '/user/{str:uid}', [PublicProfileController::class, 'view'], [LoginRequared::class, RequireProfileUse::class], 'profile-public')
   ->add('POST', '/publication', [ProfileController::class, 'setPublication'], [LoginRequared::class, RequireProfileUse::class], 'profile-publication')
   ->add('POST', '/', [ProfileController::class, 'update'], [LoginRequared::class, RequireProfileUse::class], 'profile-set')
   ->add('GET', '/avatar/{str:uid}', [ProfileController::class, 'avatar'], [LoginRequared::class, RequireProfileUse::class], 'profile-avatar')
   ->add('POST', '/avatar/delete', [ProfileController::class, 'removeAvatar'], [LoginRequared::class, RequireProfileUse::class], 'profile-avatar-delete')
   ->add('POST', '/change-pass', [ProfileController::class, 'changeUserPass'], [LoginRequared::class, RequireProfileUse::class], 'profile-password-set')
   ->add('POST', '/delete-user', [ProfileController::class, 'deleteUser'], [LoginRequared::class, RequireProfileUse::class], 'profile-delete')
   ->endGroup();

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

$router->group('/messenger')
    ->add('GET', '/', [MessagerController::class, 'index'], [LoginRequared::class, RequireMessengerUse::class], 'messenger')
    ->add('POST', '/socket-ticket', [MessagerController::class, 'socketTicket'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_socket_ticket')
    ->add('POST', '/upload', [MessagerController::class, 'uploadFile'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class, EnforceMessengerUploadPolicy::class], 'messenger_upload')
    ->add('POST', '/voice-upload', [MessengerVoiceController::class, 'upload'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class, EnforceMessengerUploadPolicy::class], 'messenger_voice_upload')
    ->add('GET', '/media/{str:uid}', [MessagerController::class, 'media'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_media')
    ->add('GET', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'avatar'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_group_avatar')
    ->add('POST', '/group-avatar/{str:uid}', [MessengerGroupController::class, 'uploadAvatar'], [LoginRequared::class, RequireMessengerUse::class, UploadRateLimit::class], 'messenger_group_avatar_upload')
    ->add('POST', '/group-avatar/{str:uid}/delete', [MessengerGroupController::class, 'removeAvatar'], [LoginRequared::class, RequireMessengerUse::class], 'messenger_group_avatar_delete')
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
   ->add('GET', '/license', [LicenseController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_license')
   ->add('POST', '/license/activate', [LicenseController::class, 'activate'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_license_activate')
   ->add('POST', '/license/clear', [LicenseController::class, 'clear'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_license_clear')
   ->add('GET', '/updates', [UpdateController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_updates')
   ->add('GET', '/updates/check', [UpdateController::class, 'check'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_updates_check')
   ->add('POST', '/updates/stage', [UpdateController::class, 'stage'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_stage')
   ->endGroup();
