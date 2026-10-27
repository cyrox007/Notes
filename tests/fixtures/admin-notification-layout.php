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
$notification_settings = ['last_http_run' => null];
require dirname(__DIR__, 2) . '/modules/admin/views/notifications.php';
