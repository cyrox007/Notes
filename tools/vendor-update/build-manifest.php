<?php

declare(strict_types=1);

use Core\UpdateManifestVerifier;

require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__, 2) . '/core/UpdateManifestVerifier.php';

vendorUpdateRequireCli();

$options = getopt('', [
    'package:',
    'version:',
    'version-code:',
    'channel:',
    'source-commit:',
    'min-source-version-code:',
    'requires-php:',
    'notes:',
    'out:',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/vendor-update/build-manifest.php --package=/path/release.zip --version=1.0.0 --version-code=10000 --channel=stable --source-commit=<40hex> --min-source-version-code=1404 --requires-php=8.1.0 --out=/path/update.json [--notes='...']\n");
    exit(0);
}

$packagePath = trim((string) ($options['package'] ?? ''));
if ($packagePath === '' || str_contains($packagePath, '://') || !is_file($packagePath) || is_link($packagePath)) {
    vendorUpdateFail('package must be an explicit regular local file, not a URL or symlink.');
}
$packageReal = realpath($packagePath);
if (!is_string($packageReal)) {
    vendorUpdateFail('Unable to resolve package path.');
}
$packageName = basename($packageReal);
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,190}\.zip$/', $packageName) !== 1) {
    vendorUpdateFail('Package filename must be a safe .zip basename.');
}
$packageSize = filesize($packageReal);
$packageSha = hash_file('sha256', $packageReal);
if (!is_int($packageSize) || $packageSize <= 0 || !is_string($packageSha)) {
    vendorUpdateFail('Unable to measure/hash update package.');
}
$handle = fopen($packageReal, 'rb');
if ($handle === false) {
    vendorUpdateFail('Unable to open update package.');
}
try {
    $magic = fread($handle, 4);
} finally {
    fclose($handle);
}
if (!in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true)) {
    vendorUpdateFail('Update package is not a ZIP archive.');
}

$version = trim((string) ($options['version'] ?? ''));
if (preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,63}$/', $version) !== 1) {
    vendorUpdateFail('Invalid version.');
}
$versionCodeRaw = (string) ($options['version-code'] ?? '');
$minSourceRaw = (string) ($options['min-source-version-code'] ?? '');
if (preg_match('/^[1-9][0-9]{0,9}$/', $versionCodeRaw) !== 1 || preg_match('/^[1-9][0-9]{0,9}$/', $minSourceRaw) !== 1) {
    vendorUpdateFail('version-code and min-source-version-code must be positive integers.');
}
$channel = trim((string) ($options['channel'] ?? ''));
if (!in_array($channel, ['alpha', 'beta', 'stable'], true)) {
    vendorUpdateFail('channel must be alpha, beta or stable.');
}
$sourceCommit = strtolower(trim((string) ($options['source-commit'] ?? '')));
if (preg_match('/^[0-9a-f]{40}$/', $sourceCommit) !== 1) {
    vendorUpdateFail('source-commit must be a full 40-character lowercase commit SHA.');
}
$requiresPhp = trim((string) ($options['requires-php'] ?? ''));
if (preg_match('/^\d+\.\d+(?:\.\d+)?$/', $requiresPhp) !== 1) {
    vendorUpdateFail('requires-php must be a semantic PHP floor such as 8.1.0.');
}

$manifest = [
    'schema' => UpdateManifestVerifier::MANIFEST_SCHEMA,
    'product' => 'workspace-organizer',
    'version' => $version,
    'version_code' => (int) $versionCodeRaw,
    'channel' => $channel,
    'issued_at' => time(),
    'source_commit' => $sourceCommit,
    'min_source_version_code' => (int) $minSourceRaw,
    'requires_php' => $requiresPhp,
    'package' => [
        'filename' => $packageName,
        'sha256' => $packageSha,
        'size' => $packageSize,
        'format' => 'zip',
    ],
];
$notes = trim((string) ($options['notes'] ?? ''));
if ($notes !== '') {
    if (mb_strlen($notes) > 2000) {
        vendorUpdateFail('notes must not exceed 2000 characters.');
    }
    $manifest['notes'] = $notes;
}

$bytes = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
$out = trim((string) ($options['out'] ?? ''));
if ($out === '' || str_contains($out, '://')) {
    vendorUpdateFail('out must be an explicit local file path.');
}
$parent = realpath(dirname($out));
if ($parent === false || !is_dir($parent)) {
    vendorUpdateFail('out parent directory does not exist.');
}
$resolvedOut = $parent . DIRECTORY_SEPARATOR . basename($out);
if (file_exists($resolvedOut)) {
    vendorUpdateFail('Refusing to overwrite an existing update manifest.');
}
$handle = fopen($resolvedOut, 'x');
if ($handle === false) {
    vendorUpdateFail('Unable to create update manifest.');
}
try {
    if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
        @unlink($resolvedOut);
        vendorUpdateFail('Unable to write update manifest.', 1);
    }
} finally {
    fclose($handle);
}

fwrite(STDOUT, "Manifest: {$resolvedOut}\n");
fwrite(STDOUT, "Package: {$packageName}\n");
fwrite(STDOUT, "SHA-256: {$packageSha}\n");
fwrite(STDOUT, "Size: {$packageSize}\n");
