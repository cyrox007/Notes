# Руководство по разработке и установке модулей

Это практическое дополнение к `docs/MODULE_PLATFORM_0.14.md` и `docs/MODULE_RUNTIME_ISOLATION_1.0.md`. Здесь описано, как добавить новый изолированный модуль в Workspace Organizer 1.x и как оператор подключает non-bundled модуль к существующей установке.

## 1. Lifecycle модуля за одну минуту

Workspace Organizer обнаруживает модули по пути:

```text
modules/<module-id>/module.json
```

Валидный non-bundled модуль **не включается автоматически**. При первом обнаружении он регистрируется в `module_lifecycle` со значениями:

```text
configured_state = discovered
effective_state  = discovered
```

Поддерживаемый операторский flow:

```text
copy package -> discovered -> installed -> enabled
```

Команды:

```bash
php bin/control.php modules list
php bin/control.php modules install <module-id>
php bin/control.php modules enable <module-id>
```

Чтобы остановить модуль без удаления его данных:

```bash
php bin/control.php modules disable <module-id>
```

Dependency должна уже находиться в effective state `enabled`, прежде чем можно включить зависимый module. Платформа отклоняет недопустимые lifecycle transitions и не позволяет отключить dependency, пока она требуется другому enabled module.

## 2. Рекомендуемая структура модуля

Небольшой изолированный module рекомендуется оформлять так:

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

На filesystem level обязательны только `module.json` и настроенный runtime entrypoint. Controllers, services, models, views и assets необязательны и должны находиться внутри module, если принадлежат ему.

Не добавляйте product runtime files в общие `app/controllers`, `app/services`, `app/models` или `core/` только ради того, чтобы module начал загружаться.

## 3. Правила идентификатора модуля

Имя каталога и `id` в manifest должны быть идентичны.

Валидные identifiers соответствуют выражению:

```text
^[a-z][a-z0-9_.-]{1,63}$
```

Примеры:

```text
calendar
crm.contacts
inventory-tools
```

Примеры, которые будут отклонены:

```text
Calendar
my module
../calendar
a
```

Capability identifiers используют тот же формат и должны быть глобально уникальными среди активных modules.

## 4. Минимальный module.json

Пример non-bundled module без ownership базы данных:

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

Важные поля:

- `core.min` / `core.max_exclusive` задают совместимость с запущенным Core. Валидный, но несовместимый module записывается как `incompatible` и никогда не включается в runtime.
- `dependencies` содержит IDs модулей, а не имена PHP packages.
- `capabilities` объявляет каждый service, экспортируемый другим modules.
- `package.bundled=false` — обычное значение для независимо устанавливаемого module.
- `package.default_enabled` не включает автоматически новый обнаруженный non-bundled module. Явная установка и включение оператором всё равно обязательны.
- `license.feature` — центральный entitlement identifier. Используйте `null`, если у модуля нет отдельного entitlement. Module не должен реализовывать собственный trust root подписи лицензий.
- production modules обязаны использовать `runtime.mode = isolated`.
- `runtime.entrypoint` должен быть относительным PHP path внутри module root.
- `storage_namespaces` объявляет private-storage namespaces, принадлежащие module.
- `database` объявляет ownership БД, используемый при composition install/update/health.

## 5. runtime.php

Runtime autoloader Core намеренно не загружает namespaces модулей рекурсивно. Изолированный module явно подключает собственные classes и возвращает один `Core\ModuleRuntimeProvider`.

Пример:

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

Entrypoint загружается только когда module входит в effective runtime composition.

## 6. Runtime provider

Каждый изолированный module возвращает объект, реализующий:

```php
Core\ModuleRuntimeProvider
```

У provider четыре обязанности:

- `moduleId()` — должен точно совпадать с `module.json.id`;
- `boot()` — инициализирует module-owned runtime services, которые не меняют router;
- `capabilities()` — экспортирует concrete service objects;
- `registerRoutes()` — регистрирует только routes, принадлежащие этому module.

Минимальный пример:

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

Имена экспортируемых capabilities должны **в точности** совпадать со списком в manifest. Missing, extra или duplicate active capability providers приводят к fail-closed.

## 7. Controller и view модуля

Controller модуля может наследоваться от `Core\Controller` и отрисовывать module-owned view через namespace `@module-id/path`:

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

Template располагается по адресу:

```text
modules/example/views/index.php
```

Пример view:

```php
<?php
/** @var \Core\NativeViewRenderer $view */
?>
<section class="workspace-panel">
    <h1><?= $view->e($title ?? 'Example') ?></h1>
    <link rel="stylesheet" href="<?= $view->e($view->moduleAsset('example', 'style.css')) ?>">
</section>
```

Assets module отдаются только из каталога `assets/` активного module через Core module-asset route. Не публикуйте весь каталог module как public web root.

## 8. Межмодульный доступ

Module не должен подключать internal files другого module по path.

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

Если есть стабильный interface, запрашивайте ожидаемый type:

```php
$service = \Core\ModuleRuntimeLoader::getInstance()
    ->capabilities()
    ->require('example.api', ExampleContract::class);
```

Dependencies, влияющие на boot order, также должны быть объявлены в `module.json.dependencies`.

## 9. Модули с собственной БД

Module, владеющий tables, объявляет их в `module.json`:

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

