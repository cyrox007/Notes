<?php

declare(strict_types=1);

use Core\UpdateArchiveInspector;
use Core\UpdateHttpsTransport;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\UpdateRemoteDelivery;
use Core\UpdateRemoteTransport;
use Core\Version;

$root = dirname(__DIR__, 2);
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';
require_once $root . '/core/UpdateRemoteTransport.php';
require_once $root . '/core/UpdateRemoteDelivery.php';

function remoteAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

function remoteRemoveTree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !is_link($path)) {
            remoteRemoveTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** @param list<array{name:string,data?:string,directory?:bool}> $entries */
function remoteBuildZipBytes(array $entries): string
{
    $body = '';
    $central = '';
    $count = 0;
    foreach ($entries as $spec) {
        $name = $spec['name'];
        $directory = (bool) ($spec['directory'] ?? str_ends_with($name, '/'));
        $data = $directory ? '' : (string) ($spec['data'] ?? '');
        $method = 0;
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 4294967296;
        }
        $size = strlen($data);
        $offset = strlen($body);
        $external = (($directory ? 0040755 : 0100644) << 16);
        $versionMade = ((3 << 8) | 20);

        $body .= "PK\x03\x04" . pack(
            'vvvvvVVVvv',
            20,
            0,
            $method,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0
        ) . $name . $data;

        $central .= "PK\x01\x02" . pack(
            'vvvvvvVVVvvvvvVV',
            $versionMade,
            20,
            0,
            $method,
            0,
            0,
            $crc,
            $size,
            $size,
            strlen($name),
            0,
            0,
            0,
            0,
            $external,
            $offset
        ) . $name;
        $count++;
    }

    $centralOffset = strlen($body);
    return $body . $central . "PK\x05\x06" . pack(
        'vvvvVVv',
        0,
        0,
        $count,
        $count,
        strlen($central),
        $centralOffset,
        0
    );
}

/** @param array<string,mixed> $manifest @return array{bytes:string,token:string} */
function remoteSignManifest(array $manifest, string $secret, string $keyId): array
{
    $bytes = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    $token = UpdateManifestVerifier::SIGNATURE_PREFIX . '.' . $keyId . '.'
        . UpdateManifestVerifier::base64UrlEncode(
            sodium_crypto_sign_detached(UpdateManifestVerifier::DOMAIN . $bytes, $secret)
        );
    return ['bytes' => $bytes, 'token' => $token];
}

final class FakeRemoteTransport implements UpdateRemoteTransport
{
    /** @var array<string,string> */
    public array $text = [];
    /** @var array<string,string> */
    public array $downloads = [];
    /** @var list<string> */
    public array $fetchCalls = [];
    /** @var list<string> */
    public array $downloadCalls = [];

