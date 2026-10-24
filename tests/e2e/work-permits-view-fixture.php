<?php

declare(strict_types=1);

/**
 * Браузерная проверка только реального представления модуля.
 * Никаких замен таблиц, маршрутов и разметки приложения здесь нет.
 */
final class WorkPermitBrowserView
{
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function moduleAsset(string $module, string $asset): string
    {
        return '/assets/' . $module . '/' . $asset;
    }

    /** @param array<string,mixed> $data */
    public function layout(string $name, array $data, string $content): string
    {
        return $content;
    }
}

$view = new WorkPermitBrowserView();
$base_url = '';
$csrf_input = '<input type="hidden" name="csrf_token" value="test-csrf-token">';
$can_manage_references = ($argv[1] ?? 'manager') === 'manager';

ob_start();
require dirname(__DIR__, 2) . '/modules/work-permits/views/index.php';
$content = (string) ob_get_clean();
echo '<!doctype html><html lang="ru"><head>'
    . '<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    . '<title>Браузерная проверка нарядов</title></head><body>'
    . $content . '</body></html>';
