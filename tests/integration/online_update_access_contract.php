<?php

declare(strict_types=1);

use App\Services\LicenseVerifier;
use Core\UpdateDownloadCredentials;
use Core\UpdateManifestVerifier;
use Core\UpdateRemoteDelivery;
use Core\UpdateRemoteTransport;
use NotesVendor\LicenseServer;

$root = dirname(__DIR__, 2);
require_once $root . '/tools/license-server/LicenseServer.php';
require_once $root . '/core/UpdateRemoteTransport.php';
require_once $root . '/core/UpdateRemoteDelivery.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';

function accessAssert(bool $value, string $message): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function accessDenied(callable $action, int $code = 0): void
{
    try {
        $action();
    } catch (Throwable $e) {
        accessAssert($code === 0 || $e->getCode() === $code, 'Unexpected denial: ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('Expected denial');
}

function accessRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $item) {
            accessRemove($path . '/' . $item);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}

$work = sys_get_temp_dir() . '/notes-online-access-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$licensePair = sodium_crypto_sign_keypair();
$updatePair = sodium_crypto_sign_keypair();
$encode = static fn (string $bytes): string => LicenseVerifier::base64UrlEncode($bytes);
$installation = '12345678-1234-4234-8234-123456789012';
$other = '12345678-1234-4234-8234-123456789013';
$base = 'https://updates.example.com/delivery/';
$makeLicense = static function (string $id, string $pair, ?int $expires = null) use ($encode): string {
    $payload = ['v' => 1, 'license_id' => 'license-' . $id, 'installation_id' => $id,
        'issued_at' => time() - 3600, 'expires_at' => $expires, 'edition' => 'standard'];
    $bytes = 'wo1.test.' . $encode(json_encode($payload, JSON_THROW_ON_ERROR));
    return $bytes . '.' . $encode(sodium_crypto_sign_detached($bytes, sodium_crypto_sign_secretkey($pair)));
};

try {
    $licenses = new LicenseVerifier(['test' => $encode(sodium_crypto_sign_publickey($licensePair))]);
    $updates = new UpdateManifestVerifier(['test' => $encode(sodium_crypto_sign_publickey($updatePair))]);
    $server = new LicenseServer($work . '/registry.sqlite', $licenses, $updates, true);
    $token = $makeLicense($installation, $licensePair);
    accessDenied(fn () => $server->register($installation, $makeLicense($installation, sodium_crypto_sign_keypair()), null, null), 403);
    accessDenied(fn () => $server->register($other, $token, null, null), 403);
    accessDenied(fn () => $server->register($installation, $makeLicense($installation, $licensePair, time() - 1000), null, null), 403);
    $activation = $server->register($installation, $token, null, 10001);
    accessDenied(fn () => $server->activate($installation, str_repeat('0', 64)), 401);
    accessDenied(fn () => $server->activate($other, $activation), 401);
    $credential = $server->activate($installation, $activation);
    accessDenied(fn () => $server->activate($installation, $activation), 401);
    $secret = $credential['token'];
    accessDenied(fn () => $server->authorize($other, $secret), 401);
    $server->authorize($installation, $secret);
    $rawRegistry = file_get_contents($work . '/registry.sqlite');
    accessAssert(!str_contains($rawRegistry, $secret) && !str_contains($rawRegistry, $activation), 'Registry must store only hashes of access secrets');

    // Two real ZIP releases, signed with a separate ephemeral update key.
    foreach ([10001, 10002] as $version) {
        $package = $work . '/notes-' . $version . '.zip';
        $zip = new ZipArchive();
        accessAssert($zip->open($package, ZipArchive::CREATE) === true, 'Create package');
        $zip->addFromString('workspace-organizer/core/Version.php', '<?php // fixture');
        $zip->close();
        $manifest = ['schema' => 1, 'product' => 'workspace-organizer', 'version' => '1.0.' . ($version - 10000),
            'version_code' => $version, 'channel' => 'stable', 'issued_at' => time(), 'source_commit' => str_repeat('a', 40),
            'min_source_version_code' => 10000, 'requires_php' => '8.1.0',
            'package' => ['filename' => basename($package), 'sha256' => hash_file('sha256', $package), 'size' => filesize($package), 'format' => 'zip']];
        $bytes = json_encode($manifest, JSON_THROW_ON_ERROR);
        $signature = 'wou1.test.' . $encode(sodium_crypto_sign_detached(UpdateManifestVerifier::DOMAIN . $bytes, sodium_crypto_sign_secretkey($updatePair)));
        file_put_contents($work . '/release.json', $bytes);
        file_put_contents($work . '/release.sig', $signature);
        $server->publish($work . '/release.json', $work . '/release.sig', $package);
    }
    $feed = json_decode($server->artifact($installation, $secret, 'stable', 'feed.json')['body'], true);
    accessAssert($feed['manifest'] === 'release-10001.json', 'Feed must honor version entitlement');
    accessDenied(fn () => $server->artifact($installation, $secret, 'stable', 'notes-10002.zip'), 403);
    accessDenied(fn () => $server->artifact($installation, $secret, 'stable', 'release-10002.json'), 403);
    accessDenied(fn () => $server->artifact($installation, $secret, 'stable', '../registry.sqlite'), 404);
    accessDenied(fn () => $server->artifact($installation, str_repeat('0', 64), 'stable', 'notes-10001.zip'), 401);

    $credential['base_url'] = $base;
    $scoped = new UpdateDownloadCredentials($credential);
    accessAssert(str_contains($scoped->headersFor($base . 'stable/feed.json'), 'Bearer ' . $secret), 'Credential headers missing');
    foreach (['https://evil.example/stable/feed.json', $base . '../stable/feed.json', $base . 'stable/%2e%2e/file',
        $base . 'stable/feed.json?token=x', $base . "stable/feed.json\r\nX-Test: bad"] as $url) {
        accessDenied(fn () => $scoped->headersFor($url));
    }
    file_put_contents($work . '/access.json', json_encode($credential));
    chmod($work . '/access.json', 0600);
    putenv('UPDATE_ACCESS_MODE=online');
    putenv('UPDATE_CREDENTIALS_FILE=' . $work . '/access.json');
    accessAssert(UpdateDownloadCredentials::fromEnvironment()->installationId() === $installation, 'Read private credential');
    putenv('UPDATE_CREDENTIALS_FILE=' . $root . '/default.env');
    accessDenied(fn () => UpdateDownloadCredentials::fromEnvironment());
    putenv('UPDATE_CREDENTIALS_FILE=' . $work . '/missing.json');
    accessDenied(fn () => UpdateDownloadCredentials::fromEnvironment());
    putenv('UPDATE_ACCESS_MODE=offline');
    accessAssert(UpdateDownloadCredentials::fromEnvironment() === null, 'Offline mode must not require service access');

    // Drive the existing updater with actual service responses; signature, hash and ZIP checks remain active.
    $transport = new class($server, $installation, $secret) implements UpdateRemoteTransport {
        public bool $revokeBeforeDownload = false;
        public function __construct(private LicenseServer $server, private string $installation, private string $secret) {}
        public function fetchText(string $url, int $maxBytes): string {
            $body = $this->server->artifact($this->installation, $this->secret, 'stable', basename($url))['body'];
            accessAssert(strlen($body) <= $maxBytes, 'Bound metadata');
            return $body;
        }
        public function downloadExact(string $url, string $destination, int $expectedBytes, string $expectedSha256): array {
            if ($this->revokeBeforeDownload) {
                $this->server->setStatus($this->installation, 'revoked');
            }
            $response = $this->server->artifact($this->installation, $this->secret, 'stable', basename($url));
            $body = stream_get_contents($response['stream']);
            fclose($response['stream']);
            accessAssert(strlen($body) === $expectedBytes && hash_equals($expectedSha256, hash('sha256', $body)), 'Exact signed bytes');
            file_put_contents($destination, $body);
            return ['bytes' => strlen($body), 'sha256' => hash('sha256', $body)];
        }
    };
    $delivery = new UpdateRemoteDelivery($root, $updates, $transport);
    $checked = $delivery->check($base . 'stable/feed.json', 'stable', 10000, PHP_VERSION);
    accessAssert($checked['update_available'], 'Licensed check must discover signed update');
    mkdir($work . '/stage', 0700);
    $staged = $delivery->stage($base . 'stable/feed.json', 'stable', $work . '/stage', 10000, PHP_VERSION);
    accessAssert($staged['status'] === 'staged', 'Licensed download must stage');
    $transport->revokeBeforeDownload = true;
    accessDenied(fn () => $delivery->stage($base . 'stable/feed.json', 'stable', $work . '/stage', 10000, PHP_VERSION), 403);
    accessAssert($licenses->verify($token, $installation)['valid'], 'Server revocation must not change the offline license');
    foreach (['feed.json', 'release-10001.json', 'release-10001.sig', 'notes-10001.zip'] as $name) {
        accessDenied(fn () => $server->artifact($installation, $secret, 'stable', $name), 403);
    }
    $server->setStatus($installation, 'active');
    $server->authorize($installation, $secret);
    $replacement = $server->register($installation, $token, null, 10001);
    accessDenied(fn () => $server->authorize($installation, $secret), 401);
    $secret = $server->activate($installation, $replacement)['token'];
    // Existing authenticated credential must lose access when the server-side entitlement expires.
    $db = new PDO('sqlite:' . $work . '/registry.sqlite');
    $db->exec('UPDATE licenses SET updates_until=' . (time() - 1));
    accessDenied(fn () => $server->artifact($installation, $secret, 'stable', 'notes-10001.zip'), 403);
    $db->exec('UPDATE licenses SET updates_until=NULL');
    file_put_contents($work . '/notes-10001.zip', 'tampered');
    accessDenied(fn () => $server->artifact($installation, $secret, 'stable', 'notes-10001.zip'), 503);
    echo "[OK] Online update access: activation, forged keys, binding, private secrets, version limits, signed staging, revocation, expiry, rotation, tampering, offline independence\n";
} finally {
    putenv('UPDATE_ACCESS_MODE');
    putenv('UPDATE_CREDENTIALS_FILE');
    accessRemove($work);
}
