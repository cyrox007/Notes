<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tools/license-server/LicenseServer.php';

/** Exercise the real front controller over HTTP; only the fixture router simulates trusted TLS termination. */
function httpAccessAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function httpAccessRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $item) {
            httpAccessRemove($path . '/' . $item);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

$root = dirname(__DIR__, 2);
$work = sys_get_temp_dir() . '/notes-license-http-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$process = null;
try {
    // Isolated public trust fixture; never edits the release's public registries.
    foreach (['tools/license-server/LicenseServer.php', 'tools/license-server/public/index.php',
        'tools/license-server/manage.php', 'app/services/LicenseVerifier.php', 'core/UpdateManifestVerifier.php',
        'core/UpdateDownloadCredentials.php', 'core/PrivateStorageResolver.php', 'core/UpdatePath.php', 'core/Version.php'] as $file) {
        $destination = $work . '/app/' . $file;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0700, true);
        }
        copy($root . '/' . $file, $destination);
    }
    mkdir($work . '/app/config', 0700);
    $pair = sodium_crypto_sign_keypair();
    $updatePair = sodium_crypto_sign_keypair();
    $encode = static fn (string $value): string => \App\Services\LicenseVerifier::base64UrlEncode($value);
    $licenseKeys = ['http-test' => $encode(sodium_crypto_sign_publickey($pair))];
    $updateKeys = ['http-test' => $encode(sodium_crypto_sign_publickey($updatePair))];
    file_put_contents($work . '/app/config/license_trusted_keys.php', '<?php return ' . var_export($licenseKeys, true) . ';');
    file_put_contents($work . '/app/config/update_trusted_keys.php', '<?php return ' . var_export($updateKeys, true) . ';');
    $server = new \NotesVendor\LicenseServer($work . '/registry.sqlite', new \App\Services\LicenseVerifier($licenseKeys),
        new \Core\UpdateManifestVerifier($updateKeys), true);
    $installation = '12345678-1234-4234-8234-123456789012';
    $payload = ['v' => 1, 'license_id' => 'http-test', 'installation_id' => $installation,
        'issued_at' => time() - 10, 'expires_at' => null, 'edition' => 'standard'];
    $bytes = 'wo1.http-test.' . $encode(json_encode($payload));
    $token = $bytes . '.' . $encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($pair)));
    $activation = $server->register($installation, $token, null, null);
    // Exact transport framing is tested with binary bytes, independent of ZIP semantics (covered by the other contract).
    $package = "PK\x03\x04\0private-package\xff";
    file_put_contents($work . '/notes.zip', $package);
    $manifest = ['schema' => 1, 'product' => 'workspace-organizer', 'version' => '1.0.1', 'version_code' => 10001,
        'channel' => 'stable', 'issued_at' => time(), 'source_commit' => str_repeat('a', 40),
        'min_source_version_code' => 10000, 'requires_php' => '8.1.0',
        'package' => ['filename' => 'notes.zip', 'sha256' => hash('sha256', $package), 'size' => strlen($package), 'format' => 'zip']];
    $manifestBytes = json_encode($manifest);
    file_put_contents($work . '/manifest.json', $manifestBytes);
    file_put_contents($work . '/manifest.sig', 'wou1.http-test.' . $encode(sodium_crypto_sign_detached(
        \Core\UpdateManifestVerifier::DOMAIN . $manifestBytes, sodium_crypto_sign_secretkey($updatePair))));
    $server->publish($work . '/manifest.json', $work . '/manifest.sig', $work . '/notes.zip');

    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    httpAccessAssert(is_resource($listener), 'Reserve local test port');
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $router = $work . '/router.php';
    file_put_contents($router, '<?php $_SERVER["HTTPS"] = isset($_SERVER["HTTP_X_TEST_PLAIN_HTTP"]) ? "" : "on"; require '
        . var_export($work . '/app/tools/license-server/public/index.php', true) . ';');
    $environment = array_merge(getenv(), ['LICENSE_SERVER_DB' => $work . '/registry.sqlite',
        'LICENSE_SERVER_BASE_URL' => 'https://updates.example.com/delivery/']);
    $process = proc_open([PHP_BINARY, '-S', $address, '-t', $work . '/app/tools/license-server/public', $router],
        [0 => ['pipe', 'r'], 1 => ['file', $work . '/server.log', 'a'], 2 => ['file', $work . '/server.log', 'a']], $pipes, null, $environment);
    httpAccessAssert(is_resource($process), 'Start fixture server');
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(20000);
    }
    httpAccessAssert($ready, 'Fixture server ready');
    $request = static function (string $path, ?string $body = null, array $headers = []) use ($address): array {
        $context = stream_context_create(['http' => ['method' => $body === null ? 'GET' : 'POST',
            'header' => implode("\r\n", array_merge(['Content-Type: application/json'], $headers)),
            'content' => $body ?? '', 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5]]);
        $response = file_get_contents('http://' . $address . $path, false, $context);
        $responseHeaders = $http_response_header;
        preg_match('~HTTP/\S+ (\d+)~', $responseHeaders[0], $status);
        $flat = strtolower(implode("\n", $responseHeaders));
        $cacheDirectives = [];
        foreach ($responseHeaders as $headerLine) {
            if (stripos((string) $headerLine, 'Cache-Control:') !== 0) {
                continue;
            }
            $cacheValue = trim(substr((string) $headerLine, strlen('Cache-Control:')));
            foreach (explode(',', $cacheValue) as $directive) {
                $directive = trim(strtolower($directive));
                if ($directive !== '') {
                    $cacheDirectives[$directive] = true;
                }
            }
        }
        httpAccessAssert(
            isset($cacheDirectives['no-store'], $cacheDirectives['private']),
            'Приватные артефакты должны запрещать кеширование: ' . implode(' | ', $responseHeaders)
        );
        preg_match('/content-length: (\d+)/', $flat, $length);
        httpAccessAssert(isset($length[1]) && (int) $length[1] === strlen($response), 'Exact Content-Length');
        return [(int) $status[1], $response];
    };
    [$status, $response] = $request('/delivery/health');
    $health = json_decode($response, true);
    httpAccessAssert($status === 200 && ($health['status'] ?? '') === 'ok', 'Public health endpoint reports ready service');
    httpAccessAssert(array_keys($health) === ['status', 'registry', 'license_trust', 'update_trust'], 'Health endpoint exposes unexpected metadata');

    [$status] = $request('/delivery/stable/notes.zip');
    httpAccessAssert($status === 401, 'Direct unauthenticated ZIP denied');
    [$status] = $request('/delivery/stable/feed.json', null, ['X-Test-Plain-HTTP: 1']);
    httpAccessAssert($status === 400, 'Plain HTTP denied');
    [$status] = $request('/delivery/activate', '{bad');
    httpAccessAssert($status === 400, 'Malformed activation denied');
    [$status] = $request('/delivery/activate', str_repeat('x', 1025));
    httpAccessAssert($status === 413, 'Bound activation body');
    $body = json_encode(['installation_id' => $installation, 'activation_code' => $activation]);
    [$status, $response] = $request('/delivery/activate', $body);
    httpAccessAssert($status === 200, 'HTTP activation succeeds');
    $access = json_decode($response, true);
    httpAccessAssert(($access['base_url'] ?? '') === 'https://updates.example.com/delivery/', 'Bind server scope');
    $headers = ['Authorization: Bearer ' . $access['token'], 'X-Notes-Installation: ' . $installation];
    [$status] = $request('/delivery/activate', $body);
    httpAccessAssert($status === 401, 'HTTP activation cannot be replayed');
    [$status, $response] = $request('/delivery/stable/feed.json', null, $headers);
    httpAccessAssert($status === 200 && json_decode($response, true)['manifest'] === 'release-10001.json', 'Authenticated feed');
    [$status, $response] = $request('/delivery/stable/notes.zip', null, $headers);
    httpAccessAssert($status === 200 && $response === $package, 'Stream exact private package bytes');
    [$status] = $request('/delivery/stable/notes.zip?bypass=1', null, $headers);
    httpAccessAssert($status === 404, 'No query bypass');
    [$status] = $request('/registry.sqlite', null, $headers);
    httpAccessAssert($status === 404, 'Registry is never an HTTP asset');
    $server->setStatus($installation, 'revoked');
    [$status, $response] = $request('/delivery/stable/notes.zip', null, $headers);
    $denied = json_decode($response, true);
    httpAccessAssert($status === 403, 'HTTP download observes revocation immediately');
    httpAccessAssert(
        ($denied['reason'] ?? '') === 'license_revoked',
        'HTTP API не вернул безопасную причину отзыва лицензии'
    );

    $server->setStatus($installation, 'active');
    $registry = new PDO('sqlite:' . $work . '/registry.sqlite');
    $registry->exec('UPDATE licenses SET updates_until=' . (time() - 1) . ' WHERE installation_id=' . $registry->quote($installation));
    [$status, $response] = $request('/delivery/stable/notes.zip', null, $headers);
    $denied = json_decode($response, true);
    httpAccessAssert($status === 403, 'HTTP download observes updates_until immediately');
    httpAccessAssert(
        ($denied['reason'] ?? '') === 'updates_expired',
        'HTTP API не вернул безопасную причину истечения updates_until'
    );
    $registry->exec('UPDATE licenses SET updates_until=NULL WHERE installation_id=' . $registry->quote($installation));
    httpAccessAssert(!str_contains(file_get_contents($work . '/server.log'), $access['token'])
        && !str_contains(file_get_contents($work . '/server.log'), $activation), 'HTTP logs contain no access secrets');
    echo "[OK] Online update HTTP: activation, replay, framing, private ZIP, revocation, TLS requirement, malformed/oversized requests, no secret logs\n";
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    httpAccessRemove($work);
}
