<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}

putenv('SITEURL=https://example.test');
putenv('BASE_PATH=/workspace');
putenv('SESSION_LIFETIME_SECONDS=2592000');
putenv('MAX_JSON_BODY_BYTES=1048576');
putenv('TRUSTED_PROXY_IPS=');
$_SERVER['HTTP_HOST'] = 'example.test';
$_SERVER['HTTPS'] = 'on';

require_once $root . '/core/config.php';
require_once $root . '/core/RequestOrigin.php';
require_once $root . '/core/SessionSecurity.php';
require_once $root . '/core/RedirectPolicy.php';
require_once $root . '/core/request.php';
require_once $root . '/core/Router.php';

use Core\RedirectPolicy;
use Core\Request;
use Core\RequestOrigin;
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

// SITEURL is canonical metadata, not proof of the transport used by this request.
// Reproduce the Beta4 mismatch directly: an HTTP request must remain HTTP even if
// the configured canonical URL is HTTPS, and spoofed proxy headers must not win.
$directHttp = [
    'REMOTE_ADDR' => '198.51.100.40',
    'REQUEST_SCHEME' => 'http',
    'SERVER_PORT' => '80',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
];
if (RequestOrigin::isSecure($directHttp, 'https://example.test')) {
    failSecurityContract('HTTP request inherited HTTPS from SITEURL or an untrusted proxy header');
}
if (RequestOrigin::clientIp($directHttp) !== '198.51.100.40') {
    failSecurityContract('untrusted proxy headers changed the direct client address');
}

$directHttps = [
    'REMOTE_ADDR' => '198.51.100.40',
    'HTTPS' => 'on',
    'REQUEST_SCHEME' => 'https',
    'SERVER_PORT' => '443',
];
if (!RequestOrigin::isSecure($directHttps, 'http://example.test')) {
    failSecurityContract('direct HTTPS request inherited insecure SITEURL scheme');
}

putenv('TRUSTED_PROXY_IPS=10.0.0.2');
$trustedProxy = [
    'REMOTE_ADDR' => '10.0.0.2',
    'REQUEST_SCHEME' => 'http',
    'SERVER_PORT' => '8080',
    'HTTP_X_FORWARDED_PROTO' => 'https',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.77, 10.0.0.2',
];
if (!RequestOrigin::isSecure($trustedProxy, 'http://example.test')) {
    failSecurityContract('trusted reverse proxy HTTPS transport was ignored');
}
if (RequestOrigin::clientIp($trustedProxy) !== '203.0.113.77') {
    failSecurityContract('trusted reverse proxy client address was not resolved');
}

$trustedProxyHttp = $trustedProxy;
$trustedProxyHttp['HTTP_X_FORWARDED_PROTO'] = 'http';
if (RequestOrigin::isSecure($trustedProxyHttp, 'https://example.test')) {
    failSecurityContract('trusted reverse proxy HTTP request inherited HTTPS from SITEURL');
}
putenv('TRUSTED_PROXY_IPS=');

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

expectInvalid(
    static fn () => $patternMethod->invoke($router, '/duplicate/{int:id}/{str:id}/'),
    'duplicate route parameter name'
);

$clearParams = $routerReflection->getMethod('clearParams');
$typedPattern = $patternMethod->invoke($router, '/typed/{str:uid}/{int:id}/');
if (!is_string($typedPattern) || preg_match($typedPattern, '/typed/123/456/', $typedMatches) !== 1) {
    failSecurityContract('typed conversion fixture did not match');
}
$typed = $clearParams->invoke($router, $typedMatches, '/typed/{str:uid}/{int:id}/');
if (($typed['uid'] ?? null) !== '123' || !is_string($typed['uid'] ?? null)) {
    failSecurityContract('numeric string route parameter must remain a string');
}
if (($typed['id'] ?? null) !== 456 || !is_int($typed['id'] ?? null)) {
    failSecurityContract('integer route parameter must be converted to int');
}

expectInvalid(
    static fn () => $router->add('TRACE', '/trace-contract', [stdClass::class, 'x']),
    'unsupported route method'
);
expectInvalid(
    static fn () => $router->add('GET', '/invalid-controller', [stdClass::class]),
    'invalid controller tuple'
);

$router->add('GET', '/contract-route/{str:uid}', [stdClass::class, 'first'], [], 'contract.route');
expectInvalid(
    static fn () => $router->add('GET', '/contract-route/{str:uid}', [stdClass::class, 'second']),
    'duplicate method/path route'
);
expectInvalid(
    static fn () => $router->add('POST', '/other-contract-route', [stdClass::class, 'second'], [], 'contract.route'),
    'duplicate route name'
);

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

$requestReflection = new ReflectionClass(Request::class);
$request = $requestReflection->newInstanceWithoutConstructor();
$decodeJson = $requestReflection->getMethod('decodeJsonBody');

$decodeJson->invoke($request, '{"message":"<b>ok</b>"}', 1024);
if ($request->hasInvalidJson() || $request->json('message') !== '&lt;b&gt;ok&lt;/b&gt;') {
    failSecurityContract('valid JSON body did not decode/sanitize correctly');
}

$decodeJson->invoke($request, '{broken', 1024);
if (!$request->hasInvalidJson() || $request->jsonError() !== 'json_body_invalid') {
    failSecurityContract('malformed JSON body was not rejected');
}

$decodeJson->invoke($request, '', 1024);
if (!$request->hasInvalidJson() || $request->jsonError() !== 'json_body_empty') {
    failSecurityContract('empty JSON body was not rejected');
}

$decodeJson->invoke($request, str_repeat('x', 1025), 1024);
if (!$request->hasInvalidJson() || $request->jsonError() !== 'json_body_too_large') {
    failSecurityContract('oversized JSON body was not rejected');
}

$expectsJson = $requestReflection->getMethod('expectsJsonBody');
$_SERVER['CONTENT_TYPE'] = 'application/problem+json; charset=utf-8';
if ($expectsJson->invoke($request) !== true) {
    failSecurityContract('+json media type was not recognized');
}
$_SERVER['CONTENT_TYPE'] = 'text/plain';
if ($expectsJson->invoke($request) !== false) {
    failSecurityContract('non-JSON media type was incorrectly treated as JSON');
}
unset($_SERVER['CONTENT_TYPE']);

putenv('MAX_JSON_BODY_BYTES=10');
if (Request::maxJsonBodyBytes() !== 1024) {
    failSecurityContract('JSON body limit did not enforce minimum safety bound');
}
putenv('MAX_JSON_BODY_BYTES=999999999');
if (Request::maxJsonBodyBytes() !== 10485760) {
    failSecurityContract('JSON body limit did not enforce maximum safety bound');
}
putenv('MAX_JSON_BODY_BYTES=1048576');

fwrite(STDOUT, "Router/session security contract: OK\n");
