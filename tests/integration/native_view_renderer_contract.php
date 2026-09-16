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
$globalRoot = $root . '/app-views';
$notesRoot = $root . '/modules/notes/views';
mkdir($globalRoot . '/auth', 0777, true);
mkdir($notesRoot . '/page', 0777, true);
file_put_contents(
    $globalRoot . '/auth/native.php',
    '<?php echo $view->e($dangerous);'
);
file_put_contents(
    $notesRoot . '/page/index.php',
    '<?php echo "module:" . $view->e($label);'
);

$request = new Request();
$context = new ViewContext($request);
$native = new NativeViewRenderer($globalRoot, $context, ['notes' => $notesRoot]);

ob_start();
$native->render('auth/native', ['dangerous' => '<script>alert("x")</script>']);
$nativeOutput = (string) ob_get_clean();
nativeViewAssert(
    $nativeOutput === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
    'native renderer did not escape HTML by default helper'
);

ob_start();
$native->render('@notes/page/index', ['label' => '<owned>']);
$moduleOutput = (string) ob_get_clean();
nativeViewAssert(
    $moduleOutput === 'module:&lt;owned&gt;',
    'native renderer did not resolve an active isolated module view namespace'
);
nativeViewAssert($native->hasTemplate('@notes/page/index'), 'registered module template was not discoverable');
nativeViewAssert(!$native->hasTemplate('@files/page/index'), 'unregistered module view root became visible');

$unknownModuleBlocked = false;
try {
    $native->render('@files/page/index');
} catch (RuntimeException) {
    $unknownModuleBlocked = true;
}
nativeViewAssert($unknownModuleBlocked, 'native renderer accepted an inactive module namespace');

$moduleTraversalBlocked = false;
try {
    $native->render('@notes/../auth/native');
} catch (RuntimeException) {
    $moduleTraversalBlocked = true;
}
nativeViewAssert($moduleTraversalBlocked, 'native module view namespace accepted path traversal');

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
nativeViewAssert(str_contains($controllerSource, 'ModuleRuntimeLoader::getInstance()->viewRoots()'), 'base Controller does not expose active isolated module view roots');
nativeViewAssert(!str_contains($controllerSource, 'HybridViewRenderer'), 'base Controller still uses hybrid renderer fallback');
nativeViewAssert(!str_contains($controllerSource, 'LegacySmartyRenderer'), 'base Controller still references legacy Smarty renderer');

$coreSource = file_get_contents(__DIR__ . '/../../core.php') ?: '';
nativeViewAssert(!str_contains($coreSource, 'LegacySmartyRenderer.php'), 'core bootstrap still loads LegacySmartyRenderer');
nativeViewAssert(!str_contains($coreSource, 'HybridViewRenderer.php'), 'core bootstrap still loads HybridViewRenderer');
nativeViewAssert(!str_contains($coreSource, 'vendor/autoload.php'), 'core bootstrap still depends on Composer vendor autoload');
nativeViewAssert(!is_file(__DIR__ . '/../../core/LegacySmartyRenderer.php'), 'LegacySmartyRenderer file still exists');
nativeViewAssert(!is_file(__DIR__ . '/../../core/HybridViewRenderer.php'), 'HybridViewRenderer file still exists');
nativeViewAssert($appNative->hasTemplate('error_page/index'), 'native error view is missing');

$composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json') ?: '', true);
nativeViewAssert(is_array($composer), 'composer.json is not valid JSON');
nativeViewAssert(!isset($composer['require']['smarty/smarty']), 'composer.json still requires smarty/smarty');
nativeViewAssert(!isset($composer['require']['workerman/workerman']), 'composer.json still requires workerman/workerman');
nativeViewAssert(array_keys((array) ($composer['require'] ?? [])) === ['php'], 'runtime Composer requirements contain a package other than PHP');

$lock = json_decode(file_get_contents(__DIR__ . '/../../composer.lock') ?: '', true);
nativeViewAssert(is_array($lock), 'composer.lock is not valid JSON');
nativeViewAssert(($lock['packages'] ?? null) === [], 'composer.lock still contains runtime packages');
nativeViewAssert(($lock['packages-dev'] ?? null) === [], 'composer.lock still contains dev packages');

@unlink($globalRoot . '/auth/native.php');
@unlink($notesRoot . '/page/index.php');
@rmdir($globalRoot . '/auth');
@rmdir($globalRoot);
@rmdir($notesRoot . '/page');
@rmdir($notesRoot);
@rmdir($root . '/modules/notes');
@rmdir($root . '/modules');
@rmdir($root);

echo "[OK] vendor-free native view runtime and module namespace contract\n";
