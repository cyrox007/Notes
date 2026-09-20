# Module development and installation guide

This guide is the practical companion to `docs/MODULE_PLATFORM_0.14.md` and
`docs/MODULE_RUNTIME_ISOLATION_1.0.md`. It describes how to add a new isolated
module to Workspace Organizer 1.x and how an operator introduces a non-bundled
module into an existing installation.

## 1. Module lifecycle in one minute

Workspace Organizer discovers modules from:

```text
modules/<module-id>/module.json
```

A valid non-bundled module is **not auto-enabled**. On first discovery it is
registered in `module_lifecycle` with:

```text
configured_state = discovered
effective_state  = discovered
```

The supported operator flow is:

```text
copy package -> discovered -> installed -> enabled
```

Commands:

```bash
php bin/control.php modules list
php bin/control.php modules install <module-id>
php bin/control.php modules enable <module-id>
```

To stop a module without deleting its data:

```bash
php bin/control.php modules disable <module-id>
```

A dependency must already be effectively enabled before a dependent module can
be enabled. The platform rejects invalid lifecycle transitions and refuses to
disable a dependency while another enabled module requires it.

## 2. Recommended module layout

A small isolated module should look like this:

```text
modules/example/
├── module.json
├── runtime.php
├── ExampleCapability.php
├── ExampleRuntimeProvider.php
├── controllers/
│   └── ExampleController.php
├── middlewares/
├── models/
├── services/
├── views/
│   └── index.php
└── assets/
    └── style.css
```

Only `module.json` and the configured runtime entrypoint are mandatory at the
filesystem level. Controllers, services, models, views and assets are optional
and should live inside the module when they belong to that module.

Do not add product runtime files to shared `app/controllers`,
`app/services`, `app/models` or `core/` just to make the module load.

## 3. Module identifier rules

The directory name and manifest `id` must be identical.

Valid identifiers match:

```text
^[a-z][a-z0-9_.-]{1,63}$
```

Examples:

```text
calendar
crm.contacts
inventory-tools
```

Examples that are rejected:

```text
Calendar
my module
../calendar
a
```

Capability identifiers use the same identifier format and must be globally
unique among active modules.

## 4. Minimal module.json

Example for a non-bundled module with no database ownership:

```json
{
  "schema": 1,
  "id": "example",
  "name": "Example",
  "version": "1.0.0",
  "core": {
    "min": "1.0.0",
    "max_exclusive": "2.0.0"
  },
  "dependencies": [],
  "capabilities": [
    "example.api"
  ],
  "package": {
    "bundled": false,
    "default_enabled": false
  },
  "license": {
    "feature": null
  },
  "runtime": {
    "mode": "isolated",
    "entrypoint": "runtime.php"
  },
  "storage_namespaces": [],
  "database": {
    "tables": [],
    "schemas": [],
    "migrations": []
  }
}
```

Important fields:

- `core.min` / `core.max_exclusive` define compatibility with the running
  Core. A valid but incompatible module is recorded as `incompatible` and is
  never runtime-enabled.
- `dependencies` contains module IDs, not PHP package names.
- `capabilities` declares every service exported to other modules.
- `package.bundled=false` is the normal value for an independently installed
  module.
- `package.default_enabled` does not auto-enable a newly discovered
  non-bundled module. Explicit operator installation and enablement are still
  required.
- `license.feature` is the central entitlement identifier. Use `null` for a
  module that has no separate entitlement. A module must not implement its own
  license-signing trust root.
- production modules must use `runtime.mode = isolated`.
- `runtime.entrypoint` must be a relative PHP path inside the module root.
- `storage_namespaces` declares private-storage namespaces owned by the
  module.
- `database` declares database ownership used by install/update/health
  composition.

## 5. runtime.php

The Core runtime autoloader deliberately does not recursively autoload module
namespaces. An isolated module loads its own classes explicitly and returns one
`Core\ModuleRuntimeProvider`.

Example:

```php
<?php

declare(strict_types=1);

$root = __DIR__;

foreach ([
    '/controllers/ExampleController.php',
    '/ExampleCapability.php',
    '/ExampleRuntimeProvider.php',
] as $file) {
    $path = $root . $file;
    if (!is_file($path) || is_link($path)) {
        throw new RuntimeException('Example runtime file is missing or unsafe: ' . $file);
    }
    require_once $path;
}

return new \Modules\Example\ExampleRuntimeProvider();
```

