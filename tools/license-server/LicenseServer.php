<?php

declare(strict_types=1);

namespace NotesVendor;

use App\Services\LicenseVerifier;
use Core\UpdateManifestVerifier;
use PDO;
use RuntimeException;
use Throwable;

require_once dirname(__DIR__, 2) . '/app/services/LicenseVerifier.php';
require_once dirname(__DIR__, 2) . '/core/UpdateManifestVerifier.php';
require_once dirname(__DIR__, 2) . '/core/UpdateDownloadCredentials.php';

/** Vendor-only registry. No signing key, application database or public package directory. */
final class LicenseServer
{
    private PDO $db;

    public function __construct(
        string $database,
        private LicenseVerifier $licenses,
        private UpdateManifestVerifier $updates,
        bool $initialize = false
    ) {
        \Core\UpdateDownloadCredentials::assertExternalPath($database);
        if (!$initialize && !is_file($database)) {
            throw new RuntimeException('License registry is not initialized');
        }
        if (is_file($database) && DIRECTORY_SEPARATOR === '/' && (fileperms($database) & 0077) !== 0) {
            throw new RuntimeException('License registry must have mode 0600');
        }
        if (DIRECTORY_SEPARATOR === '/' && (fileperms(dirname($database)) & 0007) !== 0) {
            throw new RuntimeException('License registry directory must not be accessible to other users');
        }
        $old = umask(0077);
        try {
            $this->db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } finally {
            umask($old);
        }
        $this->db->exec('PRAGMA busy_timeout=5000');
        if ($initialize) {
            $this->db->exec('CREATE TABLE IF NOT EXISTS licenses (
                installation_id TEXT PRIMARY KEY, license_id TEXT NOT NULL UNIQUE,
                signed_license TEXT NOT NULL, status TEXT NOT NULL DEFAULT \'active\',
                updates_until INTEGER, max_version INTEGER,
                activation_hash TEXT, credential_hash TEXT, activated_at INTEGER
            ); CREATE TABLE IF NOT EXISTS releases (
                channel TEXT NOT NULL, version_code INTEGER NOT NULL,
                manifest_name TEXT NOT NULL, signature_name TEXT NOT NULL,
                manifest_bytes TEXT NOT NULL, signature TEXT NOT NULL,
                package_name TEXT NOT NULL, package_path TEXT NOT NULL,
                package_size INTEGER NOT NULL, package_sha256 TEXT NOT NULL,
                PRIMARY KEY(channel, version_code), UNIQUE(channel, package_name)
            )');
        }
    }

    /** Register/renew a vendor-signed license; never trusts a key supplied by the customer. */
    public function register(string $installation, string $token, ?int $until, ?int $maxVersion): string
    {
        $this->validateIdentity($installation, str_repeat('0', 64));
        $this->verifyLicense($installation, $token);
        if (($until !== null && $until <= 0) || ($maxVersion !== null && $maxVersion <= 0)) {
            throw new RuntimeException('Entitlement limits must be positive integers or null');
        }
        $payload = $this->licenses->verify($token, $installation)['payload'];
        $activation = bin2hex(random_bytes(32));
        $stmt = $this->db->prepare('INSERT INTO licenses
            (installation_id, license_id, signed_license, updates_until, max_version, activation_hash)
            VALUES (?, ?, ?, ?, ?, ?)
            ON CONFLICT(installation_id) DO UPDATE SET license_id=excluded.license_id,
            signed_license=excluded.signed_license, updates_until=excluded.updates_until,
            max_version=excluded.max_version, activation_hash=excluded.activation_hash,
            credential_hash=NULL, activated_at=NULL, status=\'active\'');
        $stmt->execute([$installation, $payload['license_id'], $token, $until, $maxVersion, hash('sha256', $activation)]);
        return $activation;
    }

    /** @return array{status:string,registry:bool,license_trust:bool,update_trust:bool} */
    public function health(): array
    {
        $registryReady = false;
        try {
            $value = $this->db->query("SELECT 1")->fetchColumn();
            $registryReady = (int) $value === 1;
        } catch (Throwable) {
            $registryReady = false;
        }

        $licenseTrust = $this->licenses->hasTrustedKeys();
        $updateTrust = $this->updates->hasTrustedKeys();
        $ok = $registryReady && $licenseTrust && $updateTrust;

        return [
            'status' => $ok ? 'ok' : 'degraded',
            'registry' => $registryReady,
            'license_trust' => $licenseTrust,
            'update_trust' => $updateTrust,
        ];
    }

    public function setStatus(string $installation, string $status): void
    {
        if (!in_array($status, ['active', 'revoked'], true)) {
            throw new RuntimeException('Invalid license status');
        }
        $stmt = $this->db->prepare('UPDATE licenses SET status=? WHERE installation_id=?');
        $stmt->execute([$status, $installation]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Installation was not found');
        }
    }

