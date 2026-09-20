<?php

declare(strict_types=1);

use Core\UpdateManifestVerifier;

require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__, 2) . '/core/UpdateManifestVerifier.php';

vendorUpdateRequireCli();

$options = getopt('', ['private-key:', 'key-id:', 'manifest:', 'signature-out:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/vendor-update/sign-manifest.php --private-key=/secure/key.update-secret --key-id=update-prod-YYYY-NN --manifest=/path/update.json [--signature-out=/path/update.sig]\n");
    fwrite(STDOUT, "Signs the exact manifest bytes using the Workspace update-signing domain. The signature token is printed to stdout unless --signature-out is supplied.\n");
    exit(0);
}

$keyId = vendorUpdateValidateKeyId((string) ($options['key-id'] ?? ''));
$manifestPath = trim((string) ($options['manifest'] ?? ''));
if ($manifestPath === '' || str_contains($manifestPath, '://') || !is_file($manifestPath) || is_link($manifestPath)) {
    vendorUpdateFail('Manifest must be an explicit regular local file, not a URL or symlink.');
}
$manifestSize = filesize($manifestPath);
if (!is_int($manifestSize) || $manifestSize <= 0 || $manifestSize > UpdateManifestVerifier::MAX_MANIFEST_BYTES) {
    vendorUpdateFail('Manifest has an invalid size.');
}
$manifestBytes = file_get_contents($manifestPath);
if (!is_string($manifestBytes)) {
    vendorUpdateFail('Unable to read update manifest.');
}

$secretKey = vendorUpdateReadSecretKey((string) ($options['private-key'] ?? ''));
$error = null;
$signatureToken = null;
try {
    $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
    $signature = sodium_crypto_sign_detached(UpdateManifestVerifier::DOMAIN . $manifestBytes, $secretKey);
    $signatureToken = UpdateManifestVerifier::SIGNATURE_PREFIX . '.' . $keyId . '.'
        . UpdateManifestVerifier::base64UrlEncode($signature);

    $verifier = new UpdateManifestVerifier([
        $keyId => UpdateManifestVerifier::base64UrlEncode($publicKey),
    ]);
    $status = $verifier->verify($manifestBytes, $signatureToken);
    if (!($status['valid'] ?? false)) {
        $error = 'Self-verification failed: ' . (string) ($status['message'] ?? 'unknown error');
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
sodium_memzero($secretKey);

if ($error !== null || !is_string($signatureToken)) {
    vendorUpdateFail($error ?? 'Unable to sign update manifest', 1);
}

$out = trim((string) ($options['signature-out'] ?? ''));
if ($out === '') {
    fwrite(STDOUT, $signatureToken . PHP_EOL);
    exit(0);
}
if (str_contains($out, '://')) {
    vendorUpdateFail('signature-out must be a local path.');
}
$parent = realpath(dirname($out));
if ($parent === false || !is_dir($parent)) {
    vendorUpdateFail('signature-out parent directory does not exist.');
}
$resolvedOut = $parent . DIRECTORY_SEPARATOR . basename($out);
if (file_exists($resolvedOut)) {
    vendorUpdateFail('Refusing to overwrite an existing signature file.');
}
$handle = fopen($resolvedOut, 'x');
if ($handle === false) {
    vendorUpdateFail('Unable to create signature file.');
}
try {
    $payload = $signatureToken . PHP_EOL;
    if (fwrite($handle, $payload) !== strlen($payload) || !fflush($handle)) {
        @unlink($resolvedOut);
        vendorUpdateFail('Unable to write signature file.', 1);
    }
} finally {
    fclose($handle);
}

fwrite(STDOUT, "Signed update manifest: {$manifestPath}\n");
fwrite(STDOUT, "Signature: {$resolvedOut}\n");
fwrite(STDOUT, "Key ID: {$keyId}\n");
