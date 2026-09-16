<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../core/ViewRenderer.php';
require_once __DIR__ . '/../../core/ViewContext.php';
require_once __DIR__ . '/../../core/NativeViewRenderer.php';

use Core\NativeViewRenderer;
use Core\Request;
use Core\ViewContext;

function nativeLayoutAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/wo-native-layout-' . bin2hex(random_bytes(6));
mkdir($root . '/page', 0777, true);
mkdir($root . '/core', 0777, true);

file_put_contents(
    $root . '/page/index.php',
    <<<'PHP'
<?php
$content = '<main id="page-content">page</main>';
echo $view->layout('core/base', ['title' => 'Explicit title'], $content);
PHP
);
file_put_contents(
    $root . '/core/base.php',
    <<<'PHP'
<?php
$runtime = is_array($licenseRuntime ?? null) ? $licenseRuntime : [];
echo '<title>' . $view->e($title ?? '') . '</title>';
echo '<div data-code="' . $view->e($runtime['code'] ?? '') . '" data-read-only="' . ((!($runtime['writable'] ?? true)) ? '1' : '0') . '">';
echo $content ?? '';
echo '</div>';
PHP
);

$request = new Request();
$renderer = new NativeViewRenderer($root, new ViewContext($request));

ob_start();
$renderer->render('page/index', [
    'title' => 'Inherited title',
    'licenseRuntime' => [
        'enforced' => true,
        'writable' => false,
        'code' => 'expired',
        'can_manage' => true,
    ],
]);
$output = (string) ob_get_clean();

nativeLayoutAssert(str_contains($output, '<title>Explicit title</title>'), 'explicit layout data did not override inherited render data');
nativeLayoutAssert(str_contains($output, 'data-code="expired"'), 'layout did not inherit licenseRuntime from the page render context');
nativeLayoutAssert(str_contains($output, 'data-read-only="1"'), 'layout lost inherited read-only state');
nativeLayoutAssert(str_contains($output, '<main id="page-content">page</main>'), 'trusted page content was not rendered inside layout');

@unlink($root . '/page/index.php');
@unlink($root . '/core/base.php');
@rmdir($root . '/page');
@rmdir($root . '/core');
@rmdir($root);

echo "[OK] native layout inherits page render context\n";
