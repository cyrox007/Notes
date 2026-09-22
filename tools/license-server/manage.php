<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/LicenseServer.php';

$options = getopt('', ['db:', 'init', 'register', 'revoke', 'restore', 'publish', 'installation-id:',
    'license-file:', 'activation-out:', 'updates-until:', 'max-version:', 'manifest:', 'signature:', 'package:', 'status', 'json', 'help']);
if (isset($options['help'])) {
    echo "Vendor license registry (PHP sqlite3, sodium, mbstring required)\n"
        . "  --db=/private/licenses.sqlite --init\n"
        . "  --db=... --register --installation-id=UUID --license-file=/private/license.txt\n"
        . "    --activation-out=/private/customer.activation [--updates-until=UNIX] [--max-version=10001]\n"
        . "  --db=... --revoke|--restore --installation-id=UUID\n"
        . "  --db=... --publish --manifest=/private/release.json --signature=/private/release.sig --package=/private/release.zip\n"
        . "  --db=... --status [--json]\n"
        . "Registering again rotates credentials and consumes a new activation code. Output files must not exist.\n";
    exit;
}

$activationOutput = null;
$activationPath = null;
try {
    $actions = array_intersect(['init', 'register', 'revoke', 'restore', 'publish', 'status'], array_keys($options));
    if (count($actions) !== 1) {
        throw new RuntimeException('Choose exactly one action; see --help');
    }
    $server = new \NotesVendor\LicenseServer((string) ($options['db'] ?? ''),
        new \App\Services\LicenseVerifier(), new \Core\UpdateManifestVerifier(), isset($options['init']));
    $installation = strtolower(trim((string) ($options['installation-id'] ?? '')));
    if (isset($options['register'])) {
        $licensePath = (string) ($options['license-file'] ?? '');
        if (!is_file($licensePath) || filesize($licensePath) > \App\Services\LicenseVerifier::MAX_TOKEN_LENGTH) {
            throw new RuntimeException('A bounded signed license file is required');
        }
        $limits = [];
        foreach (['updates-until', 'max-version'] as $key) {
            $limits[$key] = isset($options[$key]) ? filter_var($options[$key], FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]) : null;
            if ($limits[$key] === false) {
                throw new RuntimeException('Invalid entitlement limit');
            }
        }
        $activationPath = (string) ($options['activation-out'] ?? '');
        \Core\UpdateDownloadCredentials::assertExternalPath($activationPath);
        $old = umask(0077);
        try {
            $activationOutput = @fopen($activationPath, 'xb');
        } finally {
            umask($old);
        }
        if ($activationOutput === false) {
            throw new RuntimeException('Cannot create activation output; existing files are never overwritten');
        }
        $code = $server->register($installation, trim((string) file_get_contents($licensePath)),
            $limits['updates-until'], $limits['max-version']);
        if (fwrite($activationOutput, $code . "\n") !== 65 || !fflush($activationOutput)) {
            throw new RuntimeException('Cannot save activation code; register again with a new output path');
        }
        fclose($activationOutput);
        $activationOutput = null;
        echo "Registered. Activation code saved privately. Previous download credentials are invalid.\n";
    } elseif (isset($options['revoke']) || isset($options['restore'])) {
        $server->setStatus($installation, isset($options['revoke']) ? 'revoked' : 'active');
        echo "License status updated. The next artifact request uses the new status.\n";
    } elseif (isset($options['publish'])) {
        $server->publish((string) ($options['manifest'] ?? ''), (string) ($options['signature'] ?? ''), (string) ($options['package'] ?? ''));
        echo "Signed release registered. Serve packages only through the authenticated endpoint.\n";
    } elseif (isset($options['status'])) {
        $health = $server->health();
        if (isset($options['json'])) {
            echo json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } else {
            echo 'Update service: ' . strtoupper((string) $health['status']) . "\n";
            echo 'Registry:       ' . ($health['registry'] ? 'READY' : 'NOT READY') . "\n";
            echo 'License trust:  ' . ($health['license_trust'] ? 'READY' : 'NOT READY') . "\n";
            echo 'Update trust:   ' . ($health['update_trust'] ? 'READY' : 'NOT READY') . "\n";
        }
        if (($health['status'] ?? '') !== 'ok') {
            exit(3);
        }
    } else {
        echo "Registry initialized.\n";
    }
} catch (Throwable $e) {
    if (is_resource($activationOutput)) {
        fclose($activationOutput);
        @unlink((string) $activationPath);
    }
    // Database errors may contain sensitive values; report only the type for unexpected errors.
    fwrite(STDERR, '[FAIL] ' . ($e instanceof \PDOException ? 'Registry operation failed' : $e->getMessage()) . "\n");
    exit(1);
}
