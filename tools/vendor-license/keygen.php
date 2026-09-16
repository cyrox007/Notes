<?php

declare(strict_types=1);

require_once __DIR__ . '/lib.php';

vendorLicenseRequireCli();

$options = getopt('', ['key-id:', 'private-out:', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/vendor-license/keygen.php --key-id=prod-YYYY-NN --private-out=/secure/offline/path/key.license-secret\n");
    fwrite(STDOUT, "Generates an Ed25519 keypair. The private key is written only to the explicit external path; the public registry entry is printed to stdout.\n");
    exit(0);
}

$keyId = vendorLicenseValidateKeyId((string) ($options['key-id'] ?? ''));
$privateOut = vendorLicenseAssertOutsideRepository((string) ($options['private-out'] ?? ''), false);
if (file_exists($privateOut)) {
    vendorLicenseFail('Refusing to overwrite an existing private key file. Choose a new path or remove it explicitly.');
}

$keyPair = sodium_crypto_sign_keypair();
$publicKey = sodium_crypto_sign_publickey($keyPair);
$secretKey = sodium_crypto_sign_secretkey($keyPair);
$encodedPublic = vendorLicenseBase64UrlEncode($publicKey);
$encodedSecret = vendorLicenseBase64UrlEncode($secretKey);
$privatePayload = WORKSPACE_LICENSE_SECRET_PREFIX . $encodedSecret . PHP_EOL;
$error = null;
$handle = @fopen($privateOut, 'x');
if ($handle === false) {
    $error = 'Unable to create private key file with exclusive-create semantics.';
} else {
    try {
        $written = fwrite($handle, $privatePayload);
        if ($written !== strlen($privatePayload) || !fflush($handle)) {
            $error = 'Unable to write the complete private key file.';
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
    vendorLicenseFail($error);
}

if (PHP_OS_FAMILY !== 'Windows' && !@chmod($privateOut, 0600)) {
    @unlink($privateOut);
    vendorLicenseFail('Unable to set private key permissions to 0600; key file removed.');
}

fwrite(STDOUT, "Key ID: {$keyId}\n");
fwrite(STDOUT, "Public key: {$encodedPublic}\n");
fwrite(STDOUT, "Registry entry:\n");
fwrite(STDOUT, "    '" . $keyId . "' => '" . $encodedPublic . "',\n");
fwrite(STDOUT, "Private key saved to external path: {$privateOut}\n");
fwrite(STDOUT, "Store that file in the vendor's offline secret storage. Do not copy it into the repository, CI, release bundle, customer server or support archive.\n");
