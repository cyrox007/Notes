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

function moduleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Module registry contract failed: {$message}\n");
        exit(1);
    }
}

/** @param list<string> $dependencies */
function writeFixture(
    string $root,
    string $id,
    array $dependencies = [],
    ?string $capability = null,
    array $core = ['min' => '0.13.0-alpha', 'max_exclusive' => '2.0.0'],
): void {
    $dir = $root . '/' . $id;
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create fixture directory {$dir}");
    }

    $capability ??= 'fixture.' . $id;
    file_put_contents($dir . '/module.json', json_encode([
        'schema' => 1,
        'id' => $id,
        'name' => ucfirst($id),
        'version' => '0.13.0',
        'core' => $core,
        'dependencies' => $dependencies,
        'capabilities' => [$capability],
        'package' => ['bundled' => false, 'default_enabled' => true],
        'license' => ['feature' => 'fixture.' . $id],
        'runtime' => ['mode' => 'isolated', 'entrypoint' => 'runtime.php'],
        'storage_namespaces' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

    $provider = <<<'PHP'
<?php
return new class('__ID__', '__CAP__') implements \Core\ModuleRuntimeProvider {
    public function __construct(private string $id, private string $capability) {}
    public function moduleId(): string { return $this->id; }
    public function boot(): void { $GLOBALS['moduleBoots'][] = $this->id; }
    public function capabilities(): array {
        return [$this->capability => new class($this->id) {
            public function __construct(public string $providerId) {}
        }];
    }
    public function registerRoutes(\Core\Router $router): void { $GLOBALS['moduleRoutes'][] = $this->id; }
};
PHP;
    file_put_contents(
        $dir . '/runtime.php',
        str_replace(['__ID__', '__CAP__'], [$id, $capability], $provider)
    );
}

function removeFixtureTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() && !$entry->isLink()
            ? rmdir($entry->getPathname())
            : unlink($entry->getPathname());
    }
    rmdir($path);
}

$registry = ModuleRegistry::discover($root . '/modules', Version::VERSION);
$expected = ['admin', 'files', 'messenger', 'notes', 'profile', 'tasks'];
$isolated = ['files', 'notes', 'tasks'];

moduleAssert(array_keys($registry->all()) === $expected, 'bundled module manifest set drifted');
moduleAssert($registry->defaultComposition() === $expected, 'default bundled composition drifted');

foreach ($registry->all() as $id => $manifest) {
    moduleAssert($manifest->id() === $id, "manifest id mismatch for {$id}");
    moduleAssert(strlen($manifest->integrityHash()) === 64, "{$id} manifest has no SHA-256 integrity hash");
    moduleAssert($manifest->licenseFeature() !== null, "{$id} has no entitlement feature");
    moduleAssert($manifest->isCompatibleWithCore(Version::VERSION), "{$id} is incompatible with current core");

    if (in_array($id, $isolated, true)) {
        moduleAssert($manifest->runtimeMode() === 'isolated', "{$id} must remain physically isolated");
        moduleAssert($manifest->runtimeEntrypoint() === 'runtime.php', "{$id} isolated entrypoint drifted");
    } else {
        moduleAssert($manifest->runtimeMode() === 'legacy', "{$id} remains legacy until its dedicated migration");
        moduleAssert($manifest->runtimeEntrypoint() === null, "legacy {$id} exposes an isolated entrypoint");
    }
}

moduleAssert($registry->resolveComposition(['notes']) === ['notes'], 'Notes composition failed');
moduleAssert($registry->resolveComposition(['tasks']) === ['tasks'], 'Tasks composition failed');
moduleAssert($registry->resolveComposition(['files', 'notes', 'tasks']) === ['files', 'notes', 'tasks'], 'isolated composition order drifted');

