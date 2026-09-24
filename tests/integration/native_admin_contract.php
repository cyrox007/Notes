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
    'modules/admin/views/index.php',
    'modules/admin/views/registration.php',
    'modules/admin/views/settings.php',
    'modules/admin/views/roles.php',
    'modules/admin/views/updates.php',
    'modules/admin/views/license.php',
    'modules/admin/views/audit.php',
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
    nativeAdminAssert(str_contains($source, "moduleAsset('admin', 'style.css')"), "{$relative} does not load module-owned Admin styles");
    nativeAdminAssert(str_contains($source, "moduleAsset('admin', 'admin-settings-nav.js')"), "{$relative} does not load module-owned navigation behavior");
}

$index = (string) file_get_contents($root . '/modules/admin/views/index.php');
nativeAdminAssert(str_contains($index, "route('admin_create_user')"), 'admin user provisioning route is missing');
nativeAdminAssert(str_contains($index, "route('admin_toggle_user')"), 'admin status route is missing');
nativeAdminAssert(str_contains($index, "route('admin_delete_user')"), 'admin deactivation route is missing');
nativeAdminAssert(str_contains($index, "route('save_custom_fields')"), 'custom field route is missing');
nativeAdminAssert(str_contains($index, '$view->csrfInput()'), 'admin forms lost CSRF inputs');
nativeAdminAssert(str_contains($index, 'id="custom-fields-container"'), 'custom field JS container hook is missing');
nativeAdminAssert(str_contains($index, 'id="add-field-btn"'), 'custom field add-button hook is missing');
nativeAdminAssert(str_contains($index, 'data-confirm-deactivate'), 'safe deactivation confirmation hook is missing');
nativeAdminAssert(str_contains($index, "moduleAsset('admin', 'admin-page.js')"), 'module-owned admin behavior bundle is missing');
nativeAdminAssert(str_contains($index, '$canManageRoles'), 'role manager navigation guard is missing');
nativeAdminAssert(str_contains($index, 'class="admin-toolbar"'), 'Admin user list does not own its server-rendered toolbar');
nativeAdminAssert(str_contains($index, 'id="admin-search"'), 'Admin user search input is missing');
nativeAdminAssert(str_contains($index, 'id="admin-sort"'), 'Admin user sort control is missing');
nativeAdminAssert(str_contains($index, 'class="admin-pagination"'), 'Admin user list does not own server-rendered pagination');
nativeAdminAssert(!str_contains($index, 'data-findability-slot'), 'Admin user list still delegates controls to generic findability JS');

$adminNav = (string) file_get_contents($root . '/modules/admin/assets/admin-settings-nav.js');
nativeAdminAssert(str_contains($adminNav, "['/admin/updates', 'fa-refresh', 'Обновления']"), 'Admin section navigation does not expose signed updates');
nativeAdminAssert(str_contains($adminNav, "['/admin/audit', 'fa-history', 'Журнал действий']"), 'Admin section navigation does not expose the user action journal');
nativeAdminAssert(str_contains($adminNav, 'window.wspaceRuntime?.adminAudit'), 'Admin audit navigation is not permission-aware');
nativeAdminAssert(!str_contains($index, '<div class="admin-user-actions">\n            <a class="admin-action admin-action--secondary" href="<?= $view->e($view->route(\'admin_registration\')) ?>">'), 'Admin hero still duplicates the section navigation');

$audit = (string) file_get_contents($root . '/modules/admin/views/audit.php');
nativeAdminAssert(str_contains($audit, 'class="admin-toolbar admin-audit-toolbar"'), 'audit journal does not use the responsive Admin audit toolbar');
nativeAdminAssert(str_contains($audit, "moduleAsset('admin', 'admin-settings-nav.js')"), 'audit journal is disconnected from Admin section navigation');

$licenseView = (string) file_get_contents($root . '/modules/admin/views/license.php');
$licenseStyle = (string) file_get_contents($root . '/modules/admin/assets/license.css');
nativeAdminAssert(str_contains($licenseView, 'class="admin-license-features"'), 'license features row does not use owned spacing');
nativeAdminAssert(str_contains($licenseStyle, '.admin-license-grid>div:last-child:nth-child(3n+1){grid-column:1/-1}'), 'license grid still leaves an empty grey remainder row');
nativeAdminAssert(str_contains($licenseStyle, '.admin-license-features{'), 'license feature copy is not styled inside the status card');

$registration = (string) file_get_contents($root . '/modules/admin/views/registration.php');
nativeAdminAssert(str_contains($registration, "route('admin_registration_mode')"), 'registration mode route is missing');
nativeAdminAssert(str_contains($registration, "route('admin_registration_invite_create')"), 'invite create route is missing');
nativeAdminAssert(str_contains($registration, "route('admin_registration_invite_revoke')"), 'invite revoke route is missing');
nativeAdminAssert(str_contains($registration, '$view->e($flash[\'invite_code\'])'), 'one-time invite code is not escaped');
nativeAdminAssert(str_contains($registration, '$view->csrfInput()'), 'registration admin forms lost CSRF inputs');

$settings = (string) file_get_contents($root . '/modules/admin/views/settings.php');
nativeAdminAssert(str_contains($settings, "route('admin_settings_default_quota')"), 'default quota route is missing');
nativeAdminAssert(str_contains($settings, "route('admin_settings_user_quota')"), 'per-user quota route is missing');
nativeAdminAssert(str_contains($settings, '1048576'), 'quota byte/MB conversion contract is missing');
nativeAdminAssert(str_contains($settings, '$view->csrfInput()'), 'settings forms lost CSRF inputs');

