<?php
declare(strict_types=1);

function catalogAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function renderCatalog(array $modules): string
{
    $view = new class {
        public function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
        public function route(string $name): string { return '/' . $name; }
        public function csrfInput(): string { return '<input name="csrf_token" value="fixture">'; }
        public function moduleAsset(string $module, string $file): string { return '/module-assets/' . $module . '/' . $file; }
        public function layout(string $name, array $data, string $content): string { return $content; }
    };
    ob_start();
    require dirname(__DIR__, 2) . '/modules/admin/views/modules.php';
    return (string) ob_get_clean();
}

$modules = [];
foreach (['admin', 'notes', 'defects', 'work-permits'] as $id) {
    $modules[] = [
        'id' => $id, 'name' => $id, 'version' => '1.2.0', 'required' => $id === 'admin',
        'configured_state' => 'enabled', 'effective_state' => 'enabled',
        'license_entitled' => in_array($id, ['admin', 'notes'], true),
        'license_feature' => 'workspace.' . $id, 'license_reason' => 'Нет разрешения в ключе',
    ];
}
$html = renderCatalog($modules);
foreach (['defects', 'work-permits'] as $id) {
    catalogAssert(str_contains($html, 'workspace.' . $id), 'Installed unlicensed module disappeared');
    catalogAssert(!str_contains($html, 'name="module_id" value="' . $id . '"'), 'Unlicensed module has a mutation form');
}
catalogAssert(substr_count($html, 'Не разрешён лицензией') === 2, 'Missing license decisions');
catalogAssert(substr_count($html, 'Требуется расширение лицензии') === 2, 'Missing explanation for locked modules');
catalogAssert(substr_count($html, 'href="/system_license"') === 2, 'Missing key update action');
catalogAssert(str_contains($html, 'name="module_id" value="notes"'), 'Licensed optional module lost its controls');
catalogAssert(!str_contains($html, 'name="module_id" value="admin"') && str_contains($html, 'Отключение запрещено'), 'Required module can be disabled');
$missingDecision = $modules[1];
unset($missingDecision['license_entitled']);
catalogAssert(!str_contains(renderCatalog([$missingDecision]), 'name="module_id"'), 'Missing license decision must fail closed');
echo "[OK] installed module catalog: locked modules visible, no unlicensed mutations, licensed controls and required-module protection preserved\n";