The entrypoint is loaded only when the module belongs to the effective runtime
composition.

## 6. Runtime provider

Every isolated module returns an object implementing:

```php
Core\ModuleRuntimeProvider
```

The provider has four responsibilities:

- `moduleId()` — must exactly match `module.json.id`;
- `boot()` — initialize module-owned runtime services that do not mutate the
  router;
- `capabilities()` — export concrete service objects;
- `registerRoutes()` — register only routes owned by this module.

Minimal example:

```php
<?php

declare(strict_types=1);

namespace Modules\Example;

use App\Middlewares\LoginRequared;
use Core\ModuleRuntimeProvider;
use Core\Router;
use Modules\Example\Controllers\ExampleController;

final class ExampleRuntimeProvider implements ModuleRuntimeProvider
{
    private ExampleCapability $capability;

    public function __construct()
    {
        $this->capability = new ExampleCapability();
    }

    public function moduleId(): string
    {
        return 'example';
    }

    public function boot(): void
    {
    }

    public function capabilities(): array
    {
        return [
            'example.api' => $this->capability,
        ];
    }

    public function registerRoutes(Router $router): void
    {
        $router->group('/example')
            ->add(
                'GET',
                '/',
                [ExampleController::class, 'index'],
                [LoginRequared::class],
                'example_index'
            )
            ->endGroup();
    }
}
```

The exported capability names must match the manifest capability list
**exactly**. Missing, extra or duplicate active capability providers fail
closed.

## 7. Controller and module view

A module controller can extend `Core\Controller` and render a module-owned
view with the `@module-id/path` namespace:

```php
<?php

declare(strict_types=1);

namespace Modules\Example\Controllers;

use Core\Controller;
use Core\Request;

final class ExampleController extends Controller
{
    public function index(Request $request): void
    {
        $this->render_template('@example/index', [
            'title' => 'Example module',
        ]);
    }
}
```

The template is then:

```text
modules/example/views/index.php
```

Example view:

```php
<?php
/** @var \Core\NativeViewRenderer $view */
?>
<section class="workspace-panel">
    <h1><?= $view->e($title ?? 'Example') ?></h1>
    <link rel="stylesheet" href="<?= $view->e($view->moduleAsset('example', 'style.css')) ?>">
</section>
```

Module assets are served only from the active module's `assets/` directory
through the Core module-asset route. Do not expose the whole module directory as
a public web root.

## 8. Cross-module access

A module must not require another module's internal files by path.

Provider:

```php
public function capabilities(): array
{
    return ['example.api' => $this->capability];
}
```

Consumer:

```php
$service = \Core\ModuleRuntimeLoader::getInstance()
    ->capabilities()
    ->require('example.api');
```

When a stable interface exists, request the expected type:

```php
$service = \Core\ModuleRuntimeLoader::getInstance()
    ->capabilities()
    ->require('example.api', ExampleContract::class);
```

Dependencies that affect boot order must also be declared in
`module.json.dependencies`.

## 9. Database-backed modules

A module that owns tables declares them in `module.json`:

```json
"database": {
  "tables": [
    "example_items"
  ],
  "schemas": [
    "database/example_schema.sql"
  ],
  "migrations": [
    "database/migrations/20260920_example_items.sql"
  ]
}
```

Current 1.x SQL ownership paths are application-root paths under
`database/` and `database/migrations/`. The manifest owns those paths even
though the SQL files are physically stored in the common database tree.

For a new migration:

1. add a new immutable SQL file under `database/migrations/`;
2. append its filename to `database/migrations/manifest.json` in canonical
   order;
3. declare its full path in the owning module's `database.migrations`;
4. never rewrite a migration that has already been applied on customer
   installations.

For an existing installation:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

A module must not write another module's tables directly as its integration
contract.

## 10. Private storage

If the module owns files outside the database, declare a unique namespace:

```json
"storage_namespaces": [
  "example"
]
```

