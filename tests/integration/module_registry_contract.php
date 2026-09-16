<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/core/Version.php';
require_once $root . '/core/ModuleManifest.php';
require_once $root . '/core/ModuleRegistry.php';
require_once $root . '/core/ModuleRuntimeProvider.php';
require_once $root . '/core/ModuleCapabilityRegistry.php';
require_once $root . '/core/ModuleRuntimeLoader.php';
require_once $root . '/core/Router.php';

use Core\ModuleRegistry;
use Core\ModuleRuntimeLoader;
use Core\Router;
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

    $declaredCapability = $capability !== '' ? $capability : 'fixture.' . $id;
    $manifest = [
        'schema' => 1,
        'id' => $id,
        'name' => ucfirst($id),
        'version' => '0.13.0',
        'core' => $core !== [] ? $core : ['min' => '0.13.0-alpha', 'max_exclusive' => '0.15.0'],
        'dependencies' => $dependencies,
        'capabilities' => [$declaredCapability],
        'package' => ['bundled' => false, 'default_enabled' => true],
        'license' => ['feature' => 'fixture.' . $id],
        'runtime' => ['mode' => 'isolated', 'entrypoint' => 'runtime.php'],
        'storage_namespaces' => [],
    ];

    file_put_contents(
        $dir . '/module.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );

    $provider = <<<'PHP'
<?php
return new class('__MODULE_ID__', '__CAPABILITY__') implements \Core\ModuleRuntimeProvider {
    public function __construct(private string $id, private string $capability) {}
    public function moduleId(): string { return $this->id; }
    public function boot(): void { $GLOBALS['moduleRuntimeBoots'][] = $this->id; }
    public function capabilities(): array {
        return [
            $this->capability => new class($this->id) {
                public function __construct(public string $providerId) {}
            },
        ];
    }
    public function registerRoutes(\Core\Router $router): void { $GLOBALS['moduleRuntimeRoutes'][] = $this->id; }
};
PHP;
    $provider = str_replace(
        ['__MODULE_ID__', '__CAPABILITY__'],
        [$id, $declaredCapability],
        $provider
    );
    file_put_contents($dir . '/runtime.php', $provider);
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
    if ($id === 'notes') {
        assertModuleContract($manifest->runtimeMode() === 'isolated', 'Notes must be the first physically isolated bundled module');
        assertModuleContract($manifest->runtimeEntrypoint() === 'runtime.php', 'Notes isolated entrypoint drifted');
    } else {
        assertModuleContract($manifest->runtimeMode() === 'legacy', "{$id} remains legacy until its dedicated migration");
        assertModuleContract($manifest->runtimeEntrypoint() === null, "legacy {$id} must not expose an isolated entrypoint");
    }
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
    $composition = $fixtureRegistry->resolveComposition(['alpha']);
    assertModuleContract(
        $composition === ['beta', 'alpha'],
        'dependency closure/load order must place dependency before consumer'
    );

    $GLOBALS['moduleRuntimeBoots'] = [];
    $GLOBALS['moduleRuntimeRoutes'] = [];
    $runtime = ModuleRuntimeLoader::boot($fixtureRegistry, $composition);
    assertModuleContract(
        array_keys($runtime->providers()) === ['beta', 'alpha'],
        'isolated providers must load in dependency-first runtime composition order'
    );
    assertModuleContract(
        $GLOBALS['moduleRuntimeBoots'] === ['beta', 'alpha'],
        'isolated provider boot order drifted'
    );

    $capabilities = $runtime->capabilities();
    assertModuleContract($capabilities->isSealed(), 'capability registry must be immutable after runtime boot');
    assertModuleContract(
        $capabilities->providers() === [
            'fixture.alpha' => 'alpha',
            'fixture.beta' => 'beta',
        ],
        'capability-to-provider registry does not match effective runtime composition'
    );
    $alphaService = $capabilities->require('fixture.alpha');
    assertModuleContract(
        property_exists($alphaService, 'providerId') && $alphaService->providerId === 'alpha',
        'capability lookup did not return the provider-owned service object'
    );
    assertModuleContract(
        $capabilities->providerModuleId('fixture.beta') === 'beta',
        'capability provider ownership lookup failed'
    );

    $missingCapabilityRejected = false;
    try {
        $capabilities->require('fixture.missing');
    } catch (RuntimeException) {
        $missingCapabilityRejected = true;
    }
    assertModuleContract($missingCapabilityRejected, 'missing capability lookup must fail closed');

    $typeMismatchRejected = false;
    try {
        $capabilities->require('fixture.alpha', DateTimeInterface::class);
    } catch (RuntimeException) {
        $typeMismatchRejected = true;
    }
    assertModuleContract($typeMismatchRejected, 'capability contract type mismatch must fail closed');

    $sealedMutationRejected = false;
    try {
        $capabilities->register('alpha', 'fixture.late', new stdClass());
    } catch (RuntimeException) {
        $sealedMutationRejected = true;
    }
    assertModuleContract($sealedMutationRejected, 'sealed capability registry accepted a late provider');

    $runtime->registerRoutes(Router::getInstance());
    assertModuleContract(
        $GLOBALS['moduleRuntimeRoutes'] === ['beta', 'alpha'],
        'isolated route providers were not invoked in runtime composition order'
    );
} finally {
    unset($GLOBALS['moduleRuntimeBoots'], $GLOBALS['moduleRuntimeRoutes']);
    removeTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-capability-duplicate-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeModuleFixture($tmp, 'alpha', [], 'fixture.shared');
    writeModuleFixture($tmp, 'beta', [], 'fixture.shared');
    $duplicateRegistry = ModuleRegistry::discover($tmp, Version::VERSION);
    $duplicateRejected = false;
    try {
        ModuleRuntimeLoader::boot($duplicateRegistry, ['alpha', 'beta']);
    } catch (RuntimeException) {
        $duplicateRejected = true;
    }
    assertModuleContract($duplicateRejected, 'duplicate active providers for one capability must fail closed');
} finally {
    removeTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-capability-drift-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeModuleFixture($tmp, 'alpha', [], 'fixture.declared');
    $runtimePath = $tmp . '/alpha/runtime.php';
    $runtimeSource = (string) file_get_contents($runtimePath);
    file_put_contents($runtimePath, str_replace('fixture.declared', 'fixture.undeclared', $runtimeSource));
    $driftRegistry = ModuleRegistry::discover($tmp, Version::VERSION);
    $driftRejected = false;
    try {
        ModuleRuntimeLoader::boot($driftRegistry, ['alpha']);
    } catch (RuntimeException) {
        $driftRejected = true;
    }
    assertModuleContract($driftRejected, 'runtime capability exports must exactly match manifest declarations');
} finally {
    removeTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-entrypoint-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeModuleFixture($tmp, 'alpha');
    $path = $tmp . '/alpha/module.json';
    $manifest = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    unset($manifest['runtime']['entrypoint']);
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));

    $missingEntrypointRejected = false;
    try {
        ModuleRegistry::discover($tmp, Version::VERSION);
    } catch (RuntimeException) {
        $missingEntrypointRejected = true;
    }
    assertModuleContract($missingEntrypointRejected, 'isolated module without entrypoint must fail closed');

    $manifest['runtime']['entrypoint'] = '../escape.php';
    file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR));
    $escapingEntrypointRejected = false;
    try {
        ModuleRegistry::discover($tmp, Version::VERSION);
    } catch (RuntimeException) {
        $escapingEntrypointRejected = true;
    }
    assertModuleContract($escapingEntrypointRejected, 'isolated entrypoint traversal must fail closed');
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
