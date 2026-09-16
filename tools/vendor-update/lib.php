<?php

declare(strict_types=1);

const WORKSPACE_UPDATE_SECRET_PREFIX = 'wo-update-ed25519-secret-v1:';

function vendorUpdateRoot(): string
{
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false) {
        throw new RuntimeException('Unable to resolve Workspace repository root');
    }
    return $root;
}

function vendorUpdateFail(string $message, int $exitCode = 2): never
{
    fwrite(STDERR, 'Update signing tooling error: ' . $message . PHP_EOL);
    exit($exitCode);
}

function vendorUpdateRequireCli(): void
{
    if (PHP_SAPI !== 'cli') {
        vendorUpdateFail('This command is CLI-only.');
    }
    if (!extension_loaded('sodium')) {
        vendorUpdateFail('PHP sodium extension is required.');
    }
}

function vendorUpdateValidateKeyId(string $keyId): string
{
    $keyId = trim($keyId);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,47}$/', $keyId) !== 1) {
        vendorUpdateFail('Invalid key id. Use 1-48 characters: letters, numbers, dot, underscore or hyphen.');
    }
    return $keyId;
}

function vendorUpdateIsAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\\\')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function vendorUpdateNormalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        $path = strtolower($path[0]) . substr($path, 1);
    }
    return rtrim($path, '/');
}

function vendorUpdateAssertOutsideRepository(string $path, bool $mustExist): string
{
    $path = trim($path);
    if ($path === '' || !vendorUpdateIsAbsolutePath($path)) {
        vendorUpdateFail('Private update-signing key path must be absolute and outside the repository.');
    }

    if ($mustExist) {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || is_link($resolved)) {
            vendorUpdateFail('Private update-signing key does not exist or is not a regular file.');
        }
    } else {
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent)) {
            vendorUpdateFail('Private key output directory does not exist.');
        }
        $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
    }

    $root = vendorUpdateNormalizePath(vendorUpdateRoot());
    $candidate = vendorUpdateNormalizePath($resolved);
    if ($candidate === $root || str_starts_with($candidate . '/', $root . '/')) {
        vendorUpdateFail('Private update-signing material must not be stored inside the repository tree.');
    }

    return $resolved;
}

function vendorUpdateBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function vendorUpdateBase64UrlDecode(string $value): ?string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return null;
    }
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    return $decoded === false ? null : $decoded;
}

function vendorUpdateReadSecretKey(string $path): string
{
    $resolved = vendorUpdateAssertOutsideRepository($path, true);
    $size = filesize($resolved);
    if (!is_int($size) || $size <= 0 || $size > 4096) {
        vendorUpdateFail('Private update-signing key file has an invalid size.');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        $permissions = fileperms($resolved);
        if ($permissions !== false && (($permissions & 0077) !== 0)) {
            vendorUpdateFail('Private update-signing key must not be accessible by group/other users (expected mode 0600).');
        }
    }

    $content = file_get_contents($resolved);
    if (!is_string($content)) {
        vendorUpdateFail('Unable to read private update-signing key.');
    }
    $content = trim($content);
    if (!str_starts_with($content, WORKSPACE_UPDATE_SECRET_PREFIX)) {
        vendorUpdateFail('Unsupported private update-signing key file format.');
    }

    $encoded = substr($content, strlen(WORKSPACE_UPDATE_SECRET_PREFIX));
    $secret = vendorUpdateBase64UrlDecode($encoded);
    sodium_memzero($content);
    if ($secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        vendorUpdateFail('Invalid Ed25519 private update-signing key material.');
    }
    return $secret;
}