Runtime data belongs below the installation's configured private-storage root,
not below the public module directory. Do not store customer uploads, secrets
or generated state in `modules/<id>/`.

## 11. Installing a non-bundled module on an existing installation

Before changing an existing installation, make a current database/private
storage backup.

Copy the complete module package into the application. At minimum:

```text
modules/example/module.json
modules/example/runtime.php
...
```

If the module owns SQL, also deploy its declared schema/migration files before
running the migration command.

Then verify discovery:

```bash
php bin/control.php modules list
```

Expected first state for a new non-bundled module:

```text
example      configured=discovered  effective=discovered
```

If it has database migrations:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

Mark the package installed:

```bash
php bin/control.php modules install example
```

Expected state:

```text
example: configured=installed effective=installed
```

Enable it:

```bash
php bin/control.php modules enable example
```

Expected state:

```text
example: configured=enabled effective=enabled
```

Then run:

```bash
php bin/healthcheck.php
```

and smoke-test the module's routes.

If `enable` fails because a dependency is disabled, install/enable dependencies
first. If it fails because the module is incompatible, do not bypass the
compatibility check; update the module or Core version range instead.

## 12. Disabling a module

Use the control plane:

```bash
php bin/control.php modules disable example
```

Disabling is non-destructive. It removes the module from the effective runtime
composition but does not purge its customer data.

Do not simply delete a module directory while the lifecycle row still says the
module is installed/enabled/disabled. The platform intentionally treats a
registered module that unexpectedly disappears from disk as a startup error.

## 13. Bundled versus independently installed modules

A first-party module shipped as part of the product may use:

```json
"package": {
  "bundled": true,
  "default_enabled": true
}
```

A third-party/optional package normally uses:

```json
"package": {
  "bundled": false,
  "default_enabled": false
}
```

Bundled modules are reconciled on first installation according to
`default_enabled`. Non-bundled modules always require an explicit lifecycle
transition from `discovered`.

## 14. Validation before distribution

At minimum:

```bash
php -l modules/example/runtime.php
php -l modules/example/ExampleRuntimeProvider.php
php -l modules/example/controllers/ExampleController.php
php bin/control.php modules list --json
php bin/healthcheck.php
```

For a module developed inside the main repository, also run the module
contracts and update their expected module set when intentionally adding a new
repository-owned package:

```bash
php tests/integration/module_registry_contract.php
php tests/integration/module_runtime_composition_contract.php
php tests/integration/module_lifecycle_runtime.php
```

The normal PR CI must remain green.

## 15. Fail-closed conditions

Startup/discovery intentionally fails for:

- malformed or missing `module.json`;
- module-directory/manifest ID mismatch;
- symlinked module roots/manifests/entrypoints;
- missing dependencies;
- dependency cycles;
- duplicate active capabilities;
- isolated module without a valid in-root entrypoint;
- provider ID mismatch;
- capability exports that differ from the manifest;
- registered module unexpectedly missing from disk;
- invalid lifecycle state.

These failures are security/integrity boundaries. Do not work around them by
editing Core discovery checks.

## 16. Current 1.x packaging limitation

The runtime boundary is module-local, but database schema/migration files still
use canonical application-root `database/` paths. Therefore a DB-backed
independently distributed module package currently contains both:

```text
modules/<id>/...
database/<module-schema>.sql
database/migrations/<module-migration>.sql
```

and must integrate its migration filename into
`database/migrations/manifest.json`.

A future module-package format can move these SQL artifacts fully under the
module package only after installer/migrator/database-ownership contracts are
updated together. Do not invent an alternative layout that the current 1.x
runtime does not understand.

## Related documentation

- `docs/MODULE_PLATFORM_0.14.md` — manifest, composition and lifecycle model.
- `docs/MODULE_RUNTIME_ISOLATION_1.0.md` — physical runtime-isolation contract.
- `core/ModuleManifest.php` — authoritative manifest validation.
- `core/ModuleRegistry.php` — discovery, dependencies and composition.
- `core/ModuleLifecycleStore.php` — persisted lifecycle transitions.
- `core/ModuleRuntimeLoader.php` — isolated runtime loading and capability registry.
- `bin/control.php` — operator module lifecycle CLI.
