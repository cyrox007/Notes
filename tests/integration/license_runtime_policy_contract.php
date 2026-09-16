<?php

declare(strict_types=1);

use App\Middlewares\EnforceLicenseMutation;
use App\Services\LicenseRuntimePolicy;
use Core\Request;

$root = dirname(__DIR__, 2);
require_once $root . '/core/request.php';
require_once $root . '/app/services/MaintenanceModeService.php';
require_once $root . '/app/services/LicenseRuntimePolicy.php';
require_once $root . '/app/middlewares/EnforceLicenseMutation.php';

function runtimeLicenseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function requestFor(string $method, string $uri, string $accept = 'text/html'): Request
{
    $_GET = [];
    $_POST = [];
    $_FILES = [];
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['HTTP_ACCEPT'] = $accept;
    unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    return new Request();
}

$disabled = new LicenseRuntimePolicy(
    static fn (): bool => false,
    static fn (): array => ['valid' => false, 'code' => 'unlicensed', 'message' => 'missing']
);
$state = $disabled->state();
runtimeLicenseAssert(!$state['enforced'] && $state['writable'], 'build without trust root must not enforce licensing');

$valid = new LicenseRuntimePolicy(
    static fn (): bool => true,
    static fn (): array => ['valid' => true, 'code' => 'valid', 'message' => 'ok']
);
runtimeLicenseAssert($valid->canMutate(), 'valid enforced license must allow mutations');

$invalid = new LicenseRuntimePolicy(
    static fn (): bool => true,
    static fn (): array => ['valid' => false, 'code' => 'expired', 'message' => 'expired']
);
$state = $invalid->state();
runtimeLicenseAssert($state['enforced'] && !$state['writable'] && $state['code'] === 'expired', 'invalid enforced license must be read-only');

$failed = new LicenseRuntimePolicy(
    static fn (): bool => true,
    static function (): array { throw new RuntimeException('database unavailable'); }
);
$state = $failed->state();
runtimeLicenseAssert($state['enforced'] && !$state['writable'] && $state['code'] === 'license_check_failed', 'license status failure must fail closed for mutations');

putenv('BASE_PATH=/workspace/');
$guard = new EnforceLicenseMutation($invalid);
runtimeLicenseAssert($guard->handle(requestFor('GET', '/workspace/notes/')), 'GET must remain available in read-only mode');
runtimeLicenseAssert($guard->handle(requestFor('HEAD', '/workspace/files/')), 'HEAD must remain available in read-only mode');
runtimeLicenseAssert($guard->handle(requestFor('OPTIONS', '/workspace/tasks/')), 'OPTIONS must remain available in read-only mode');
runtimeLicenseAssert($guard->handle(requestFor('POST', '/workspace/auth/login')), 'login recovery path must remain available');
runtimeLicenseAssert($guard->handle(requestFor('POST', '/workspace/auth/logout')), 'logout recovery path must remain available');
runtimeLicenseAssert($guard->handle(requestFor('POST', '/workspace/admin/license/activate')), 'license activation must remain available');
runtimeLicenseAssert($guard->handle(requestFor('POST', '/workspace/admin/license/clear')), 'license clear/recovery must remain available');
runtimeLicenseAssert($guard->handle(requestFor('POST', '/workspace/messenger/socket-ticket')), 'socket-ticket refresh must remain available for read-only Messenger');

ob_start();
$allowed = $guard->handle(requestFor('POST', '/workspace/notes/', 'application/json'));
$body = (string) ob_get_clean();
runtimeLicenseAssert(!$allowed, 'ordinary mutation must be blocked in read-only mode');
$decoded = json_decode($body, true);
runtimeLicenseAssert(is_array($decoded) && ($decoded['error'] ?? '') === 'license_read_only', 'blocked JSON mutation must return structured license error');
runtimeLicenseAssert(($decoded['code'] ?? '') === 'expired', 'blocked response must preserve license state code');
runtimeLicenseAssert(($decoded['license_url'] ?? '') === '/workspace/admin/license', 'license recovery URL must honor BASE_PATH');

$validGuard = new EnforceLicenseMutation($valid);
runtimeLicenseAssert($validGuard->handle(requestFor('POST', '/workspace/tasks/')), 'valid license must allow ordinary mutation');

$disabledGuard = new EnforceLicenseMutation($disabled);
runtimeLicenseAssert($disabledGuard->handle(requestFor('DELETE', '/workspace/tasks/abc/category/1')), 'unconfigured trust root must leave runtime unrestricted');

$routerSource = file_get_contents($root . '/core/Router.php');
runtimeLicenseAssert(is_string($routerSource), 'Router source must be readable');
$globalDispatch = strpos($routerSource, 'executeMiddlewares($this->globalMiddlewares, $request)');
$routeDispatch = strpos($routerSource, "executeMiddlewares(\$route['middlewares'], \$request)");
runtimeLicenseAssert($globalDispatch !== false, 'Router must execute global middleware');
runtimeLicenseAssert($routeDispatch !== false, 'Router must execute route middleware');
runtimeLicenseAssert($globalDispatch < $routeDispatch, 'global middleware must run before route-specific middleware');

$routerConfig = file_get_contents($root . '/core/routerConfig.php');
runtimeLicenseAssert(is_string($routerConfig), 'routerConfig source must be readable');
runtimeLicenseAssert(str_contains($routerConfig, 'use App\\Middlewares\\EnforceLicenseMutation;'), 'routerConfig must import the global license guard');
runtimeLicenseAssert(str_contains($routerConfig, '$router->addGlobalMiddleware(EnforceLicenseMutation::class);'), 'routerConfig must register the global license guard');

$baseView = file_get_contents($root . '/app/views/core/base.php');
runtimeLicenseAssert(is_string($baseView), 'base view source must be readable');
runtimeLicenseAssert(str_contains($baseView, 'license-readonly-banner'), 'native layout must expose a read-only banner');
runtimeLicenseAssert(str_contains($baseView, '$canManageLicense'), 'license management action must be permission-aware');

putenv('BASE_PATH');
echo "[OK] recovery-safe license runtime policy contract\n";
