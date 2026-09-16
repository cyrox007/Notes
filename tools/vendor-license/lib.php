<?php

declare(strict_types=1);

const WORKSPACE_LICENSE_SECRET_PREFIX = 'wo-ed25519-secret-v1:';

function vendorLicenseRoot(): string
{
    $root = realpath(dirname(__DIR__, 2));
    if ($root === false) {
        throw new RuntimeException('Unable to resolve Workspace repository root');
    }
    return $root;
}

function vendorLicenseFail(string $message, int $exitCode = 2): never
{
    fwrite(STDERR, 'License tooling error: ' . $message . PHP_EOL);
    exit($exitCode);
}

function vendorLicenseRequireCli(): void
{
    if (PHP_SAPI !== 'cli') {
        vendorLicenseFail('This command is CLI-only.');
    }
    if (!extension_loaded('sodium')) {
        vendorLicenseFail('PHP sodium extension is required.');
    }
}

function vendorLicenseValidateKeyId(string $keyId): string
{
    $keyId = trim($keyId);
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $keyId) !== 1) {
        vendorLicenseFail('Invalid key id. Use 1-32 characters: letters, numbers, dot, underscore or hyphen.');
    }
    return $keyId;
}

function vendorLicenseIsAbsolutePath(string $path): bool
{
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\\\')
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

function vendorLicenseNormalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        $path = strtolower($path[0]) . substr($path, 1);
    }
    return rtrim($path, '/');
}

function vendorLicenseAssertOutsideRepository(string $path, bool $mustExist): string
{
    $path = trim($path);
    if ($path === '' || !vendorLicenseIsAbsolutePath($path)) {
        vendorLicenseFail('Private key path must be an absolute path outside the repository.');
    }

    if ($mustExist) {
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved)) {
            vendorLicenseFail('Private key file does not exist or is not a regular file.');
        }
    } else {
        $parent = realpath(dirname($path));
        if ($parent === false || !is_dir($parent)) {
            vendorLicenseFail('Private key output directory does not exist.');
        }
        $resolved = $parent . DIRECTORY_SEPARATOR . basename($path);
    }

    $root = vendorLicenseNormalizePath(vendorLicenseRoot());
    $candidate = vendorLicenseNormalizePath($resolved);
    if ($candidate === $root || str_starts_with($candidate . '/', $root . '/')) {
        vendorLicenseFail('Private signing material must not be stored inside the repository tree.');
    }

    return $resolved;
}

function vendorLicenseBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function vendorLicenseBase64UrlDecode(string $value): ?string
{
    if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
        return null;
    }
    $padding = (4 - (strlen($value) % 4)) % 4;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    return $decoded === false ? null : $decoded;
}

function vendorLicenseReadSecretKey(string $path): string
{
    $resolved = vendorLicenseAssertOutsideRepository($path, true);
    if (filesize($resolved) === false || (int) filesize($resolved) > 4096) {
        vendorLicenseFail('Private key file has an invalid size.');
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        $permissions = fileperms($resolved);
        if ($permissions !== false && (($permissions & 0077) !== 0)) {
            vendorLicenseFail('Private key file must not be accessible by group/other users (expected mode 0600).');
        }
    }

    $content = file_get_contents($resolved);
    if (!is_string($content)) {
        vendorLicenseFail('Unable to read private key file.');
    }
    $content = trim($content);
    if (!str_starts_with($content, WORKSPACE_LICENSE_SECRET_PREFIX)) {
        vendorLicenseFail('Unsupported private key file format.');
    }

    $encoded = substr($content, strlen(WORKSPACE_LICENSE_SECRET_PREFIX));
    $secret = vendorLicenseBase64UrlDecode($encoded);
    if ($secret === null || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        vendorLicenseFail('Invalid Ed25519 private key material.');
    }
    return $secret;
}

function vendorLicenseParseTimestamp(mixed $value, string $name): int
{
    if (!is_scalar($value) || preg_match('/^[0-9]{1,12}$/', (string) $value) !== 1) {
        vendorLicenseFail("{$name} must be a Unix timestamp.");
    }
    $timestamp = (int) $value;
    if ($timestamp <= 0) {
        vendorLicenseFail("{$name} must be greater than zero.");
    }
    return $timestamp;
}
