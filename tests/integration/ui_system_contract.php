<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$basePath = $root . '/app/views/core/base.php';
$headerPath = $root . '/app/views/^shared/header/index.php';
$sidebarPath = $root . '/app/views/^shared/sidebar/index.php';
$headerStylePath = $root . '/app/views/^shared/header/style.css';
$sidebarStylePath = $root . '/app/views/^shared/sidebar/style.css';
$cssPath = $root . '/assets/css/workspace-ui-1.0.css';
$scriptPath = $root . '/assets/js/theme-mode.js';

function uiSystemAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] UI system contract: {$message}\n");
        exit(1);
    }
}

foreach ([$basePath, $headerPath, $sidebarPath, $headerStylePath, $sidebarStylePath, $cssPath, $scriptPath] as $path) {
    uiSystemAssert(is_file($path), 'missing UI system file: ' . $path);
}

$base = file_get_contents($basePath);
$header = file_get_contents($headerPath);
$sidebar = file_get_contents($sidebarPath);
$headerStyle = file_get_contents($headerStylePath);
$sidebarStyle = file_get_contents($sidebarStylePath);
$css = file_get_contents($cssPath);
$script = file_get_contents($scriptPath);

uiSystemAssert(
    is_string($base)
    && is_string($header)
    && is_string($sidebar)
    && is_string($headerStyle)
    && is_string($sidebarStyle)
    && is_string($css)
    && is_string($script),
    'UI system source is unreadable'
);

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
        str_contains($sidebar, 'data-theme-option="' . $theme . '"'),
        "theme picker is missing {$theme} mode"
    );
}
uiSystemAssert(str_contains($header, 'data-command-open'), 'top command/search trigger is missing');
uiSystemAssert(str_contains($header, 'data-command-palette'), 'command palette is missing from the structural shell');
uiSystemAssert(str_contains($sidebar, 'data-nav-key="home"'), 'sidebar home navigation is missing');
uiSystemAssert(str_contains($sidebar, 'data-sidebar-toggle'), 'sidebar collapse control is missing');

uiSystemAssert(str_contains($headerStyle, '.workspace-command-trigger'), 'shared header stylesheet lost command bar ownership');
uiSystemAssert(str_contains($sidebarStyle, '.sidebar__theme'), 'shared sidebar stylesheet lost theme picker ownership');
foreach (['.navbar__theme-option', '.navbar__theme-picker', '.sidebar__user-panel', '.sidebar__site-title'] as $legacyShellSelector) {
    uiSystemAssert(
        !str_contains($css, $legacyShellSelector),
        "unified UI CSS still carries obsolete shell selector: {$legacyShellSelector}"
    );
}
uiSystemAssert(!str_contains($css, '--sidebar-width: 198px'), 'legacy responsive sidebar width override returned');

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
    '.module-page-header',
    'body[data-workspace-section="notes"]',
] as $marker) {
    uiSystemAssert(str_contains($css, $marker), "unified UI CSS is missing marker: {$marker}");
}

foreach ([
    "workspace.theme",
    "prefers-color-scheme: dark",
    "aria-pressed",
    "dataset.theme",
] as $marker) {
    uiSystemAssert(str_contains($script, $marker), "theme controller is missing marker: {$marker}");
}

fwrite(STDOUT, "[OK] unified light/dark UI system contract\n");
