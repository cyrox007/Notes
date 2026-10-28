<?php
declare(strict_types=1);
$view = new class {
    public function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    public function route(string $name): string { return '/admin/notifications/' . $name; }
    public function csrfInput(): string { return '<input type="hidden" name="csrf_token" value="fixture">'; }
    public function moduleAsset(string $module, string $file): string { return '/modules/' . $module . '/assets/' . $file; }
    public function layout(string $name, array $data, string $content): string
    {
        return json_encode(['content' => $content, 'styles' => $data['module_styles']], JSON_THROW_ON_ERROR);
    }
};
$can_manage_references = true;
require_once dirname(__DIR__, 2) . '/modules/files/services/FileSpaceAccessService.php';
$can_manage_defects = true;
$can_admin_files = true;
$catalog = ['can_create' => true, 'can_upload' => true, 'can_manage' => true];
$templates = [
    'work-permits' => '/modules/work-permits/views/index.php',
    'defects' => '/modules/defects/views/index.php',
    'spaces' => '/modules/files/views/spaces.php',
    'admin-spaces' => '/modules/files/views/admin-spaces.php',
];
$template = $templates[$argv[1] ?? 'work-permits'] ?? throw new InvalidArgumentException('Unknown fixture');
require dirname(__DIR__, 2) . $template;

