<?php
declare(strict_types=1);
namespace Modules\Admin;
use App\Controllers\Admin\{AdminController,AuditController,LicenseController,RegistrationSettingsController,RoleManagementController,SettingsController,UpdateController,UserProvisioningController};
use App\Middlewares\{CSRFMiddleware,LoginRequared,RequireAdminAccess,RequireAdminAuditView,RequireAdminRolesManage,RequireAdminSettingsManage,RequireAdminUsersManage};
use Core\{ModuleRuntimeProvider,Router};
final class AdminRuntimeProvider implements ModuleRuntimeProvider
{
    private AdminCapability $capability;
    public function __construct() { $this->capability = new AdminCapability(); }
    public function moduleId(): string { return 'admin'; }
    public function boot(): void {}
    /** @return array<string,object> */
    public function capabilities(): array { return ['workspace.admin' => $this->capability]; }
    public function registerRoutes(Router $router): void
    {
        $router->group('/admin')
            ->add('GET', '/', [AdminController::class, 'index'], [LoginRequared::class, RequireAdminAccess::class], 'adminpanel')
            ->add('GET', '/audit', [AuditController::class, 'index'], [LoginRequared::class, RequireAdminAuditView::class], 'admin_audit')
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
            ->add('POST', '/settings/two-factor', [SettingsController::class, 'saveTwoFactorPolicy'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_settings_two_factor')
            ->add('POST', '/settings/default-quota', [SettingsController::class, 'saveDefaultQuota'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_settings_default_quota')
            ->add('POST', '/settings/user-quota', [SettingsController::class, 'saveUserQuota'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_settings_user_quota')
            ->add('GET', '/license', [LicenseController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_license')
            ->add('POST', '/license/activate', [LicenseController::class, 'activate'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_license_activate')
            ->add('POST', '/license/clear', [LicenseController::class, 'clear'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_license_clear')
            ->add('GET', '/updates', [UpdateController::class, 'index'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_updates')
            ->add('GET', '/updates/status', [UpdateController::class, 'status'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_updates_status')
            ->add('GET', '/updates/check', [UpdateController::class, 'check'], [LoginRequared::class, RequireAdminSettingsManage::class], 'admin_updates_check')
            ->add('POST', '/updates/stage', [UpdateController::class, 'stage'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_stage')
            ->add('POST', '/updates/apply-latest', [UpdateController::class, 'applyLatest'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_apply_latest')
            ->add('POST', '/updates/apply', [UpdateController::class, 'apply'], [LoginRequared::class, RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_apply')
            ->endGroup();
    }
}
