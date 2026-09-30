# Разработка и установка модулей

Актуальный платформенный контракт: [MODULE_PLATFORM.md](MODULE_PLATFORM.md).

## Структура

Новый модуль находится в:

```text
modules/<module-id>/
├── module.json
├── runtime.php
├── controllers/
├── services/
├── views/
├── assets/
└── ...
```

Production-модуль использует `runtime.mode=isolated`.

## Manifest

Минимальный пример:

```json
{
  "schema_version": 1,
  "id": "example",
  "name": "Example",
  "version": "1.0.0",
  "core": {
    "min": "1.0.0",
    "max_exclusive": "2.0.0"
  },
  "dependencies": [],
  "capabilities": ["workspace.example"],
  "package": {
    "bundled": false,
    "default_enabled": false,
    "required": false
  },
  "license": {
    "feature": "workspace.example"
  },
  "runtime": {
    "mode": "isolated",
    "entrypoint": "runtime.php"
  },
  "storage_namespaces": ["example"]
}
```

Правила:

- имя каталога совпадает с `id`;
- зависимости указываются по module id;
- capability глобально уникальна в активной композиции;
- обычный внешний модуль не может объявить себя `required=true`;
- production-модуль обязан иметь явный `license.feature`;
- entrypoint не выходит за корень модуля.

## Provider

`runtime.php` возвращает объект, реализующий `Core\ModuleRuntimeProvider`.

Provider:

- выполняет минимальный `boot()`;
- регистрирует собственные маршруты;
- экспортирует ровно те capability, которые объявлены в manifest.

Core не должен вручную подключать контроллеры или сервисы нового модуля.

## Lifecycle

Новый небандловый пакет после обнаружения не включается автоматически:

```text
discovered -> installed -> enabled
```

Управление:

```bash
php bin/control.php modules list
php bin/control.php modules install <module-id>
php bin/control.php modules enable <module-id>
php bin/control.php modules disable <module-id>
```

Лицензионный запрет и операторское отключение не удаляют данные.

## База данных

Модуль владеет своей схемой и compatibility-upgrades.

Нельзя:

- использовать таблицу другого модуля как внутренний API;
- создавать «общую» таблицу без явного владельца;
- менять уже применённую миграцию задним числом.

Если нескольким модулям нужна одна возможность, создаётся capability с владельцем данных.

## Storage

Private storage объявляется модулем и находится под `PRIVATE_STORAGE_PATH` либо в другом явно разрешённом внешнем namespace. Browser не должен получать физический путь.

## Межмодульная интеграция

Потребитель получает сервис через capability и обязан корректно работать, если capability отсутствует.

Нельзя делать скрытую обязательную зависимость через:

- прямой include соседнего модуля;
- чтение его таблиц;
- знание его private storage;
- жёстко заданный URL на маршрут, который может быть отключён.

## Лицензирование

`license.feature` проверяется центральным сервисом Core. Модуль не реализует свой trust root и не читает приватные ключи.

Отсутствие feature означает, что модуль не входит в runtime.

## Проверки перед merge

Минимум:

- manifest/registry contract;
- fresh schema и upgrade;
- install/enable/disable/recovery;
- отсутствие fatal error при выключенном модуле;
- permissions/ACL;
- browser lifecycle для пользовательского сценария;
- проверка зависимостей и capability;
- нагрузочный бюджет согласно [PERFORMANCE_1000_USERS.md](PERFORMANCE_1000_USERS.md).

## Удаление

Отключение и удаление пакета — разные операции. Физическое удаление схемы/storage допускается только отдельным явным контрактом uninstall с recovery/backup story.
