<?php

declare(strict_types=1);

use App\Services\LicenseVerifier;

require_once __DIR__ . '/lib.php';
require_once dirname(__DIR__, 2) . '/app/services/LicenseVerifier.php';

vendorLicenseRequireCli();

$options = getopt('', [
    'private-key:',
    'key-id:',
    'installation-id:',
    'license-id:',
    'edition:',
    'expires-at:',
    'not-before:',
    'customer:',
    'features:',
    'help',
]);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php tools/vendor-license/issue.php \\\n    --private-key=/secure/offline/path/key.license-secret \\\n    --key-id=prod-YYYY-NN \\\n    --installation-id=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx \\\n    --license-id=lic-customer-001 \\\n    --edition=standard [--expires-at=UNIX] [--not-before=UNIX] \\\n    [--customer='Customer name'] [--features=notes,tasks,messenger]\n\n");
    fwrite(STDOUT, "The signed wo1 token is written to stdout. The private key is never printed or copied.\n");
    exit(0);
}

$keyId = vendorLicenseValidateKeyId((string) ($options['key-id'] ?? ''));
$installationId = strtolower(trim((string) ($options['installation-id'] ?? '')));
if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $installationId) !== 1) {
    vendorLicenseFail('Invalid installation id. Copy the exact value from /admin/license.');
}

$licenseId = trim((string) ($options['license-id'] ?? ''));
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,127}$/', $licenseId) !== 1) {
    vendorLicenseFail('Invalid license id.');
}

$edition = strtolower(trim((string) ($options['edition'] ?? '')));
if (preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $edition) !== 1) {
    vendorLicenseFail('Invalid edition.');
}

$issuedAt = time();
$expiresAt = array_key_exists('expires-at', $options)
    ? vendorLicenseParseTimestamp($options['expires-at'], 'expires-at')
    : null;
$notBefore = array_key_exists('not-before', $options)
    ? vendorLicenseParseTimestamp($options['not-before'], 'not-before')
    : null;

if ($expiresAt !== null && $expiresAt <= $issuedAt) {
    vendorLicenseFail('expires-at must be later than the issue time.');
}
if ($notBefore !== null && $expiresAt !== null && $notBefore >= $expiresAt) {
    vendorLicenseFail('not-before must be earlier than expires-at.');
}

$payload = [
    'v' => LicenseVerifier::PAYLOAD_VERSION,
    'license_id' => $licenseId,
    'installation_id' => $installationId,
    'issued_at' => $issuedAt,
    'expires_at' => $expiresAt,
    'edition' => $edition,
];
if ($notBefore !== null) {
    $payload['not_before'] = $notBefore;
}

$customer = trim((string) ($options['customer'] ?? ''));
if ($customer !== '') {
    if (mb_strlen($customer) > 160) {
        vendorLicenseFail('customer must not exceed 160 characters.');
    }
    $payload['customer'] = $customer;
}

$featuresRaw = trim((string) ($options['features'] ?? ''));
if ($featuresRaw !== '') {
    $features = [];
    foreach (explode(',', $featuresRaw) as $feature) {
        $feature = strtolower(trim($feature));
        if ($feature === '' || preg_match('/^[a-z][a-z0-9._-]{1,63}$/', $feature) !== 1) {
            vendorLicenseFail('Invalid feature name: ' . ($feature !== '' ? $feature : '[empty]'));
        }
        $features[$feature] = true;
    }
    $payload['features'] = array_keys($features);
}

$secretKey = vendorLicenseReadSecretKey((string) ($options['private-key'] ?? ''));
$error = null;
$token = null;
try {
    $publicKey = sodium_crypto_sign_publickey_from_secretkey($secretKey);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $payloadEncoded = LicenseVerifier::base64UrlEncode($json);
    $signedBytes = LicenseVerifier::TOKEN_PREFIX . '.' . $keyId . '.' . $payloadEncoded;
    $signature = sodium_crypto_sign_detached($signedBytes, $secretKey);
    $token = $signedBytes . '.' . LicenseVerifier::base64UrlEncode($signature);

    $verificationTime = max($issuedAt, $notBefore ?? $issuedAt);
    $verifier = new LicenseVerifier(
        [$keyId => LicenseVerifier::base64UrlEncode($publicKey)],
        static fn (): int => $verificationTime
    );
    $status = $verifier->verify($token, $installationId);
    if (!($status['valid'] ?? false)) {
        throw new RuntimeException('Self-verification failed: ' . (string) ($status['message'] ?? 'unknown error'));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
} finally {
    sodium_memzero($secretKey);
}

if ($error !== null || !is_string($token)) {
    vendorLicenseFail($error ?? 'License token was not generated.', 1);
}

fwrite(STDOUT, $token . PHP_EOL);