$tmp = sys_get_temp_dir() . '/workspace-module-contract-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeFixture($tmp, 'beta');
    writeFixture($tmp, 'alpha', ['beta']);
    $fixtures = ModuleRegistry::discover($tmp, Version::VERSION);
    $composition = $fixtures->resolveComposition(['alpha']);
    moduleAssert($composition === ['beta', 'alpha'], 'dependency must load before consumer');

    $GLOBALS['moduleBoots'] = [];
    $GLOBALS['moduleRoutes'] = [];
    $runtime = ModuleRuntimeLoader::boot($fixtures, $composition);
    moduleAssert(array_keys($runtime->providers()) === ['beta', 'alpha'], 'provider load order drifted');
    moduleAssert($GLOBALS['moduleBoots'] === ['beta', 'alpha'], 'provider boot order drifted');

    $capabilities = $runtime->capabilities();
    moduleAssert($capabilities->isSealed(), 'capability registry must seal after boot');
    moduleAssert(
        $capabilities->providers() === ['fixture.alpha' => 'alpha', 'fixture.beta' => 'beta'],
        'capability ownership drifted'
    );
    $alpha = $capabilities->require('fixture.alpha');
    moduleAssert(($alpha->providerId ?? null) === 'alpha', 'capability lookup returned wrong provider');
    moduleAssert($capabilities->providerModuleId('fixture.beta') === 'beta', 'provider ownership lookup failed');

    foreach ([
        'missing capability' => static fn () => $capabilities->require('fixture.missing'),
        'type mismatch' => static fn () => $capabilities->require('fixture.alpha', DateTimeInterface::class),
        'mutation after seal' => static fn () => $capabilities->register('alpha', 'fixture.late', new stdClass()),
    ] as $label => $operation) {
        $rejected = false;
        try {
            $operation();
        } catch (RuntimeException) {
            $rejected = true;
        }
        moduleAssert($rejected, "{$label} must fail closed");
    }

    $runtime->registerRoutes(Router::getInstance());
    moduleAssert($GLOBALS['moduleRoutes'] === ['beta', 'alpha'], 'route provider order drifted');
} finally {
    unset($GLOBALS['moduleBoots'], $GLOBALS['moduleRoutes']);
    removeFixtureTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-negative-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeFixture($tmp, 'alpha', [], 'fixture.shared');
    writeFixture($tmp, 'beta', [], 'fixture.shared');
    $duplicates = ModuleRegistry::discover($tmp, Version::VERSION);
    $rejected = false;
    try {
        ModuleRuntimeLoader::boot($duplicates, ['alpha', 'beta']);
    } catch (RuntimeException) {
        $rejected = true;
    }
    moduleAssert($rejected, 'duplicate active capability providers must fail closed');
} finally {
    removeFixtureTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-drift-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeFixture($tmp, 'alpha', [], 'fixture.declared');
    $runtimePath = $tmp . '/alpha/runtime.php';
    file_put_contents(
        $runtimePath,
        str_replace('fixture.declared', 'fixture.undeclared', (string) file_get_contents($runtimePath))
    );
    $fixtures = ModuleRegistry::discover($tmp, Version::VERSION);
    $rejected = false;
    try {
        ModuleRuntimeLoader::boot($fixtures, ['alpha']);
    } catch (RuntimeException) {
        $rejected = true;
    }
    moduleAssert($rejected, 'runtime capability exports must match manifest declarations');
} finally {
    removeFixtureTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-entrypoint-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeFixture($tmp, 'alpha');
    $manifestPath = $tmp . '/alpha/module.json';
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);

    unset($manifest['runtime']['entrypoint']);
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $rejected = false;
    try {
        ModuleRegistry::discover($tmp, Version::VERSION);
    } catch (RuntimeException) {
        $rejected = true;
    }
    moduleAssert($rejected, 'isolated module without entrypoint must fail closed');

    $manifest['runtime']['entrypoint'] = '../escape.php';
    file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
    $rejected = false;
    try {
        ModuleRegistry::discover($tmp, Version::VERSION);
    } catch (RuntimeException) {
        $rejected = true;
    }
    moduleAssert($rejected, 'entrypoint traversal must fail closed');
} finally {
    removeFixtureTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-cycle-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeFixture($tmp, 'alpha', ['beta']);
    writeFixture($tmp, 'beta', ['alpha']);
    $rejected = false;
    try {
        ModuleRegistry::discover($tmp, Version::VERSION);
    } catch (RuntimeException) {
        $rejected = true;
    }
    moduleAssert($rejected, 'dependency cycle must fail closed');
} finally {
    removeFixtureTree($tmp);
}

$tmp = sys_get_temp_dir() . '/workspace-module-core-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    writeFixture($tmp, 'future', [], 'fixture.future', ['min' => '99.0.0', 'max_exclusive' => '100.0.0']);
    $future = ModuleRegistry::discover($tmp, Version::VERSION)->get('future');
    moduleAssert(!$future->isCompatibleWithCore(Version::VERSION), 'future module unexpectedly compatible');
    $rejected = false;
    try {
        $future->assertCompatibleWithCore(Version::VERSION);
    } catch (RuntimeException) {
        $rejected = true;
    }
    moduleAssert($rejected, 'explicit compatibility assertion must fail closed');
} finally {
    removeFixtureTree($tmp);
}

echo "Module registry contract: OK\n";