$roles = (string) file_get_contents($root . '/modules/admin/views/roles.php');
foreach (['admin_roles_create', 'admin_roles_update', 'admin_roles_policies', 'admin_roles_assign', 'admin_roles_delete'] as $route) {
    nativeAdminAssert(str_contains($roles, "route('{$route}')"), "role management route {$route} is missing");
}
nativeAdminAssert(str_contains($roles, '$roleCode === \'superadmin\''), 'superadmin edit lock was dropped');
nativeAdminAssert(str_contains($roles, "permission_codes[]"), 'permission assignment controls are missing');
nativeAdminAssert(str_contains($roles, "policies["), 'role policy controls are missing');
nativeAdminAssert(str_contains($roles, '__inherit__'), 'policy inheritance control is missing');
nativeAdminAssert(str_contains($roles, '$view->csrfInput()'), 'role management forms lost CSRF inputs');
nativeAdminAssert(str_contains($roles, '$view->e($permission[\'code\'] ?? \'\')'), 'permission codes are not escaped');

nativeAdminAssert(str_contains($roles, 'admin-roles-unavailable-title'), 'страница ролей не показывает диагностическое состояние при ошибке загрузки');

$roleController = (string) file_get_contents($root . '/modules/admin/controllers/RoleManagementController.php');
nativeAdminAssert(str_contains($roleController, "'admin_roles_load_error' => "), 'контроллер ролей не передаёт диагностическое состояние в представление');
nativeAdminAssert(str_contains($roleController, "http_response_code(503)"), 'ошибка загрузки ролей не переводится в диагностируемый 503');

$updates = (string) file_get_contents($root . '/modules/admin/views/updates.php');
nativeAdminAssert(str_contains($updates, "route('admin_updates_check')"), 'signed updater check route is missing');
nativeAdminAssert(str_contains($updates, "route('admin_updates_stage')"), 'signed updater stage route is missing');
nativeAdminAssert(str_contains($updates, '$view->csrfInput()'), 'signed updater forms lost CSRF input');
nativeAdminAssert(str_contains($updates, 'Рабочие файлы не менялись'), 'интерфейс обновлений потерял указание о неизменности рабочих файлов на staging');
nativeAdminAssert(str_contains($updates, "route('admin_updates_apply')"), 'Admin UI не показывает защищённую установку обновления');
nativeAdminAssert(str_contains($updates, 'Установить обновление'), 'Admin UI потерял действие установки обновления');
nativeAdminAssert(!str_contains($updates, 'stage_dir'), 'signed updater UI exposes absolute stage path');
nativeAdminAssert(str_contains($updates, 'class="admin-status-grid"'), 'signed updater local/result state is not using Admin status cards');
nativeAdminAssert(str_contains($updates, 'class="admin-status-card"'), 'signed updater status card contract is missing');

$updateController = (string) file_get_contents($root . '/modules/admin/controllers/UpdateController.php');
nativeAdminAssert(str_contains($updateController, "render_template('@admin/updates'"), 'admin controller does not render the module view directly');
nativeAdminAssert(!str_contains($updateController, "'stage_dir' =>"), 'signed updater controller persists absolute stage path into UI state');

$runtime = (string) file_get_contents($root . '/modules/admin/runtime.php');
nativeAdminAssert(str_contains($runtime, "'/middlewares/RequireAdminAuditView.php'"), 'Admin runtime does not load audit permission middleware');
nativeAdminAssert(str_contains($runtime, "'/controllers/AuditController.php'"), 'Admin runtime does not load the audit controller');

$router = (string) file_get_contents($root . '/modules/admin/AdminRuntimeProvider.php');
nativeAdminAssert(str_contains($router, "->add('GET', '/updates'"), 'signed updater page route missing');
nativeAdminAssert(str_contains($router, "->add('GET', '/updates/check'"), 'signed updater read-only check route missing');
nativeAdminAssert(str_contains($router, "->add('POST', '/updates/stage'"), 'signed updater stage route missing');
nativeAdminAssert(str_contains($router, "RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_stage'"), 'signed updater stage middleware contract missing');
nativeAdminAssert(str_contains($router, "->add('POST', '/updates/apply'"), 'signed updater apply route missing');
nativeAdminAssert(str_contains($router, "RequireAdminSettingsManage::class, CSRFMiddleware::class], 'admin_updates_apply'"), 'signed updater apply middleware contract missing');

foreach ([
    'app/controllers/Admin',
    'app/services/AdminUserService.php',
    'app/services/AdminUpdateService.php',
    'app/services/RoleManagementService.php',
    'app/views/admin-page',
    'assets/js/admin-page.js',
    'assets/js/admin-settings-nav.js',
] as $legacyPath) {
    nativeAdminAssert(!file_exists($root . '/' . $legacyPath), "legacy Admin ownership remains: {$legacyPath}");
}

$findability = (string) file_get_contents($root . '/assets/js/findability.js');
nativeAdminAssert(!str_contains($findability, "relative.startsWith('/admin')"), 'generic findability JS still owns Admin list controls');
nativeAdminAssert(!str_contains($findability, "kind === 'admin'"), 'generic findability JS still contains Admin-specific UI branches');

$manifest = json_decode((string) file_get_contents($root . '/modules/admin/module.json'), true, 32, JSON_THROW_ON_ERROR);
nativeAdminAssert(($manifest['runtime']['mode'] ?? null) === 'isolated', 'Admin manifest is not isolated');
nativeAdminAssert(($manifest['runtime']['entrypoint'] ?? null) === 'runtime.php', 'Admin runtime entrypoint drifted');

echo "[OK] isolated native admin contract\n";
