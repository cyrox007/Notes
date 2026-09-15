<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/Version.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/ModuleRegistry.php';

use Core\ModuleRegistry;
use Core\Version;

function failModuleContract(string $message): never
{
    fwrite(STDERR, "Module registry contract failed: {$message}\n");
    exit(1);
}

function assertModuleContract(bool $condition, string $message): void
{
    if (!$condition) {
        failModuleContract($message);
    }
}

/** @param list<string> $dependencies */
function writeModuleFixture(string $root, string $id, array $dependencies = [], string $capability = '', array $core = []): void
{
    $dir = $root . '/' . $id;
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create fixture directory: {$dir}");
    }

    $manifest = [
        'schema' => 1,
        'id' => $id,
        'name' => ucfirst($id),
        'version' => '0.13.0',
        'core' => $core !== [] ? $core : ['min' => '0.13.0-alpha', 'max_exclusive' => '0.15.0'],
        'dependencies' => $dependencies,
        'capabilities' => [$capability !== '' ? $capability : 'fixture.' . $id],
        'package' => ['bundled' => false, 'default_enabled' => true],
        'license' => ['feature' => 'fixture.' . $id],
        'runtime' => ['mode' => 'isolated'],
        'storage_namespaces' => [],
    ];

    file_put_contents(
        $dir . '/module.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
}

function removeTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

$registry = ModuleRegistry::discover($root . '/modules', Version::VERSION);
$expected = ['admin', 'files', 'messenger', 'notes', 'profile', 'tasks'];
assertModuleContract(array_keys($registry->all()) === $expected, 'bundled module manifest set drifted');
assertModuleContract($registry->defaultComposition() === $expected, 'default bundled composition must contain all 0.13 modules');

foreach ($registry->all() as $id => $manifest) {
    assertModuleContract($manifest->id() === $id, "manifest id mismatch for {$id}");
    assertModuleContract($manifest->runtimeMode() === 'legacy', "{$id} must remain explicitly marked legacy until isolated");
    assertModuleContract(strlen($manifest->integrityHash()) === 64, "{$id} manifest must expose a SHA-256 integrity hash");
    assertModuleContract($manifest->licenseFeature() !== null, "{$id} must declare a central entitlement feature");
    assertModuleContract($manifest->isCompatibleWithCore(Version::VERSION), "{$id} must be compatible with the current core");
}

assertModuleContract($registry->resolveComposition(['notes']) === ['notes'], 'single independent module composition failed');
assertModuleContract($registry->resolveComposition(['files', 'tasks']) === ['files', 'tasks'], 'multi-module composition order is unstable');

$tmp = sys_get_temp_dir() . '/workspace-module-contract-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeModuleFixture($tmp, 'beta');
    writeModuleFixture($tmp, 'alpha', ['beta']);
    $fixtureRegistry = ModuleRegistry::discover($tmp, Version::VERSION);
    assertModuleContract(
        $fixtureRegistry->resolveComposition(['alpha']) === ['beta', 'alpha'],
        'dependency closure/load order must place dependency before consumer'
    );
} finally {
    removeTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-cycle-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeModuleFixture($tmp, 'alpha', ['beta']);
    writeModuleFixture($tmp, 'beta', ['alpha']);
    $cycleRejected = false;
    try {
        ModuleRegistry::discover($tmp, Version::VERSION);
    } catch (RuntimeException) {
        $cycleRejected = true;
    }
    assertModuleContract($cycleRejected, 'dependency cycles must fail closed');
} finally {
    removeTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-core-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeModuleFixture(
        $tmp,
        'future',
        [],
        'fixture.future',
        ['min' => '99.0.0', 'max_exclusive' => '100.0.0']
    );
    $futureRegistry = ModuleRegistry::discover($tmp, Version::VERSION);
    assertModuleContract($futureRegistry->has('future'), 'valid incompatible manifest must remain discoverable');
    assertModuleContract(
        !$futureRegistry->get('future')->isCompatibleWithCore(Version::VERSION),
        'core incompatibility must be represented separately from manifest validity'
    );
    $assertStillFails = false;
    try {
        $futureRegistry->get('future')->assertCompatibleWithCore(Version::VERSION);
    } catch (RuntimeException) {
        $assertStillFails = true;
    }
    assertModuleContract($assertStillFails, 'explicit compatibility assertion must remain fail-closed');
} finally {
    removeTree($tmp);
}

fwrite(STDOUT, "Module registry contract: OK\n");
