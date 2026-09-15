<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}

putenv('SITEURL=https://example.test');
putenv('BASE_PATH=/workspace');
putenv('SESSION_LIFETIME_SECONDS=2592000');
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'on';

require_once $root . '/core/config.php';
require_once $root . '/core/SessionSecurity.php';
require_once $root . '/core/RedirectPolicy.php';
require_once $root . '/core/Router.php';

use Core\RedirectPolicy;
use Core\Router;
use Core\SessionSecurity;

function failSecurityContract(string $message): never
{
    fwrite(STDERR, "Router/session security contract failed: {$message}\n");
    exit(1);
}

function expectInvalid(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (InvalidArgumentException|RuntimeException $e) {
        return;
    }

    failSecurityContract("expected rejection: {$label}");
}

SessionSecurity::configure();
if (!SessionSecurity::isConfigured()) {
    failSecurityContract('session security was not marked configured');
}

$params = session_get_cookie_params();
if (($params['secure'] ?? null) !== true) {
    failSecurityContract('HTTPS session cookie must be Secure');
}
if (($params['httponly'] ?? null) !== true) {
    failSecurityContract('session cookie must be HttpOnly');
}
if (($params['samesite'] ?? null) !== 'Lax') {
    failSecurityContract('session cookie must use SameSite=Lax');
}
if (($params['path'] ?? null) !== '/workspace/') {
    failSecurityContract('session cookie path must follow BASE_PATH');
}
if ((int) ($params['lifetime'] ?? 0) !== 2592000) {
    failSecurityContract('session cookie must persist for the configured lifetime');
}
if (SessionSecurity::lifetimeSeconds() !== 2592000) {
    failSecurityContract('session lifetime service value is inconsistent');
}
if ((int) ini_get('session.gc_maxlifetime') < 2592000) {
    failSecurityContract('server session lifetime must not expire before the persistent cookie');
}
if (ini_get('session.use_strict_mode') !== '1') {
    failSecurityContract('session.use_strict_mode must be enabled');
}
if (ini_get('session.use_only_cookies') !== '1') {
    failSecurityContract('session.use_only_cookies must be enabled');
}
if (ini_get('session.use_trans_sid') !== '0') {
    failSecurityContract('session.use_trans_sid must be disabled');
}

$local = RedirectPolicy::resolveLocal('/workspace/notes/?q=1#top', 'https://example.test');
if ($local !== '/workspace/notes/?q=1#top') {
    failSecurityContract('local redirect path changed unexpectedly');
}

$sameOrigin = RedirectPolicy::resolveLocal('https://example.test/workspace/tasks/?view=board', 'https://example.test');
if ($sameOrigin !== '/workspace/tasks/?view=board') {
    failSecurityContract('same-origin absolute URL must collapse to a local path');
}

expectInvalid(
    static fn () => RedirectPolicy::resolveLocal('https://evil.example/phish', 'https://example.test'),
    'external host'
);
expectInvalid(
    static fn () => RedirectPolicy::resolveLocal('//evil.example/phish', 'https://example.test'),
    'protocol-relative host'
);
expectInvalid(
    static fn () => RedirectPolicy::resolveLocal('http://example.test/workspace/', 'https://example.test'),
    'scheme downgrade'
);
expectInvalid(
    static fn () => RedirectPolicy::resolveLocal('/workspace/../admin/', 'https://example.test'),
    'path traversal'
);
expectInvalid(
    static fn () => RedirectPolicy::resolveLocal("/workspace/notes/\r\nX-Test: injected", 'https://example.test'),
    'header injection'
);

$router = Router::getInstance();
$routerReflection = new ReflectionClass($router);

$patternMethod = $routerReflection->getMethod('createPattern');
$pattern = $patternMethod->invoke($router, '/v1/file.name/{int:id}/{str:uid}/');
if (!is_string($pattern)) {
    failSecurityContract('route pattern must be a string');
}
if (preg_match($pattern, '/v1/file.name/42/abc_DEF-9/', $matches) !== 1) {
    failSecurityContract('valid typed route did not match');
}
if (preg_match($pattern, '/v1/fileXname/42/abc/', $matches) === 1) {
    failSecurityContract('literal route metacharacter was not escaped');
}
if (preg_match($pattern, '/v1/file.name/not-int/abc/', $matches) === 1) {
    failSecurityContract('int route parameter accepted non-numeric input');
}
if (preg_match($pattern, '/v1/file.name/42/a/b/', $matches) === 1) {
    failSecurityContract('str route parameter crossed a path boundary');
}

$buildMethod = $routerReflection->getMethod('buildUrlFromRoute');
$built = $buildMethod->invoke($router, '/notes/{str:uid}/attachment/{int:id}/', [
    'uid' => 'abc_DEF-9',
    'id' => 17,
]);
if ($built !== '/notes/abc_DEF-9/attachment/17/') {
    failSecurityContract('named route URL builder returned an unexpected path');
}

expectInvalid(
    static fn () => $buildMethod->invoke($router, '/notes/{str:uid}/', ['uid' => '../admin']),
    'unsafe string route parameter'
);
expectInvalid(
    static fn () => $buildMethod->invoke($router, '/files/{int:id}/', ['id' => '1x']),
    'unsafe integer route parameter'
);
expectInvalid(
    static fn () => $buildMethod->invoke($router, '/files/{int:id}/', []),
    'missing named route parameter'
);

fwrite(STDOUT, "Router/session security contract: OK\n");