В текущем 1.x SQL ownership paths являются путями от application root внутри `database/` и `database/migrations/`. Manifest владеет этими paths, даже если SQL files физически лежат в общем database tree.

Для новой migration:

1. добавьте новый immutable SQL file в `database/migrations/`;
2. добавьте его filename в `database/migrations/manifest.json` в каноническом порядке;
3. объявите его полный path в `database.migrations` owning module;
4. никогда не переписывайте migration, уже применённую на customer installations.

Для существующей установки:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

Module не должен напрямую записывать tables другого module как свой integration contract.

## 10. Private storage

Если module владеет файлами вне базы данных, объявите уникальный namespace:

```json
"storage_namespaces": [
  "example"
]
```

Runtime data должны находиться ниже настроенного private-storage root установки, а не в public directory module. Не храните customer uploads, secrets или generated state в `modules/<id>/`.

## 11. Установка non-bundled module в существующую установку

Перед изменением существующей installation сделайте актуальный backup database/private storage.

Скопируйте полный package module в приложение. Минимум:

```text
modules/example/module.json
modules/example/runtime.php
...
```

Если module владеет SQL, также разверните объявленные schema/migration files до запуска команды migration.

Проверьте discovery:

```bash
php bin/control.php modules list
```

Ожидаемое первое состояние нового non-bundled module:

```text
example      configured=discovered  effective=discovered
```

Если у него есть database migrations:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

Пометьте package установленным:

```bash
php bin/control.php modules install example
```

Ожидаемое состояние:

```text
example: configured=installed effective=installed
```

Включите его:

```bash
php bin/control.php modules enable example
```

Ожидаемое состояние:

```text
example: configured=enabled effective=enabled
```

Затем выполните:

```bash
php bin/healthcheck.php
```

и smoke-test routes module.

Если `enable` не проходит из-за disabled dependency, сначала установите/включите dependencies. Если причина — incompatibility module, не обходите compatibility check; вместо этого обновите module или диапазон Core version.

## 12. Отключение модуля

Используйте control plane:

```bash
php bin/control.php modules disable example
```

Отключение неразрушительно. Module удаляется из effective runtime composition, но его customer data не очищаются.

Не удаляйте каталог module вручную, пока lifecycle row сообщает, что module установлен/enabled/disabled. Платформа намеренно считает startup error ситуацию, когда зарегистрированный module неожиданно исчезает с диска.

## 13. Bundled и independently installed modules

First-party module, поставляемый как часть продукта, может использовать:

```json
"package": {
  "bundled": true,
  "default_enabled": true
}
```

Third-party/optional package обычно использует:

```json
"package": {
  "bundled": false,
  "default_enabled": false
}
```

Bundled modules согласуются при первой installation по `default_enabled`. Non-bundled modules всегда требуют явного lifecycle transition из `discovered`.

## 14. Проверка перед распространением

Минимум:

```bash
php -l modules/example/runtime.php
php -l modules/example/ExampleRuntimeProvider.php
php -l modules/example/controllers/ExampleController.php
php bin/control.php modules list --json
php bin/healthcheck.php
```

Для module, разрабатываемого внутри основного репозитория, также запустите module contracts и обновите ожидаемый module set, если вы намеренно добавляете новый repository-owned package:

```bash
php tests/integration/module_registry_contract.php
php tests/integration/module_runtime_composition_contract.php
php tests/integration/module_lifecycle_runtime.php
```

Обычный PR CI должен оставаться зелёным.

## 15. Условия fail-closed

Startup/discovery намеренно завершается ошибкой при:

- malformed или missing `module.json`;
- несовпадении module-directory/manifest ID;
- symlink module roots/manifests/entrypoints;
- отсутствующих dependencies;
- dependency cycles;
- duplicate active capabilities;
- isolated module без валидного in-root entrypoint;
- несовпадении provider ID;
- capability exports, отличающихся от manifest;
- неожиданном отсутствии зарегистрированного module на диске;
- invalid lifecycle state.

Это security/integrity boundaries. Не обходите их изменением проверок discovery в Core.

## 16. Текущее ограничение packaging в 1.x

Runtime boundary локальна для module, но database schema/migration files всё ещё используют канонические application-root paths `database/`. Поэтому independently distributed module с БД сейчас содержит одновременно:

```text
modules/<id>/...
database/<module-schema>.sql
database/migrations/<module-migration>.sql
```

и должен добавить filename своей migration в `database/migrations/manifest.json`.

Будущий module-package format сможет полностью перенести SQL artifacts внутрь package module только после совместного обновления contracts installer/migrator/database-ownership. Не придумывайте альтернативную layout, которую текущий runtime 1.x не понимает.

## Связанная документация

- `docs/MODULE_PLATFORM_0.14.md` — model manifest, composition и lifecycle.
- `docs/MODULE_RUNTIME_ISOLATION_1.0.md` — контракт физической runtime isolation.
- `core/ModuleManifest.php` — авторитетная validation manifest.
- `core/ModuleRegistry.php` — discovery, dependencies и composition.
- `core/ModuleLifecycleStore.php` — persisted lifecycle transitions.
- `core/ModuleRuntimeLoader.php` — isolated runtime loading и capability registry.
- `bin/control.php` — operator CLI управления lifecycle модулей.
