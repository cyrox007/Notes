<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeAdminAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$views = [
    'app/views/admin-page/index.php',
    'app/views/admin-page/registration.php',
    'app/views/admin-page/settings.php',
    'app/views/admin-page/roles.php',
];
foreach ($views as $relative) {
    $path = $root . '/' . $relative;
    nativeAdminAssert(is_file($path), "missing native admin view: {$relative}");
    $source = file_get_contents($path);
    nativeAdminAssert(is_string($source), "cannot read native admin view: {$relative}");
    nativeAdminAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty extends syntax");
    nativeAdminAssert(!str_contains($source, '{foreach'), "{$relative} still contains Smarty foreach syntax");
    nativeAdminAssert(!str_contains($source, '{if'), "{$relative} still contains Smarty conditional syntax");
    nativeAdminAssert(!str_contains($source, '$smarty'), "{$relative} still depends on Smarty runtime state");
    nativeAdminAssert(str_contains($source, '$view->layout(\'core/base\''), "{$relative} does not use native application shell");
}

$index = (string) file_get_contents($root . '/app/views/admin-page/index.php');
nativeAdminAssert(str_contains($index, "route('admin_create_user')"), 'admin user provisioning route is missing');
nativeAdminAssert(str_contains($index, "route('admin_toggle_user')"), 'admin status route is missing');
nativeAdminAssert(str_contains($index, "route('admin_delete_user')"), 'admin deactivation route is missing');
nativeAdminAssert(str_contains($index, "route('save_custom_fields')"), 'custom field route is missing');
nativeAdminAssert(str_contains($index, '$view->csrfInput()'), 'admin forms lost CSRF inputs');
nativeAdminAssert(str_contains($index, 'id="custom-fields-container"'), 'custom field JS container hook is missing');
nativeAdminAssert(str_contains($index, 'id="add-field-btn"'), 'custom field add-button hook is missing');
nativeAdminAssert(str_contains($index, 'data-confirm-deactivate'), 'safe deactivation confirmation hook is missing');
nativeAdminAssert(str_contains($index, '/assets/js/admin-page.js'), 'admin behavior bundle is missing');
nativeAdminAssert(str_contains($index, '$canManageRoles'), 'role manager navigation guard is missing');

$registration = (string) file_get_contents($root . '/app/views/admin-page/registration.php');
nativeAdminAssert(str_contains($registration, "route('admin_registration_mode')"), 'registration mode route is missing');
nativeAdminAssert(str_contains($registration, "route('admin_registration_invite_create')"), 'invite create route is missing');
nativeAdminAssert(str_contains($registration, "route('admin_registration_invite_revoke')"), 'invite revoke route is missing');
nativeAdminAssert(str_contains($registration, '$view->e($flash[\'invite_code\'])'), 'one-time invite code is not escaped');
nativeAdminAssert(str_contains($registration, '$view->csrfInput()'), 'registration admin forms lost CSRF inputs');

$settings = (string) file_get_contents($root . '/app/views/admin-page/settings.php');
nativeAdminAssert(str_contains($settings, "route('admin_settings_default_quota')"), 'default quota route is missing');
nativeAdminAssert(str_contains($settings, "route('admin_settings_user_quota')"), 'per-user quota route is missing');
nativeAdminAssert(str_contains($settings, '1048576'), 'quota byte/MB conversion contract is missing');
nativeAdminAssert(str_contains($settings, '$view->csrfInput()'), 'settings forms lost CSRF inputs');

$roles = (string) file_get_contents($root . '/app/views/admin-page/roles.php');
foreach (['admin_roles_create', 'admin_roles_update', 'admin_roles_policies', 'admin_roles_assign', 'admin_roles_delete'] as $route) {
    nativeAdminAssert(str_contains($roles, "route('{$route}')"), "role management route {$route} is missing");
}
nativeAdminAssert(str_contains($roles, "$roleCode === 'superadmin'"), 'superadmin edit lock was dropped');
nativeAdminAssert(str_contains($roles, "permission_codes[]"), 'permission assignment controls are missing');
nativeAdminAssert(str_contains($roles, "policies["), 'role policy controls are missing');
nativeAdminAssert(str_contains($roles, '__inherit__'), 'policy inheritance control is missing');
nativeAdminAssert(str_contains($roles, '$view->csrfInput()'), 'role management forms lost CSRF inputs');
nativeAdminAssert(str_contains($roles, '$view->e($permission[\'code\'] ?? \'\')'), 'permission codes are not escaped');

echo "[OK] native admin views contract\n";
