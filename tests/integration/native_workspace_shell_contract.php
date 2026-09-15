<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function nativeShellAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$nativeViews = [
    'app/views/core/base.php',
    'app/views/^shared/header/index.php',
    'app/views/^shared/sidebar/index.php',
    'app/views/^shared/footer/index.php',
    'app/views/main_page/index.php',
];

foreach ($nativeViews as $relative) {
    $path = $root . '/' . $relative;
    nativeShellAssert(is_file($path), "missing native view: {$relative}");
    $source = file_get_contents($path);
    nativeShellAssert(is_string($source), "cannot read native view: {$relative}");
    nativeShellAssert(!str_contains($source, '{extends'), "{$relative} still contains Smarty extends syntax");
    nativeShellAssert(!str_contains($source, '{include'), "{$relative} still contains Smarty include syntax");
    nativeShellAssert(!str_contains($source, '$smarty'), "{$relative} still reads Smarty runtime state");
}

$base = (string) file_get_contents($root . '/app/views/core/base.php');
nativeShellAssert(str_contains($base, "partial('^shared/sidebar/index'"), 'native shell does not render native sidebar partial');
nativeShellAssert(str_contains($base, "partial('^shared/header/index'"), 'native shell does not render native header partial');
nativeShellAssert(str_contains($base, "partial('^shared/footer/index'"), 'native shell does not render native footer partial');
nativeShellAssert(str_contains($base, '/assets/js/common.js'), 'native shell does not load static common runtime');
nativeShellAssert(str_contains($base, 'window.wspaceRuntime'), 'native shell does not publish runtime configuration');
nativeShellAssert(str_contains($base, 'JSON_HEX_TAG'), 'native shell runtime configuration is not hardened for inline script context');
nativeShellAssert(str_contains($base, '$view->csrfInput()'), 'native shell does not expose CSRF token template');
nativeShellAssert(str_contains($base, 'data-list-page'), 'native shell dropped pagination data attributes');

$header = (string) file_get_contents($root . '/app/views/^shared/header/index.php');
$sidebar = (string) file_get_contents($root . '/app/views/^shared/sidebar/index.php');
$main = (string) file_get_contents($root . '/app/views/main_page/index.php');
foreach ([$header, $sidebar, $main] as $source) {
    nativeShellAssert(!str_contains($source, "['role'] == 1"), 'native navigation still depends on legacy numeric role checks');
}
nativeShellAssert(str_contains($header, '$access[\'admin\']'), 'native header does not use RBAC-derived admin access');
nativeShellAssert(str_contains($sidebar, '$access[\'notes\']'), 'native sidebar does not use RBAC-derived module access');
nativeShellAssert(str_contains($main, '$view->layout(\'core/base\''), 'native main page does not use native application shell');
nativeShellAssert(str_contains($main, '$access[\'messenger\']'), 'native main page does not gate module cards by RBAC-derived access');

$common = (string) file_get_contents($root . '/assets/js/common.js');
nativeShellAssert($common !== '', 'static common runtime is missing');
nativeShellAssert(!str_contains($common, '{literal}'), 'static common runtime still contains Smarty literal tags');
nativeShellAssert(!str_contains($common, '{$'), 'static common runtime still contains Smarty interpolation');
nativeShellAssert(str_contains($common, 'global.wspaceRuntime'), 'static common runtime does not consume native runtime configuration');
nativeShellAssert(str_contains($common, 'X-CSRF-Token'), 'static common runtime dropped CSRF fetch/XHR hardening');
nativeShellAssert(str_contains($common, 'workspace.sidebar.collapsed'), 'static common runtime dropped shell navigation behavior');

echo "[OK] native workspace shell and main page contract\n";