    public function fetchText(string $url, int $maxBytes): string
    {
        $this->fetchCalls[] = $url;
        if (!array_key_exists($url, $this->text)) {
            throw new RuntimeException('fake text response missing');
        }
        $bytes = $this->text[$url];
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new RuntimeException('fake text response exceeded limit');
        }
        return $bytes;
    }

    public function downloadExact(
        string $url,
        string $destination,
        int $expectedBytes,
        string $expectedSha256
    ): array {
        $this->downloadCalls[] = $url;
        if (!array_key_exists($url, $this->downloads)) {
            throw new RuntimeException('fake download response missing');
        }
        $bytes = $this->downloads[$url];
        if (strlen($bytes) !== $expectedBytes || !hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new RuntimeException('fake remote package violated signed contract');
        }
        if (file_put_contents($destination, $bytes) !== strlen($bytes)) {
            throw new RuntimeException('fake transport could not write package');
        }
        @chmod($destination, 0600);
        return ['bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }
}

remoteAssert(extension_loaded('sodium'), 'sodium is required');
remoteAssert(extension_loaded('openssl'), 'openssl is required for remote delivery');

$temp = sys_get_temp_dir() . '/wo-remote-delivery-' . bin2hex(random_bytes(6));
remoteAssert(mkdir($temp, 0700, true), 'cannot create remote delivery fixture root');

try {
    $feedUrl = 'https://updates.example.test/stable/feed.json';
    $manifestUrl = 'https://updates.example.test/stable/workspace-organizer.update.json';
    $signatureUrl = 'https://updates.example.test/stable/workspace-organizer.update.sig';
    $packageName = 'workspace-organizer-v1.0.0-test.zip';
    $packageUrl = 'https://updates.example.test/stable/' . $packageName;
    $packageBytes = remoteBuildZipBytes([
        ['name' => 'workspace-organizer-v1.0.0-test/', 'directory' => true],
        ['name' => 'workspace-organizer-v1.0.0-test/index.php', 'data' => "<?php echo 'remote';\n"],
        ['name' => 'workspace-organizer-v1.0.0-test/core/', 'directory' => true],
        ['name' => 'workspace-organizer-v1.0.0-test/core/Version.php', 'data' => "<?php echo 'version';\n"],
    ]);

    $pair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($pair);
    $public = sodium_crypto_sign_publickey($pair);
    $keyId = 'remote-contract-key';
    $targetCode = Version::VERSION_CODE + 100;
    $manifest = [
        'schema' => UpdateManifestVerifier::MANIFEST_SCHEMA,
        'product' => 'workspace-organizer',
        'version' => '1.0.0-remote-test',
        'version_code' => $targetCode,
        'channel' => 'stable',
        'issued_at' => time() - 5,
        'source_commit' => str_repeat('b', 40),
        'min_source_version_code' => Version::VERSION_CODE,
        'requires_php' => '8.1.0',
        'package' => [
            'filename' => $packageName,
            'sha256' => hash('sha256', $packageBytes),
            'size' => strlen($packageBytes),
            'format' => 'zip',
        ],
        'notes' => 'Remote delivery contract fixture',
    ];
    $signed = remoteSignManifest($manifest, $secret, $keyId);
    $manifestBytes = $signed['bytes'];
    $signatureToken = $signed['token'];
    $feedBytes = json_encode([
        'schema' => UpdateRemoteDelivery::FEED_SCHEMA,
        'product' => 'workspace-organizer',
        'channel' => 'stable',
        'manifest' => 'workspace-organizer.update.json',
        'signature' => 'workspace-organizer.update.sig',
        // Deliberate untrusted noise: package location must come only from the signed manifest.
        'package' => 'evil-unsigned-pointer.zip',
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;

    $transport = new FakeRemoteTransport();
    $transport->text = [
        $feedUrl => $feedBytes,
        $manifestUrl => $manifestBytes,
        $signatureUrl => $signatureToken . PHP_EOL,
    ];
    $transport->downloads[$packageUrl] = $packageBytes;

    $verifier = new UpdateManifestVerifier([
        $keyId => UpdateManifestVerifier::base64UrlEncode($public),
    ]);
    $delivery = new UpdateRemoteDelivery(
        $root,
        $verifier,
        $transport,
        new UpdatePackageStager($root),
        new UpdateArchiveInspector()
    );

    $checked = $delivery->check($feedUrl, 'stable', Version::VERSION_CODE, PHP_VERSION);
    remoteAssert(($checked['status'] ?? '') === 'update_available', 'remote check did not report update_available');
    remoteAssert(($checked['update_available'] ?? false) === true, 'available update boolean missing');
    remoteAssert(($checked['target_version_code'] ?? 0) === $targetCode, 'remote check target version changed');
    remoteAssert(($checked['key_id'] ?? '') === $keyId, 'remote check lost signing key id');
    remoteAssert(($checked['package_downloaded'] ?? true) === false, 'check-only path downloaded a package');
    remoteAssert($transport->downloadCalls === [], 'check-only path invoked package transport');

    // Same-version and installed-ahead are valid signed feed states, not errors.
    foreach ([
        ['code' => Version::VERSION_CODE, 'expected' => 'up_to_date'],
        ['code' => max(1, Version::VERSION_CODE - 1), 'expected' => 'ahead_of_feed'],
    ] as $case) {
        $variant = $manifest;
        $variant['version_code'] = $case['code'];
        $variant['version'] = $case['expected'] . '-fixture';
        $variant['min_source_version_code'] = 1;
        $variantSigned = remoteSignManifest($variant, $secret, $keyId);
        $variantTransport = new FakeRemoteTransport();
        $variantTransport->text = [
            $feedUrl => $feedBytes,
            $manifestUrl => $variantSigned['bytes'],
            $signatureUrl => $variantSigned['token'] . PHP_EOL,
        ];
        $variantDelivery = new UpdateRemoteDelivery($root, $verifier, $variantTransport);
        $variantResult = $variantDelivery->check($feedUrl, 'stable', Version::VERSION_CODE, PHP_VERSION);
        remoteAssert(($variantResult['status'] ?? '') === $case['expected'], 'normal signed feed state was misclassified');
        remoteAssert(($variantResult['update_available'] ?? true) === false, 'normal non-update feed state was marked available');
        remoteAssert($variantTransport->downloadCalls === [], 'normal check state downloaded package bytes');
    }

    // A newer but runtime-incompatible signed update is discoverable without
    // downloading the package; stage() remains the enforcing action boundary.
    $incompatible = $manifest;
    $incompatible['requires_php'] = '99.0.0';
    $incompatibleSigned = remoteSignManifest($incompatible, $secret, $keyId);
    $incompatibleTransport = new FakeRemoteTransport();
    $incompatibleTransport->text = [
        $feedUrl => $feedBytes,
        $manifestUrl => $incompatibleSigned['bytes'],
        $signatureUrl => $incompatibleSigned['token'] . PHP_EOL,
    ];
    $incompatibleDelivery = new UpdateRemoteDelivery($root, $verifier, $incompatibleTransport);
    $incompatibleResult = $incompatibleDelivery->check($feedUrl, 'stable', Version::VERSION_CODE, PHP_VERSION);
    remoteAssert(($incompatibleResult['status'] ?? '') === 'update_incompatible', 'incompatible signed update was not classified');
    remoteAssert(($incompatibleResult['update_available'] ?? true) === false, 'incompatible update was marked installable');
    remoteAssert(str_contains((string) ($incompatibleResult['compatibility_message'] ?? ''), 'PHP'), 'incompatibility reason was not preserved');
    remoteAssert($incompatibleTransport->downloadCalls === [], 'incompatible check downloaded package bytes');

    $stageRoot = $temp . '/staging';
    $staged = $delivery->stage($feedUrl, 'stable', $stageRoot, Version::VERSION_CODE, PHP_VERSION);
    remoteAssert(($staged['status'] ?? '') === 'staged', 'remote delivery did not stage package');
    remoteAssert(($staged['source'] ?? '') === 'remote_feed', 'remote staged source marker missing');
    remoteAssert(($staged['package_url'] ?? '') === $packageUrl, 'unsigned feed package pointer influenced package URL');
    remoteAssert($transport->downloadCalls === [$packageUrl], 'remote delivery fetched an unexpected package URL');
    remoteAssert(is_dir((string) ($staged['stage_dir'] ?? '')), 'immutable stage directory missing');
    remoteAssert(is_file((string) $staged['stage_dir'] . '/' . $packageName), 'staged remote package missing');
    remoteAssert(
        hash_equals(hash('sha256', $packageBytes), (string) hash_file('sha256', (string) $staged['stage_dir'] . '/' . $packageName)),
        'staged remote package hash changed'
    );
    remoteAssert(!file_exists($stageRoot . '/evil-unsigned-pointer.zip'), 'unsigned feed package pointer was materialized');

    $incompatibleStageRejected = false;
    try {
        $incompatibleDelivery->stage(
            $feedUrl,
            'stable',
            $temp . '/incompatible-stage',
            Version::VERSION_CODE,
            PHP_VERSION
        );
    } catch (Throwable $e) {
        $incompatibleStageRejected = str_contains($e->getMessage(), 'PHP');
    }
    remoteAssert($incompatibleStageRejected, 'stage accepted an incompatible signed update');
    remoteAssert($incompatibleTransport->downloadCalls === [], 'incompatible stage downloaded package before compatibility rejection');

    $badTransport = new FakeRemoteTransport();
    $badTransport->text = $transport->text;
    $badTransport->text[$manifestUrl] = str_replace('Remote delivery contract fixture', 'tampered', $manifestBytes);
    $badDelivery = new UpdateRemoteDelivery($root, $verifier, $badTransport);
    $tamperRejected = false;
    try {
        $badDelivery->check($feedUrl, 'stable', Version::VERSION_CODE, PHP_VERSION);
    } catch (Throwable $e) {
        $tamperRejected = str_contains(strtolower($e->getMessage()), 'signature');
    }
    remoteAssert($tamperRejected, 'tampered remote manifest was accepted');

    $channelRejected = false;
    try {
        $delivery->check($feedUrl, 'beta', Version::VERSION_CODE, PHP_VERSION);
    } catch (Throwable $e) {
        $channelRejected = str_contains(strtolower($e->getMessage()), 'channel');
    }
    remoteAssert($channelRejected, 'feed/channel mismatch was accepted');

    $unsafeTransport = new FakeRemoteTransport();
    $unsafeTransport->text[$feedUrl] = json_encode([
        'schema' => 1,
        'product' => 'workspace-organizer',
        'channel' => 'stable',
        'manifest' => '../manifest.json',
        'signature' => 'manifest.sig',
    ], JSON_THROW_ON_ERROR);
    $unsafeDelivery = new UpdateRemoteDelivery($root, $verifier, $unsafeTransport);
    $unsafeRejected = false;
    try {
        $unsafeDelivery->check($feedUrl, 'stable', Version::VERSION_CODE, PHP_VERSION);
    } catch (Throwable $e) {
        $unsafeRejected = str_contains(strtolower($e->getMessage()), 'filename');
    }
    remoteAssert($unsafeRejected, 'feed path traversal was accepted');

    $insideRejected = false;
    $downloadsBefore = count($transport->downloadCalls);
    try {
        $delivery->stage(
            $feedUrl,
            'stable',
            $root . '/cache/remote-delivery-contract',
            Version::VERSION_CODE,
            PHP_VERSION
        );
    } catch (Throwable $e) {
        $insideRejected = str_contains(strtolower($e->getMessage()), 'outside the live application tree');
    }
    remoteAssert($insideRejected, 'remote delivery staging inside application root was accepted');
    remoteAssert(count($transport->downloadCalls) === $downloadsBefore, 'package downloaded before unsafe stage root was rejected');

    $https = new UpdateHttpsTransport(1, 1);

    $accessDeniedMethod = new ReflectionMethod(UpdateHttpsTransport::class, 'accessDeniedException');
    foreach ([
        'license_revoked' => 'отозвана',
        'updates_expired' => 'истёк',
        'version_not_entitled' => 'выше разрешённой',
        'license_invalid' => 'не прошла проверку',
    ] as $reason => $messagePart) {
        $body = json_encode(['error' => 'update_access_denied', 'reason' => $reason], JSON_THROW_ON_ERROR);
        $stream = fopen('php://temp', 'w+b');
        remoteAssert(is_resource($stream), 'не удалось создать поток проверки причины 403');
        fwrite($stream, $body);
        rewind($stream);
        $denied = $accessDeniedMethod->invoke($https, $stream, [
            'content-type' => 'application/json',
            'content-length' => (string) strlen($body),
        ], 403);
        fclose($stream);
        remoteAssert(
            $denied instanceof RuntimeException
                && $denied->getCode() === 403
                && str_contains($denied->getMessage(), $messagePart),
            'Клиент потерял точную безопасную причину 403: ' . $reason
        );
    }

    $httpRejected = false;
    try {
        $https->fetchText('http://updates.example.test/feed.json', 1024);
    } catch (Throwable $e) {
        $httpRejected = str_contains($e->getMessage(), 'HTTPS');
    }
    remoteAssert($httpRejected, 'plain HTTP remote update URL was accepted');

    $literalIpRejected = false;
    try {
        $https->fetchText('https://127.0.0.1/feed.json', 1024);
    } catch (Throwable $e) {
        $literalIpRejected = str_contains($e->getMessage(), 'public DNS host');
    }
    remoteAssert($literalIpRejected, 'literal/private update-server IP was accepted');

    sodium_memzero($secret);
    sodium_memzero($pair);
    echo "[OK] updater remote signed delivery contract\n";
} finally {
    remoteRemoveTree($temp);
    remoteRemoveTree($root . '/cache/remote-delivery-contract');
}
