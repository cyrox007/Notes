<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('SITEPATH')) {
    define('SITEPATH', $root);
}
$_SERVER['HTTP_HOST'] = 'localhost';

require_once $root . '/core/config.php';
require_once $root . '/core/Version.php';
require_once $root . '/core/DatabaseManager.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/ModuleLifecycleStore.php';
require_once $root . '/core/ModuleRegistry.php';

use Core\DatabaseManager;
use Core\ModuleLifecycleStore;
use Core\ModuleRegistry;
use Core\Version;

function lifecycleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Module lifecycle contract failed: ' . $message);
    }
}

/** @param list<string> $dependencies */
function lifecycleFixture(
    string $root,
    string $id,
    array $dependencies = [],
    bool $bundled = false,
    bool $defaultEnabled = true,
    array $core = []
): void {
    $dir = $root . '/' . $id;
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Cannot create module lifecycle fixture');
    }
    $manifest = [
        'schema' => 1,
        'id' => $id,
        'name' => ucfirst($id),
        'version' => '0.14.0-beta.1',
        'core' => $core !== [] ? $core : ['min' => '0.13.0-alpha', 'max_exclusive' => '0.15.0'],
        'dependencies' => $dependencies,
        'capabilities' => ['fixture.' . $id],
        'package' => ['bundled' => $bundled, 'default_enabled' => $defaultEnabled],
        'license' => ['feature' => 'fixture.' . $id],
        'runtime' => ['mode' => 'isolated', 'entrypoint' => 'runtime.php'],
        'storage_namespaces' => [],
    ];
    file_put_contents(
        $dir . '/module.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
}

function lifecycleRemoveTree(string $path): void
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

$db = DatabaseManager::getInstance();
$db->execute('DELETE FROM module_lifecycle');
$store = new ModuleLifecycleStore($db);

$registry = ModuleRegistry::boot($root . '/modules', Version::VERSION, $store);
$rows = $registry->lifecycle();
lifecycleAssert(count($rows) === 6, 'all bundled manifests must be registered');
lifecycleAssert($registry->enabledComposition() === ['admin', 'files', 'messenger', 'notes', 'profile', 'tasks'], 'bundled default composition must be enabled');
foreach ($rows as $moduleId => $row) {
    lifecycleAssert($row['configured_state'] === 'enabled', "{$moduleId} configured state must initialize enabled");
    lifecycleAssert($row['effective_state'] === 'enabled', "{$moduleId} effective state must initialize enabled");
    lifecycleAssert(strlen((string) $row['manifest_hash']) === 64, "{$moduleId} manifest hash must be persisted");
}

$notes = $registry->transitionLifecycle('notes', 'disabled');
lifecycleAssert($notes['configured_state'] === 'disabled' && $notes['effective_state'] === 'disabled', 'explicit disable must persist');
lifecycleAssert(!$registry->isRuntimeEnabled('notes'), 'disabled module must leave runtime composition');
$notes = $registry->transitionLifecycle('notes', 'enabled');
lifecycleAssert($notes['effective_state'] === 'enabled', 'explicit re-enable must restore runtime state');

$db->execute('DELETE FROM module_lifecycle');
$tmp = sys_get_temp_dir() . '/workspace-lifecycle-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    lifecycleFixture($tmp, 'beta');
    lifecycleFixture($tmp, 'alpha', ['beta']);
    $fixture = ModuleRegistry::boot($tmp, Version::VERSION, $store);
    lifecycleAssert($fixture->effectiveState('alpha') === 'discovered', 'non-bundled module must not auto-enable');
    lifecycleAssert($fixture->effectiveState('beta') === 'discovered', 'non-bundled dependency must not auto-enable');

    $fixture->transitionLifecycle('alpha', 'installed');
    $blockedEnable = false;
    try {
        $fixture->transitionLifecycle('alpha', 'enabled');
    } catch (RuntimeException) {
        $blockedEnable = true;
    }
    lifecycleAssert($blockedEnable, 'consumer cannot enable before dependency');

    $fixture->transitionLifecycle('beta', 'installed');
    $fixture->transitionLifecycle('beta', 'enabled');
    $fixture->transitionLifecycle('alpha', 'enabled');
    lifecycleAssert($fixture->enabledComposition() === ['beta', 'alpha'], 'enabled runtime composition must remain dependency-first');

    $blockedDisable = false;
    try {
        $fixture->transitionLifecycle('beta', 'disabled');
    } catch (RuntimeException) {
        $blockedDisable = true;
    }
    lifecycleAssert($blockedDisable, 'enabled dependency cannot be disabled under an enabled consumer');

    $fixture->transitionLifecycle('alpha', 'disabled');
    $fixture->transitionLifecycle('beta', 'disabled');
    lifecycleAssert($fixture->enabledComposition() === [], 'disabled composition must be empty');
} finally {
    lifecycleRemoveTree($tmp);
}

$db->execute('DELETE FROM module_lifecycle');
$tmp = sys_get_temp_dir() . '/workspace-lifecycle-incompatible-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    lifecycleFixture(
        $tmp,
        'future',
        [],
        true,
        true,
        ['min' => '99.0.0', 'max_exclusive' => '100.0.0']
    );
    $future = ModuleRegistry::boot($tmp, Version::VERSION, $store);
    $row = $future->lifecycleFor('future');
    lifecycleAssert($row['configured_state'] === 'enabled', 'incompatible module must retain configured intent');
    lifecycleAssert($row['effective_state'] === 'incompatible', 'core mismatch must reconcile to incompatible');
    lifecycleAssert(!$future->isRuntimeEnabled('future'), 'incompatible module must never be runtime-enabled');
    lifecycleAssert(str_contains((string) $row['last_error'], 'requires core'), 'incompatible state must retain diagnostic reason');
} finally {
    lifecycleRemoveTree($tmp);
}

$db->execute('DELETE FROM module_lifecycle');
fwrite(STDOUT, "Persisted module lifecycle contract: OK\n");