    public function activate(string $installation, string $activation): array
    {
        $this->validateIdentity($installation, $activation);
        // Serialize code consumption: two simultaneous activations cannot both succeed.
        $this->db->exec('BEGIN IMMEDIATE');
        try {
            $row = $this->row($installation);
            if ($row === null || !is_string($row['activation_hash'])
                || !hash_equals($row['activation_hash'], hash('sha256', $activation))) {
                throw new RuntimeException('Activation denied', 401);
            }
            $this->assertEntitled($row);
            $credential = bin2hex(random_bytes(32));
            $stmt = $this->db->prepare('UPDATE licenses SET activation_hash=NULL, credential_hash=?, activated_at=? WHERE installation_id=?');
            $stmt->execute([hash('sha256', $credential), time(), $installation]);
            $this->db->exec('COMMIT');
            return ['schema' => 1, 'installation_id' => $installation, 'token' => $credential];
        } catch (Throwable $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }

    public function authorize(string $installation, string $credential): array
    {
        $this->validateIdentity($installation, $credential);
        $row = $this->row($installation);
        if ($row === null || !is_string($row['credential_hash'])
            || !hash_equals($row['credential_hash'], hash('sha256', $credential))) {
            throw new RuntimeException('Authentication required', 401);
        }
        $this->assertEntitled($row);
        return $row;
    }

    /** Import immutable, already signed release artifacts from external private storage. */
    public function publish(string $manifestPath, string $signaturePath, string $packagePath): void
    {
        foreach ([$manifestPath, $signaturePath, $packagePath] as $path) {
            \Core\UpdateDownloadCredentials::assertExternalPath($path);
            if (!is_file($path) || !is_readable($path)) {
                throw new RuntimeException('Release artifact is not readable');
            }
        }
        if (filesize($manifestPath) > UpdateManifestVerifier::MAX_MANIFEST_BYTES || filesize($signaturePath) > 2048) {
            throw new RuntimeException('Release metadata is too large');
        }
        $bytes = (string) file_get_contents($manifestPath);
        $signature = trim((string) file_get_contents($signaturePath));
        $verified = $this->updates->verify($bytes, $signature);
        if (!$verified['valid']) {
            throw new RuntimeException('Release signature or manifest is invalid');
        }
        $manifest = $verified['manifest'];
        $package = $manifest['package'];
        if (basename($packagePath) !== $package['filename'] || filesize($packagePath) !== $package['size']
            || $package['size'] > 536870912 || !hash_equals($package['sha256'], (string) hash_file('sha256', $packagePath))) {
            throw new RuntimeException('Release package does not match the signed manifest');
        }
        $name = 'release-' . $manifest['version_code'];
        $stmt = $this->db->prepare('INSERT INTO releases VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$manifest['channel'], $manifest['version_code'], $name . '.json', $name . '.sig',
            $bytes, $signature, $package['filename'], realpath($packagePath), $package['size'], $package['sha256']]);
    }

    /** Called afresh for EVERY feed/manifest/signature/package request, including direct ZIP requests. */
    public function artifact(string $installation, string $credential, string $channel, string $name): array
    {
        $license = $this->authorize($installation, $credential);
        if (!in_array($channel, ['alpha', 'beta', 'stable'], true)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,200}$/D', $name) !== 1) {
            throw new RuntimeException('Artifact not found', 404);
        }
        if ($name === 'feed.json') {
            $stmt = $this->db->prepare('SELECT * FROM releases WHERE channel=? AND version_code<=? ORDER BY version_code DESC LIMIT 1');
            $stmt->execute([$channel, $license['max_version'] ?? PHP_INT_MAX]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM releases WHERE channel=? AND (manifest_name=? OR signature_name=? OR package_name=?) LIMIT 1');
            $stmt->execute([$channel, $name, $name, $name]);
        }
        $release = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($release)) {
            throw new RuntimeException('Artifact not found', 404);
        }
        if ($license['max_version'] !== null && (int) $release['version_code'] > (int) $license['max_version']) {
            throw new RuntimeException('Release entitlement denied', 403);
        }
        if ($name === 'feed.json') {
            return ['body' => json_encode(['schema' => 1, 'product' => 'workspace-organizer', 'channel' => $channel,
                'manifest' => $release['manifest_name'], 'signature' => $release['signature_name']], JSON_THROW_ON_ERROR),
                'type' => 'application/json'];
        }
        if ($name === $release['manifest_name']) {
            return ['body' => $release['manifest_bytes'], 'type' => 'application/json'];
        }
        if ($name === $release['signature_name']) {
            return ['body' => $release['signature'], 'type' => 'text/plain'];
        }
        $path = $release['package_path'];
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Release package unavailable', 503);
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Release package unavailable', 503);
        }
        // Hash the same open descriptor that will be streamed, not a separately resolved filename.
        $hash = hash_init('sha256');
        $size = hash_update_stream($hash, $stream);
        if ($size !== (int) $release['package_size'] || !hash_equals($release['package_sha256'], hash_final($hash)) || !rewind($stream)) {
            fclose($stream);
            throw new RuntimeException('Release package integrity failed', 503);
        }
        return ['stream' => $stream, 'size' => $size, 'type' => 'application/zip'];
    }

    private function row(string $installation): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM licenses WHERE installation_id=?');
        $stmt->execute([$installation]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertEntitled(array $row): void
    {
        if ($row['status'] !== 'active') {
            throw new RuntimeException('License revoked', 403);
        }
        if ($row['updates_until'] !== null && time() >= (int) $row['updates_until']) {
            throw new RuntimeException('Updates entitlement expired', 403);
        }

        $this->verifyLicense($row['installation_id'], $row['signed_license']);
    }

    private function verifyLicense(string $installation, string $token): void
    {
        if (!$this->licenses->verify($token, $installation)['valid']) {
            throw new RuntimeException('Vendor license verification failed', 403);
        }
    }

    private function validateIdentity(string $installation, string $secret): void
    {
        if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $installation) !== 1
            || preg_match('/^[0-9a-f]{64}$/D', $secret) !== 1) {
            throw new RuntimeException('Authentication required', 401);
        }
    }
}
