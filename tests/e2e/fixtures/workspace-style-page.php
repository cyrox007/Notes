<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 3);
require_once $root . '/core/SecurityHeaders.php';
$view = new class($root) {
    public function __construct(private string $root) {}
    public function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    public function route(string $name): string {
        return '/nested' . (['main' => '/', 'adminpanel' => '/admin', 'admin_settings' => '/admin/settings', 'system_license' => '/admin/license'][$name] ?? '/' . $name);
    }
    public function csrfInput(): string { return ''; }
    public function moduleAsset(string $module, string $file): string { return '/nested/modules/' . $module . '/assets/' . $file; }
    public function partial(string $name, array $data): string {
        if ($name !== '^shared/sidebar/index') { return ''; }
        extract($data);
        $view = $this;
        ob_start();
        include $this->root . '/app/views/' . $name . '.php';
        return (string) ob_get_clean();
    }
    public function layout(string $name, array $data, string $content): string {
        extract($data);
        $view = $this;
        ob_start();
        include $this->root . '/app/views/core/base.php';
        return (string) ob_get_clean();
    }
};
$_SERVER['REQUEST_URI'] = '/nested/admin/settings';
$base_url = '/nested';
$workspaceAccess = ['license_manage' => true, 'admin' => true, 'admin_settings' => true, 'notes' => true, 'tasks' => true, 'files' => true];
$files_enabled = true;
$default_quota_bytes = 1073741824;
$upload_limit_bytes = 10485760;
$storage_capacity = ['total_bytes' => 107374182400, 'free_bytes' => 21474836480, 'used_bytes' => 1073741824, 'assigned_bytes' => 32212254720, 'potential_bytes' => 31138512896, 'overcommitted' => true, 'shortfall_bytes' => 9663676416];
include $root . '/modules/admin/views/settings.php';

