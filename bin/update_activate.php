<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/core/UpdateRemoteTransport.php';

$options = getopt('', ['server:', 'installation-id:', 'activation-file:', 'credentials-out:', 'help']);
if (isset($options['help'])) {
    echo "Activate online update downloads (does not change the local runtime license)\n"
        . "php bin/update_activate.php --server=https://updates.example.com/ --installation-id=UUID \\\n"
        . "  --activation-file=/private/one-time-code --credentials-out=/private/update-access.json\n"
        . "Use the Installation ID from /admin/license. Credentials output must not exist.\n";
    exit;
}
$output = null;
$destination = (string) ($options['credentials-out'] ?? '');
try {
    $base = (string) ($options['server'] ?? '');
    \Core\UpdateDownloadCredentials::validateBaseUrl($base);
    $installation = strtolower(trim((string) ($options['installation-id'] ?? '')));
    $codePath = (string) ($options['activation-file'] ?? '');
    if (!is_file($codePath) || filesize($codePath) > 128) {
        throw new RuntimeException('A private activation code file is required');
    }
    $code = trim((string) file_get_contents($codePath));
    if (preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', $installation) !== 1
        || preg_match('/^[0-9a-f]{64}$/D', $code) !== 1) {
        throw new RuntimeException('Invalid installation ID or activation code');
    }
    \Core\UpdateDownloadCredentials::assertExternalPath($destination);
    // Reserve output before consuming a one-time code. Never overwrite a working credential.
    $old = umask(0077);
    try {
        $output = @fopen($destination, 'xb');
    } finally {
        umask($old);
    }
    if ($output === false) {
        throw new RuntimeException('Cannot create credentials file; use a new external private path');
    }
    $result = (new \Core\UpdateHttpsTransport())->activate($base, $installation, $code);
    new \Core\UpdateDownloadCredentials($result);
    if (($result['installation_id'] ?? '') !== $installation || ($result['base_url'] ?? '') !== $base) {
        throw new RuntimeException('Activation response does not match the requested installation/server');
    }
    $bytes = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($output, $bytes) !== strlen($bytes) || !fflush($output)) {
        throw new RuntimeException('Cannot persist activation; request a replacement code');
    }
    fclose($output);
    $output = null;
    echo "Update access activated. Credentials saved privately.\n"
        . "Set UPDATE_ACCESS_MODE=online, UPDATE_CREDENTIALS_FILE to the output path,\n"
        . "and UPDATE_FEED_URL=" . $base . "stable/feed.json in .env.\n";
} catch (Throwable $e) {
    if (is_resource($output)) {
        fclose($output);
        @unlink($destination);
    }
    fwrite(STDERR, '[FAIL] ' . $e->getMessage() . "\n");
    exit(1);
}
