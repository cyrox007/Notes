<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../core/ViewRenderer.php';
require_once __DIR__ . '/../../core/ViewContext.php';
require_once __DIR__ . '/../../core/NativeViewRenderer.php';

use Core\NativeViewRenderer;
use Core\Request;
use Core\ViewContext;

function nativeViewAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/wo-native-view-' . bin2hex(random_bytes(6));
mkdir($root . '/auth', 0777, true);
file_put_contents(
    $root . '/auth/native.php',
    '<?php echo $view->e($dangerous);'
);

$request = new Request();
$context = new ViewContext($request);
$native = new NativeViewRenderer($root, $context);

ob_start();
$native->render('auth/native', ['dangerous' => '<script>alert("x")</script>']);
$nativeOutput = (string) ob_get_clean();
nativeViewAssert(
    $nativeOutput === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
    'native renderer did not escape HTML by default helper'
);

$missingBlocked = false;
try {
    $native->render('auth/missing');
} catch (RuntimeException) {
    $missingBlocked = true;
}
nativeViewAssert($missingBlocked, 'native renderer silently accepted a missing template');

$blocked = false;
try {
    $native->render('../outside');
} catch (RuntimeException) {
    $blocked = true;
}
nativeViewAssert($blocked, 'native renderer accepted path traversal');

$appViews = realpath(__DIR__ . '/../../app/views');
nativeViewAssert(is_string($appViews), 'application view directory not found');
$appNative = new NativeViewRenderer($appViews, $context);

$controllerRoot = realpath(__DIR__ . '/../../app/controllers');
nativeViewAssert(is_string($controllerRoot), 'controller directory not found');
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerRoot));
$renderedTemplates = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname()) ?: '';
    nativeViewAssert(
        !str_contains($source, '->view->render_template('),
        'controller still bypasses native Controller::render_template(): ' . $file->getFilename()
    );
    nativeViewAssert(
        !str_contains($source, 'new View('),
        'controller still constructs the obsolete Core\\View path: ' . $file->getFilename()
    );

    if (preg_match_all('/\$this->render_template\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches) !== false) {
        foreach ($matches[1] as $template) {
            $renderedTemplates[$template] = true;
        }
    }
}

nativeViewAssert($renderedTemplates !== [], 'no controller render_template calls discovered');
foreach (array_keys($renderedTemplates) as $template) {
    nativeViewAssert(
        $appNative->hasTemplate($template),
        "controller template {$template} has no native .php implementation"
    );
}

$controllerSource = file_get_contents(__DIR__ . '/../../core/controller.php') ?: '';
nativeViewAssert(str_contains($controllerSource, 'new NativeViewRenderer('), 'base Controller does not construct NativeViewRenderer');
nativeViewAssert(!str_contains($controllerSource, 'HybridViewRenderer'), 'base Controller still uses hybrid renderer fallback');
nativeViewAssert(!str_contains($controllerSource, 'LegacySmartyRenderer'), 'base Controller still references legacy Smarty renderer');

$coreSource = file_get_contents(__DIR__ . '/../../core.php') ?: '';
nativeViewAssert(!str_contains($coreSource, 'LegacySmartyRenderer.php'), 'core bootstrap still loads LegacySmartyRenderer');
nativeViewAssert(!str_contains($coreSource, 'HybridViewRenderer.php'), 'core bootstrap still loads HybridViewRenderer');
nativeViewAssert(!is_file(__DIR__ . '/../../core/LegacySmartyRenderer.php'), 'LegacySmartyRenderer file still exists');
nativeViewAssert(!is_file(__DIR__ . '/../../core/HybridViewRenderer.php'), 'HybridViewRenderer file still exists');
nativeViewAssert($appNative->hasTemplate('error_page/index'), 'native error view is missing');

$composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json') ?: '', true);
nativeViewAssert(is_array($composer), 'composer.json is not valid JSON');
nativeViewAssert(!isset($composer['require']['smarty/smarty']), 'composer.json still requires smarty/smarty');

$lock = json_decode(file_get_contents(__DIR__ . '/../../composer.lock') ?: '', true);
nativeViewAssert(is_array($lock), 'composer.lock is not valid JSON');
$lockedPackages = array_map(
    static fn (array $package): string => (string) ($package['name'] ?? ''),
    is_array($lock['packages'] ?? null) ? $lock['packages'] : []
);
nativeViewAssert(!in_array('smarty/smarty', $lockedPackages, true), 'composer.lock still contains smarty/smarty');
nativeViewAssert(!in_array('symfony/polyfill-mbstring', $lockedPackages, true), 'Smarty-only mbstring polyfill remains locked');
nativeViewAssert(in_array('workerman/workerman', $lockedPackages, true), 'Workerman lock entry was lost before its replacement phase');

@unlink($root . '/auth/native.php');
@rmdir($root . '/auth');
@rmdir($root);

echo "[OK] native-only view runtime, template coverage and Composer cutover contract\n";
