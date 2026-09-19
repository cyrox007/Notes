<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$basePath = $root . '/app/views/core/base.php';
$headerPath = $root . '/app/views/^shared/header/index.php';
$cssPath = $root . '/assets/css/workspace-ui-1.0.css';
$scriptPath = $root . '/assets/js/theme-mode.js';

function uiSystemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] UI system contract: {$message}\n");
        exit(1);
    }
}

foreach ([$basePath, $headerPath, $cssPath, $scriptPath] as $path) {
    uiSystemAssert(is_file($path), 'missing UI system file: ' . $path);
}

$base = file_get_contents($basePath);
$header = file_get_contents($headerPath);
$css = file_get_contents($cssPath);
$script = file_get_contents($scriptPath);

uiSystemAssert(is_string($base) && is_string($header) && is_string($css) && is_string($script), 'UI system source is unreadable');

$uiStylesheet = '/assets/css/workspace-ui-1.0.css';
$controlsMarker = "echo $controlsCss";
$uiPosition = strpos($base, $uiStylesheet);
$controlsPosition = strpos($base, $controlsMarker);
uiSystemAssert($uiPosition !== false, 'unified UI stylesheet is not loaded');
uiSystemAssert($controlsPosition !== false && $uiPosition > $controlsPosition, 'unified UI stylesheet must load after controls and module styles');
uiSystemAssert(str_contains($base, '/assets/js/theme-mode.js'), 'theme controller is not loaded');
uiSystemAssert(str_contains($base, "localStorage.getItem('workspace.theme') || 'light'"), 'light theme must be the safe default before paint');

foreach (['light', 'system', 'dark'] as $theme) {
    uiSystemAssert(
        str_contains($header, 'data-theme-option="' . $theme . '"'),
        "theme picker is missing {$theme} mode"
    );
}

foreach ([
    'html[data-theme="light"]',
    'html[data-theme="dark"]',
    '--ui-primary:',
    '--ui-bg:',
    '.content-header',
    '.notes__content',
    '.tasks__controls',
    '.file-manager__item',
    '.messenger-app',
    '.profile--hub',
    '.admin-page',
] as $marker) {
    uiSystemAssert(str_contains($css, $marker), "unified UI CSS is missing marker: {$marker}");
}

foreach ([
    "workspace.theme",
    "prefers-color-scheme: dark",
    "aria-pressed",
    "data-theme",
] as $marker) {
    uiSystemAssert(str_contains($script, $marker), "theme controller is missing marker: {$marker}");
}

fwrite(STDOUT, "[OK] unified light/dark UI system contract\n");
