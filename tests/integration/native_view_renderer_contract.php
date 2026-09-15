<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/request.php';
require_once __DIR__ . '/../../core/ViewRenderer.php';
require_once __DIR__ . '/../../core/ViewContext.php';
require_once __DIR__ . '/../../core/NativeViewRenderer.php';
require_once __DIR__ . '/../../core/HybridViewRenderer.php';

use Core\HybridViewRenderer;
use Core\NativeViewRenderer;
use Core\Request;
use Core\ViewContext;
use Core\ViewRenderer;

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
$legacyCalls = 0;
$hybrid = new HybridViewRenderer(
    $native,
    static function () use (&$legacyCalls): ViewRenderer {
        $legacyCalls++;
        return new class implements ViewRenderer {
            public function render(string $template, array $data = []): void
            {
                echo '[legacy:' . $template . ']';
            }
        };
    }
);

ob_start();
$hybrid->render('auth/native', ['dangerous' => '<script>alert("x")</script>']);
$nativeOutput = (string) ob_get_clean();
nativeViewAssert(
    $nativeOutput === '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;',
    'native renderer did not escape HTML by default helper'
);
nativeViewAssert($legacyCalls === 0, 'legacy renderer was constructed for a native template');

ob_start();
$hybrid->render('auth/legacy-only');
$legacyOutput = (string) ob_get_clean();
nativeViewAssert($legacyOutput === '[legacy:auth/legacy-only]', 'legacy fallback did not render');
nativeViewAssert($legacyCalls === 1, 'legacy renderer factory was not lazy/single-use');

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
nativeViewAssert($appNative->hasTemplate('login_page/login_layout'), 'native login layout missing');
nativeViewAssert($appNative->hasTemplate('login_page/login_view'), 'native login view missing');
nativeViewAssert($appNative->hasTemplate('login_page/register_view'), 'native registration view missing');

$controllerSource = file_get_contents(__DIR__ . '/../../core/controller.php') ?: '';
nativeViewAssert(!str_contains($controllerSource, 'Smarty\\'), 'Controller still imports/references Smarty');
nativeViewAssert(!str_contains($controllerSource, '$this->smarty'), 'Controller still owns a Smarty instance');

@unlink($root . '/auth/native.php');
@rmdir($root . '/auth');
@rmdir($root);

echo "[OK] native view renderer and legacy fallback contract\n";
