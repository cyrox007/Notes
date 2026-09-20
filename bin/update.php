<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/core/Environment.php';
if (is_file($root . '/.env')) {
    \Core\Environment::load($root . '/.env');
}
require_once $root . '/core/Version.php';
require_once $root . '/core/UpdateManifestVerifier.php';
require_once $root . '/core/UpdatePackageStager.php';
require_once $root . '/core/UpdateArchiveInspector.php';

use Core\UpdateArchiveInspector;
use Core\UpdateManifestVerifier;
use Core\UpdatePackageStager;
use Core\Version;

$options = getopt('', [
    'manifest:',
    'signature:',
    'package:',
    'stage-root:',
    'verify-only',
    'json',
    'help',
]);

if (isset($options['help'])) {
    echo "Workspace Organizer signed updater preflight\n\n";
    echo "Verify only:\n";
    echo "  php bin/update.php --manifest=/path/update.json --signature=/path/update.sig \\\n";
    echo "      --package=/path/package.zip --verify-only [--json]\n\n";
    echo "Verify and stage outside the live application tree:\n";
    echo "  php bin/update.php --manifest=/path/update.json --signature=/path/update.sig \\\n";
    echo "      --package=/path/package.zip [--stage-root=/absolute/external/path] [--json]\n\n";
    echo "The package signature/hash and ZIP structure are audited before staging.\n";
    echo "This command NEVER extracts the package and NEVER modifies live application files.\n";
    exit(0);
}

$json = isset($options['json']);

/** @return never */
function updaterFail(string $message, string $code = 'update_failed', int $exitCode = 1): never
{
    global $json;
    if ($json) {
        echo json_encode([
            'status' => 'fail',
            'code' => $code,
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        fwrite(STDERR, "[FAIL] {$message}\n");
    }
    exit($exitCode);
}

function updaterLocalFile(string $value, string $label, int $maxBytes = 0): string
{
    $value = trim($value);
    if ($value === '' || str_contains($value, '://')) {
        updaterFail("{$label} must be an explicit local file path", 'invalid_input', 2);
    }
    if (!is_file($value) || is_link($value) || !is_readable($value)) {
        updaterFail("{$label} must be a readable regular local file, not a symlink", 'invalid_input', 2);
    }
    if ($maxBytes > 0) {
        $size = filesize($value);
        if (!is_int($size) || $size <= 0 || $size > $maxBytes) {
            updaterFail("{$label} has an invalid size", 'invalid_input', 2);
        }
    }
    $real = realpath($value);
    if (!is_string($real)) {
        updaterFail("{$label} path cannot be resolved", 'invalid_input', 2);
    }
    return $real;
}

$manifestPath = updaterLocalFile(
    (string) ($options['manifest'] ?? ''),
    'manifest',
    UpdateManifestVerifier::MAX_MANIFEST_BYTES
);
$signaturePath = updaterLocalFile((string) ($options['signature'] ?? ''), 'signature', 1024);
$packagePath = updaterLocalFile((string) ($options['package'] ?? ''), 'package');

$manifestBytes = file_get_contents($manifestPath);
$signatureToken = file_get_contents($signaturePath);
if (!is_string($manifestBytes) || !is_string($signatureToken)) {
    updaterFail('Unable to read update manifest or signature', 'read_failed');
}

try {
    $verifier = new UpdateManifestVerifier();
    if (!$verifier->hasTrustedKeys()) {
        updaterFail(
            'No trusted update public keys are configured. Signed updates remain disabled until the production update-key ceremony is completed.',
            'trust_not_configured'
        );
    }

    $status = $verifier->verify($manifestBytes, trim($signatureToken));
    if (!($status['valid'] ?? false)) {
        updaterFail(
            (string) ($status['message'] ?? 'Update manifest verification failed'),
            (string) ($status['code'] ?? 'manifest_invalid')
        );
    }
    $manifest = $status['manifest'] ?? null;
    if (!is_array($manifest)) {
        updaterFail('Verified update manifest payload is missing', 'manifest_invalid');
    }

    $stager = new UpdatePackageStager($root);
    $stager->assertCompatibility($manifest, Version::VERSION_CODE, PHP_VERSION);
    $package = $stager->verifyPackage($manifest, $packagePath);

    // Structural archive audit happens only after the signed size/hash contract
    // succeeds. It is read-only and does not extract any package entry.
    $archive = (new UpdateArchiveInspector())->inspect($packagePath);

    if (isset($options['verify-only'])) {
        $result = [
            'status' => 'verified',
            'installed_version' => Version::VERSION,
            'installed_version_code' => Version::VERSION_CODE,
            'target_version' => (string) $manifest['version'],
            'target_version_code' => (int) $manifest['version_code'],
            'key_id' => $status['key_id'],
            'package_sha256' => $package['sha256'],
            'archive' => $archive,
        ];
        if ($json) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        } else {
            echo "[OK] Signed update verified and ZIP preflight passed\n";
            echo "Installed: " . Version::VERSION . ' (' . Version::VERSION_CODE . ")\n";
            echo "Target:    " . $manifest['version'] . ' (' . $manifest['version_code'] . ")\n";
            echo "Key ID:    " . $status['key_id'] . "\n";
            echo "SHA-256:   " . $package['sha256'] . "\n";
            echo "Entries:   " . $archive['entries'] . ' (' . $archive['files'] . " files)\n";
            echo "No live files were changed.\n";
        }
        exit(0);
    }

    $stageRoot = trim((string) ($options['stage-root'] ?? ''));
    if ($stageRoot === '') {
        $configured = getenv('UPDATE_STAGING_PATH');
        if (is_string($configured) && trim($configured) !== '') {
            $stageRoot = trim($configured);
        } else {
            $private = getenv('PRIVATE_STORAGE_PATH');
            if (is_string($private) && trim($private) !== '') {
                $stageRoot = rtrim(trim($private), '/\\') . DIRECTORY_SEPARATOR . 'updates';
            }
        }
    }
    if ($stageRoot === '') {
        updaterFail(
            'No external staging root configured. Set UPDATE_STAGING_PATH, PRIVATE_STORAGE_PATH, or pass --stage-root.',
            'staging_not_configured',
            2
        );
    }

    $staged = $stager->stage(
        $manifest,
        $manifestBytes,
        trim($signatureToken),
        $packagePath,
        $stageRoot,
        Version::VERSION_CODE,
        PHP_VERSION
    );

    $result = [
        'status' => 'staged',
        'installed_version' => Version::VERSION,
        'target_version' => (string) $manifest['version'],
        'target_version_code' => (int) $manifest['version_code'],
        'key_id' => $status['key_id'],
        'stage_dir' => $staged['stage_dir'],
        'package_sha256' => $staged['sha256'],
        'archive' => $archive,
        'live_files_changed' => false,
    ];
    if ($json) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    } else {
        echo "[OK] Signed update verified, audited and staged\n";
        echo "Target:    " . $manifest['version'] . ' (' . $manifest['version_code'] . ")\n";
        echo "Stage:     " . $staged['stage_dir'] . "\n";
        echo "SHA-256:   " . $staged['sha256'] . "\n";
        echo "Entries:   " . $archive['entries'] . ' (' . $archive['files'] . " files)\n";
        echo "No live files were changed. Apply/rollback is intentionally not enabled in this preflight layer.\n";
    }
} catch (Throwable $e) {
    updaterFail($e->getMessage());
}
