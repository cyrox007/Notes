<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/request.php';
require_once $root . '/core/ViewRenderer.php';
require_once $root . '/core/ViewContext.php';
require_once $root . '/core/Version.php';
require_once $root . '/core/controller.php';

use Core\Controller;
use Core\Request;
use Core\ViewContext;
use Core\ViewRenderer;

final class ProtocolAssetCaptureRenderer implements ViewRenderer
{
    /** @var array<string,mixed> */
    public array $data = [];

    public function render(string $template, array $data = []): void
    {
        $this->data = $data;
    }
}

final class ProtocolAssetController extends Controller
{
    public function __construct(ProtocolAssetCaptureRenderer $renderer)
    {
        $this->request = new Request();
        $this->viewContext = new ViewContext($this->request);
        $this->renderer = $renderer;
    }

    public function __destruct()
    {
        // Parent owns an output buffer only when its constructor ran.
    }

    public function capture(): void
    {
        $this->render_template('contract/noop');
    }
}

function protocolAssetAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$renderer = new ProtocolAssetCaptureRenderer();
$controller = new ProtocolAssetController($renderer);

putenv('SITEURL=https://workspace-organizer.local');
putenv('BASE_PATH=/');
$controller->capture();
protocolAssetAssert(($renderer->data['base_url'] ?? null) === '', 'root base_url must not contain HTTPS SITEURL');
protocolAssetAssert(($renderer->data['base_path'] ?? null) === '', 'root base_path must be empty prefix');

putenv('SITEURL=https://canonical.example.test');
putenv('BASE_PATH=/workspace/');
$controller->capture();
protocolAssetAssert(($renderer->data['base_url'] ?? null) === '/workspace', 'subdirectory base_url must be path-only');
protocolAssetAssert(($renderer->data['base_path'] ?? null) === '/workspace', 'subdirectory base_path drifted');

putenv('SITEURL=http://different-scheme.example.test');
putenv('BASE_PATH=/workspace/');
$controller->capture();
protocolAssetAssert(($renderer->data['base_url'] ?? null) === '/workspace', 'changing SITEURL scheme/host changed asset prefix');

$baseSource = file_get_contents($root . '/app/views/core/base.php');
protocolAssetAssert(is_string($baseSource), 'unable to read native base layout');
protocolAssetAssert(
    str_contains($baseSource, '$view->e($baseUrl)') && str_contains($baseSource, '/assets/font-awesome/css/font-awesome.min.css'),
    'base layout no longer uses the shared scheme-neutral asset prefix'
);

putenv('SITEURL');
putenv('BASE_PATH');

echo "[OK] protocol-independent native asset path contract\n";
