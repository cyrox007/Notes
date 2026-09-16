<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

vendorUpdateRequireCli();

$options = getopt('', ['key-id:', 'private-out:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/vendor-update/keygen.php --key-id=update-prod-YYYY-NN --private-out=/secure/offline/path/key.update-secret\n");
    fwrite(STDOUT, "Generates a dedicated Ed25519 UPDATE keypair. The private key is written only to the explicit external path; the public registry entry is printed to stdout.\n");
    exit(0);
}

$keyId = vendorUpdateValidateKeyId((string) ($options['key-id'] ?? ''));
$privateOut = vendorUpdateAssertOutsideRepository((string) ($options['private-out'] ?? ''), false);
if (file_exists($privateOut)) {
    vendorUpdateFail('Refusing to overwrite an existing private update-signing key.');
}

$keyPair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keyPair);
$secretKey = sodium_crypto_sign_secretkey($keyPair);
$encodedPublic = vendorUpdateBase64UrlEncode($publicKey);
$encodedSecret = vendorUpdateBase64UrlEncode($secretKey);
$privatePayload = WORKSPACE_UPDATE_SECRET_PREFIX . $encodedSecret . PHP_EOL;

$previousUmask = umask(0077);
$handle = @fopen($privateOut, 'x');
umask($previousUmask);
$error = null;
if ($handle === false) {
    $error = 'Unable to create private update-signing key with exclusive-create semantics.';
} else {
    try {
        $written = fwrite($handle, $privatePayload);
        if ($written !== strlen($privatePayload) || !fflush($handle)) {
            $error = 'Unable to write the complete private update-signing key.';
        }
    } finally {
        fclose($handle);
    }
}

sodium_memzero($secretKey);
sodium_memzero($keyPair);
sodium_memzero($encodedSecret);
sodium_memzero($privatePayload);

if ($error !== null) {
    @unlink($privateOut);
    vendorUpdateFail($error);
}

if (PHP_OS_FAMILY !== 'Windows' && !@chmod($privateOut, 0600)) {
    @unlink($privateOut);
    vendorUpdateFail('Unable to set private update-signing key permissions to 0600; key removed.');
}

fwrite(STDOUT, "Update key ID: {$keyId}\n");
fwrite(STDOUT, "Public key: {$encodedPublic}\n");
fwrite(STDOUT, "Registry entry for config/update_trusted_keys.php:\n");
fwrite(STDOUT, "    '" . $keyId . "' => '" . $encodedPublic . "',\n");
fwrite(STDOUT, "Private update-signing key saved to external path: {$privateOut}\n");
fwrite(STDOUT, "Keep this key separate from license-signing keys and outside repository, CI, release bundles and customer systems.\n");
