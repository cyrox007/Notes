<?php

declare(strict_types=1);

namespace Core;

use JsonException;
use RuntimeException;
use Throwable;

/**
 * Network ingress for signed updates.
 *
 * The feed is discovery metadata only and is not trusted. Security authority
 * remains the detached Ed25519 signature over the exact update manifest bytes;
 * the signed manifest then supplies the package filename, size and SHA-256.
 * Remote delivery never enters maintenance and never mutates live code.
 */
final class UpdateRemoteDelivery
{
    public const FEED_SCHEMA = 1;
    public const MAX_FEED_BYTES = 32768;
    public const MAX_SIGNATURE_BYTES = 2048;
    public const MAX_REMOTE_PACKAGE_BYTES = 536870912; // 512 MiB hard network-ingress ceiling.

    private string $appRoot;
    private UpdatePackageStager $stager;
    private UpdateArchiveInspector $archiveInspector;

    public function __construct(
        string $appRoot,
        private UpdateManifestVerifier $verifier,
        private UpdateRemoteTransport $transport,
        ?UpdatePackageStager $stager = null,
        ?UpdateArchiveInspector $archiveInspector = null
    ) {
        $real = realpath($appRoot);
        if (!is_string($real) || !is_dir($real) || is_link($appRoot)) {
            throw new RuntimeException('Application root cannot be resolved safely for remote update delivery');
        }
        $this->appRoot = $this->normalize($real);
        $this->stager = $stager ?? new UpdatePackageStager($real);
        $this->archiveInspector = $archiveInspector ?? new UpdateArchiveInspector();
    }

    /**
     * Fetch only the discovery feed + signed manifest/signature.
     * No package bytes are downloaded. A valid signed feed state is reported as
     * data rather than turning normal "up to date" / incompatibility states into
     * transport errors; this is the read-only primitive used by the future UI.
     *
     * @return array<string,mixed>
     */
    public function check(
        string $feedUrl,
        string $channel,
        int $currentVersionCode,
        string $currentPhpVersion
    ): array {
        $resolved = $this->resolve($feedUrl, $channel);
        $manifest = $resolved['manifest'];
        $targetVersionCode = (int) $manifest['version_code'];

        $status = 'update_available';
        $available = true;
        $compatibilityMessage = null;
        if ($targetVersionCode === $currentVersionCode) {
            $status = 'up_to_date';
            $available = false;
        } elseif ($targetVersionCode < $currentVersionCode) {
            $status = 'ahead_of_feed';
            $available = false;
        } else {
            try {
                $this->stager->assertCompatibility($manifest, $currentVersionCode, $currentPhpVersion);
            } catch (Throwable $e) {
                $status = 'update_incompatible';
                $available = false;
                $compatibilityMessage = $e->getMessage();
            }
        }

        return [
            'status' => $status,
            'update_available' => $available,
            'feed_url' => $resolved['feed_url'],
            'channel' => $channel,
            'current_version_code' => $currentVersionCode,
            'target_version' => (string) $manifest['version'],
            'target_version_code' => $targetVersionCode,
            'source_commit' => (string) $manifest['source_commit'],
            'requires_php' => (string) $manifest['requires_php'],
            'min_source_version_code' => (int) $manifest['min_source_version_code'],
            'package_filename' => (string) $manifest['package']['filename'],
            'package_size' => (int) $manifest['package']['size'],
            'package_sha256' => (string) $manifest['package']['sha256'],
            'key_id' => $resolved['key_id'],
            'notes' => $manifest['notes'] ?? null,
            'compatibility_message' => $compatibilityMessage,
            'package_downloaded' => false,
            'live_files_changed' => false,
        ];
    }

