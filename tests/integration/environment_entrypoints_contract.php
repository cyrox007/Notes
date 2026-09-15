<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function entrypointAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$message}\n");
        exit(1);
    }
}

$entrypoints = [
    'bin/healthcheck.php',
    'bin/migrate.php',
    'bin/migrate_crypto.php',
    'bin/ws_doctor.php',
];

foreach ($entrypoints as $relative) {
    $path = $root . '/' . $relative;
    $source = file_get_contents($path);
    entrypointAssert(is_string($source), "cannot read {$relative}");
    entrypointAssert(
        !str_contains($source, 'Dotenv\\Dotenv'),
        "{$relative} still references phpdotenv"
    );
    entrypointAssert(
        str_contains($source, '/core/Environment.php'),
        "{$relative} does not bootstrap the internal Environment loader"
    );
    entrypointAssert(
        str_contains($source, '\\Core\\Environment::load('),
        "{$relative} does not load .env through Core\\Environment"
    );
}

$wsServer = file_get_contents($root . '/ws_server/server.php');
entrypointAssert(is_string($wsServer), 'cannot read ws_server/server.php');
entrypointAssert(
    str_contains($wsServer, "require_once SITEPATH . '/core.php'"),
    'WebSocket server bypasses the canonical core bootstrap'
);

$core = file_get_contents($root . '/core.php');
entrypointAssert(is_string($core), 'cannot read core.php');
$environmentPosition = strpos($core, '\\Core\\Environment::load(');
$vendorPosition = strpos($core, "vendor/autoload.php");
entrypointAssert($environmentPosition !== false, 'core.php does not load the internal environment');
entrypointAssert(
    $vendorPosition === false || $environmentPosition < $vendorPosition,
    'core.php loads Composer before the internal environment'
);
entrypointAssert(!str_contains($core, 'Dotenv\\Dotenv'), 'core.php still references phpdotenv');

// CLI tools intentionally support deployments where configuration is provided
// entirely by the process environment and .env is absent. Their source must
// therefore guard Environment::load() with an is_file(.env) check rather than
// turning a missing local file into a hard failure.
foreach ($entrypoints as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    entrypointAssert(
        str_contains($source, "is_file(\$root . '/.env')"),
        "{$relative} no longer preserves OS-only environment deployments"
    );
}

echo "[OK] internal environment entrypoint contract\n";
