<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

function requireMarker(string $path, string $marker, string $message): void
{
    $content = file_get_contents($path);
    if ($content === false || !str_contains($content, $marker)) {
        fwrite(STDERR, "PHP minimum runtime contract failed: {$message}\n");
        exit(1);
    }
}

if (PHP_VERSION_ID < 80200) {
    fwrite(STDERR, "PHP minimum runtime contract requires PHP 8.2+; running " . PHP_VERSION . "\n");
    exit(1);
}

requireMarker($root . '/README.md', 'PHP `8.2+`', 'README must state PHP 8.2+ technical minimum');
requireMarker($root . '/README.md', '8.3+', 'README must retain a production recommendation newer than the technical floor');
requireMarker($root . '/docs/HOSTING_INSTALL.md', 'PHP 8.2+', 'hosting guide must state PHP 8.2+ minimum');
requireMarker($root . '/docs/PRODUCTION.md', 'PHP 8.2+', 'production guide must state PHP 8.2+ technical minimum');
requireMarker($root . '/composer.json', '"php": ">=8.2"', 'Composer must define the PHP 8.2 runtime floor');
requireMarker($root . '/docs/SYSTEM_REQUIREMENTS_0.14.md', '`pcntl`', 'realtime requirements must document pcntl');
requireMarker($root . '/docs/SYSTEM_REQUIREMENTS_0.14.md', '`posix`', 'realtime requirements must document posix');
requireMarker($root . '/core/InstallerEnvironmentService.php', "'PHP 8.2+' => version_compare(PHP_VERSION, '8.2.0', '>=')", 'installer must accept PHP 8.2+');
requireMarker($root . '/bin/healthcheck.php', "version_compare(PHP_VERSION, '8.2.0', '>=')", 'healthcheck must use PHP 8.2 floor');
requireMarker($root . '/.github/workflows/hosting-installer.yml', "php-version: '8.2'", 'real HTTP installer workflow must run on the minimum PHP version');

// Baseline language features remain available at the declared floor.
requireMarker($root . '/core/DatabaseManager.php', 'enum LogLevel:', 'core uses enums available in PHP 8.2');
requireMarker($root . '/core/ModuleManifest.php', 'readonly', 'module platform uses readonly properties available in PHP 8.2');
requireMarker($root . '/core/ModuleManifest.php', 'array_is_list(', 'module manifest uses array_is_list available in PHP 8.2');
requireMarker($root . '/core/ApplicationEntryPoint.php', '): never', 'startup boundary retains a terminal response contract');

// PHP 8.3-only calls must not silently enter the supported 8.2 path.
$forbiddenRuntimeMarkers = [
    'json_validate(',      // PHP 8.3
    'str_increment(',      // PHP 8.3
    'str_decrement(',      // PHP 8.3
];

$scanRoots = ['app', 'bin', 'core', 'ws_server'];
foreach ($scanRoots as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname());
        if ($source === false) {
            fwrite(STDERR, "Cannot read {$file->getPathname()}\n");
            exit(1);
        }
        foreach ($forbiddenRuntimeMarkers as $marker) {
            if (str_contains($source, $marker)) {
                fwrite(STDERR, "PHP minimum runtime contract failed: {$marker} found in {$file->getPathname()}\n");
                exit(1);
            }
        }
    }
}

echo 'PHP minimum runtime contract OK on ' . PHP_VERSION . PHP_EOL;