    /**
     * Download the exact signed package to an external temporary directory,
     * audit it, then hand it to the existing immutable staging layer.
     *
     * @return array<string,mixed>
     */
    public function stage(
        string $feedUrl,
        string $channel,
        string $stageRoot,
        int $currentVersionCode,
        string $currentPhpVersion
    ): array {
        $resolved = $this->resolve($feedUrl, $channel);
        $manifest = $resolved['manifest'];

        // Unlike check(), staging is an action. It must fail closed for
        // same-version, downgrade, source-floor and PHP incompatibility before
        // a stage directory or package download is created.
        $this->stager->assertCompatibility($manifest, $currentVersionCode, $currentPhpVersion);

        $package = $manifest['package'];
        if (!is_array($package)) {
            throw new RuntimeException('Verified manifest package metadata is missing');
        }

        $expectedBytes = (int) ($package['size'] ?? 0);
        if ($expectedBytes < 1 || $expectedBytes > self::MAX_REMOTE_PACKAGE_BYTES) {
            throw new RuntimeException('Signed update package exceeds the remote delivery safety limit');
        }
        $filename = (string) ($package['filename'] ?? '');
        if (!$this->safeLeafName($filename) || !str_ends_with(strtolower($filename), '.zip')) {
            throw new RuntimeException('Signed update package filename is unsafe for remote delivery');
        }
        $packageUrl = $this->assetUrl($resolved['feed_url'], $filename);
        $stageRoot = $this->prepareExternalRoot($stageRoot);

        $lockPath = $stageRoot . DIRECTORY_SEPARATOR . '.remote-update-delivery.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot create remote update delivery lock');
        }
        @chmod($lockPath, 0600);
        if (!@flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('Another remote update delivery operation is already running');
        }

        $tempDir = $stageRoot . DIRECTORY_SEPARATOR . '.remote-download-'
            . (int) $manifest['version_code'] . '-' . bin2hex(random_bytes(6));
        try {
            $oldUmask = umask(0077);
            $made = @mkdir($tempDir, 0700, false);
            umask($oldUmask);
            if (!$made || !is_dir($tempDir)) {
                throw new RuntimeException('Cannot create remote update download directory');
            }

            $downloadedPackage = $tempDir . DIRECTORY_SEPARATOR . $filename;
            $download = $this->transport->downloadExact(
                $packageUrl,
                $downloadedPackage,
                $expectedBytes,
                (string) $package['sha256']
            );
            if ($download['bytes'] !== $expectedBytes || !hash_equals((string) $package['sha256'], $download['sha256'])) {
                throw new RuntimeException('Remote transport did not satisfy the signed package contract');
            }

            // Re-run the same local package and ZIP contracts used by manual
            // updater ingress. Network delivery never bypasses local verification.
            $verifiedPackage = $this->stager->verifyPackage($manifest, $downloadedPackage);
            $archive = $this->archiveInspector->inspect($downloadedPackage);
            $staged = $this->stager->stage(
                $manifest,
                $resolved['manifest_bytes'],
                $resolved['signature_token'],
                $downloadedPackage,
                $stageRoot,
                $currentVersionCode,
                $currentPhpVersion
            );

            return [
                'status' => 'staged',
                'source' => 'remote_feed',
                'feed_url' => $resolved['feed_url'],
                'channel' => $channel,
                'target_version' => (string) $manifest['version'],
                'target_version_code' => (int) $manifest['version_code'],
                'source_commit' => (string) $manifest['source_commit'],
                'key_id' => $resolved['key_id'],
                'package_url' => $packageUrl,
                'package_sha256' => $verifiedPackage['sha256'],
                'stage_dir' => $staged['stage_dir'],
                'archive' => $archive,
                'live_files_changed' => false,
            ];
        } finally {
            if (is_dir($tempDir) && !is_link($tempDir)) {
                $this->removeTree($tempDir);
            }
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Resolve and authenticate remote discovery metadata only. Compatibility is
     * intentionally classified by check() or enforced by stage(), not here.
     *
     * @return array{
     *   feed_url:string,
     *   manifest:array<string,mixed>,
     *   manifest_bytes:string,
     *   signature_token:string,
     *   key_id:string
     * }
     */
    private function resolve(string $feedUrl, string $channel): array
    {
        if (!in_array($channel, ['alpha', 'beta', 'stable'], true)) {
            throw new RuntimeException('Remote update channel must be alpha, beta or stable');
        }
        if (!$this->verifier->hasTrustedKeys()) {
            throw new RuntimeException('No trusted update public keys are configured for remote delivery');
        }

        $feedUrl = $this->canonicalFeedUrl($feedUrl);
        $feedBytes = $this->transport->fetchText($feedUrl, self::MAX_FEED_BYTES);
        try {
            $feed = json_decode($feedBytes, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Remote update feed is not valid JSON', 0, $e);
        }
        if (!is_array($feed) || array_is_list($feed)) {
            throw new RuntimeException('Remote update feed must be a JSON object');
        }
        if (($feed['schema'] ?? null) !== self::FEED_SCHEMA) {
            throw new RuntimeException('Remote update feed schema is not supported');
        }
        if (($feed['product'] ?? null) !== 'workspace-organizer') {
            throw new RuntimeException('Remote update feed product is invalid');
        }
        if (($feed['channel'] ?? null) !== $channel) {
            throw new RuntimeException('Remote update feed channel does not match the configured channel');
        }

        $manifestName = (string) ($feed['manifest'] ?? '');
        $signatureName = (string) ($feed['signature'] ?? '');
        if (!$this->safeLeafName($manifestName) || !str_ends_with(strtolower($manifestName), '.json')) {
            throw new RuntimeException('Remote update feed manifest filename is invalid');
        }
        if (!$this->safeLeafName($signatureName) || !str_ends_with(strtolower($signatureName), '.sig')) {
            throw new RuntimeException('Remote update feed signature filename is invalid');
        }

        $manifestUrl = $this->assetUrl($feedUrl, $manifestName);
        $signatureUrl = $this->assetUrl($feedUrl, $signatureName);
        $manifestBytes = $this->transport->fetchText($manifestUrl, UpdateManifestVerifier::MAX_MANIFEST_BYTES);
        $signatureToken = trim($this->transport->fetchText($signatureUrl, self::MAX_SIGNATURE_BYTES));
        if ($signatureToken === '') {
            throw new RuntimeException('Remote update signature is empty');
        }

        $verification = $this->verifier->verify($manifestBytes, $signatureToken);
        if (!($verification['valid'] ?? false) || !is_array($verification['manifest'] ?? null)) {
            throw new RuntimeException((string) ($verification['message'] ?? 'Remote update manifest verification failed'));
        }
        $manifest = $verification['manifest'];
        if (($manifest['channel'] ?? null) !== $channel) {
            throw new RuntimeException('Signed update manifest channel does not match the configured channel');
        }

        $package = $manifest['package'] ?? null;
        if (!is_array($package)) {
            throw new RuntimeException('Signed update manifest package metadata is missing');
        }
        $packageSize = (int) ($package['size'] ?? 0);
        if ($packageSize < 1 || $packageSize > self::MAX_REMOTE_PACKAGE_BYTES) {
            throw new RuntimeException('Signed update package exceeds the remote delivery safety limit');
        }

        return [
            'feed_url' => $feedUrl,
            'manifest' => $manifest,
            'manifest_bytes' => $manifestBytes,
            'signature_token' => $signatureToken,
            'key_id' => (string) ($verification['key_id'] ?? ''),
        ];
    }

    private function canonicalFeedUrl(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new RuntimeException('Remote update feed requires HTTPS');
        }
        if (
            isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
        ) {
            throw new RuntimeException('Remote update feed URL contains unsupported components');
        }
        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
        $path = (string) ($parts['path'] ?? '');
        if ($host === '' || $path === '' || !str_starts_with($path, '/') || basename($path) === '') {
            throw new RuntimeException('Remote update feed URL is incomplete');
        }
        if (str_contains($path, '\\') || str_contains($path, "\0") || str_contains($path, '/../') || str_ends_with($path, '/..')) {
            throw new RuntimeException('Remote update feed URL path is unsafe');
        }
        return 'https://' . $host . $path;
    }

    private function assetUrl(string $feedUrl, string $leaf): string
    {
        if (!$this->safeLeafName($leaf)) {
            throw new RuntimeException('Remote update asset filename is unsafe');
        }
        $parts = parse_url($feedUrl);
        if (!is_array($parts)) {
            throw new RuntimeException('Remote update feed URL cannot be resolved');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '/');
        $dir = str_replace('\\', '/', dirname($path));
        if ($dir === '/' || $dir === '.') {
            $assetPath = '/' . $leaf;
        } else {
            $assetPath = rtrim($dir, '/') . '/' . $leaf;
        }
        return 'https://' . $host . $assetPath;
    }

    private function safeLeafName(string $name): bool
    {
        return $name !== ''
            && strlen($name) <= 191
            && basename($name) === $name
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $name) === 1;
    }

    private function prepareExternalRoot(string $path): string
    {
        $path = trim($path);
        if (!$this->isAbsolutePath($path)) {
            throw new RuntimeException('Remote update staging path must be absolute');
        }
        if (is_link($path)) {
            throw new RuntimeException('Remote update staging root must not be a symlink');
        }
        if (!is_dir($path)) {
            $oldUmask = umask(0077);
            $made = @mkdir($path, 0700, true);
            umask($oldUmask);
            if (!$made && !is_dir($path)) {
                throw new RuntimeException('Cannot create remote update staging root');
            }
        }
        @chmod($path, 0700);

        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || !is_writable($real)) {
            throw new RuntimeException('Remote update staging root is not writable');
        }
        $real = $this->normalize($real);
        if ($this->inside($real, $this->appRoot)) {
            throw new RuntimeException('Remote update staging root must be outside the live application tree');
        }
        return $real;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\');
    }

    private function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        if (PHP_OS_FAMILY === 'Windows' && preg_match('/^[A-Za-z]:/', $path) === 1) {
            $path = strtolower($path[0]) . substr($path, 1);
        }
        return $path;
    }

    private function inside(string $path, string $parent): bool
    {
        $path = $this->normalize($path);
        $parent = $this->normalize($parent);
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }
        return $path === $parent || str_starts_with($path . '/', $parent . '/');
    }

    private function removeTree(string $dir): void
    {
        $items = scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($dir);
    }
}
