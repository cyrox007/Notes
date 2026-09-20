<?php
declare(strict_types=1);
$moduleRoot = __DIR__;
foreach (['/AdminCapability.php','/services/AdminUserService.php','/services/AdminUpdateService.php','/services/RoleManagementService.php','/middlewares/RequireAdminAccess.php','/middlewares/RequireAdminAuditView.php','/middlewares/RequireAdminUsersManage.php','/middlewares/RequireAdminSettingsManage.php','/middlewares/RequireAdminRolesManage.php','/controllers/AdminController.php','/controllers/AuditController.php','/controllers/LicenseController.php','/controllers/RegistrationSettingsController.php','/controllers/RoleManagementController.php','/controllers/SettingsController.php','/controllers/UpdateController.php','/controllers/UserProvisioningController.php','/AdminRuntimeProvider.php'] as $relativePath) {
    $path = $moduleRoot . $relativePath;
    if (!is_file($path)) { throw new RuntimeException('Admin module runtime file is missing: ' . $relativePath); }
    require_once $path;
}
return new \Modules\Admin\AdminRuntimeProvider();
