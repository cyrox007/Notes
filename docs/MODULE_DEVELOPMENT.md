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

### Дополнительные проверки линии 1.2

- `php tests/integration/module_database_boundary_12.php` запрещает типичные
  прямые обращения к PDO внутри модулей, но **не** подтверждает права
  собственности на таблицы.
- `php tests/integration/module_schema_ownership_contract_12.php` сверяет
  SQL-литералы с владельцами в `module.json`, обнаруживает явные
  подключения соседнего модуля. Для динамического SQL нужен отдельный анализ.
- `php tests/integration/module_account_ownership_contract_12.php` проверяет
  безопасные вызовы между Admin и владельцами данных.
- `php tests/integration/module_account_guard_sql_12.php` проверяет реальные
  запросы владельцев Messenger/Tasks на изолированной базе.

Доступ к общим платформенным таблицам (например `users`, `roles`)
производится через ядро. Внутренние таблицы другого модуля не становятся
общими только потому, что их можно прочитать через `DatabaseManager`.

## Фоновая проверка перед обезличиванием

Модуль с сохраняемыми таблицами объявляет политику
`background.account_purge_guard` в `module.json`: `none` либо
относительный PHP-путь внутри `background/`.

Путь предоставляет объект `Core\BackgroundAccountRetentionGuard`,
который получает штатный менеджер БД в переменной `$database`.
Проверка используется при отключённой лицензии и без загрузки
`runtime.php`. Модуль не должен подключать чужую схему для её
реализации. При неисправности проверки ядро блокирует обезличивание,
поэтому модулю необходимо хранить и тестировать этот компонент вместе
со схемой данных.

Для контроля:
`php tests/integration/background_account_retention_12.php`;
`php tests/integration/retention_purge_contract.php`.
Проверка на MySQL выполняется в рабочем процессе CI очистки.

## Фоновая очистка устаревших данных

Для собственных таблиц с мягким удалением модуль объявляет
`background.retention_cleanup` в `module.json`.
Поддерживаются `none` и путь `background/retention.php`.

Фоновый поставщик реализует `Core\\ModuleRetentionCleanup`:
`preview(cutoff, limit, context)` считает кандидатов,
`apply(cutoff, limit, context, result)` удаляет только истёкшие
собственные записи. Защищённый `Core\\RetentionCleanupContext`
предоставляет доступ к `DatabaseManager`, проверку существования таблицы,
учёт результатов и удаление файлов. Нельзя обращаться к чужим таблицам
или напрямую реализовывать собственное удаление приватных файлов.

Для связанных с аккаунтом вспомогательных данных модуль может дополнительно
реализовать `Core\\ModuleAccountDataCleanup`. Ядро вызывает этот
контракт внутри своей транзакции обезличивания, поэтому ошибки откатывают
изменение платформенной записи пользователя. Например, Files управляет
собственными `user_storage_quotas`, а не поручает это `RetentionService`.

Поставщик работает без `runtime.php` и независимо от лицензии.
При потере установленного пакета или фоновой политики ядро закрывает
операцию с отказом, не считая модуль пустым.

Проверка: `php tests/integration/module_retention_12_contract.php`,
`php tests/integration/retention_purge_contract.php` и полный MySQL
сценарий очистки. Перед выпуском проверяются дочерние связи,
границы сроков, поведение после отключения модуля и откат из резервной копии.

## Удаление

Отключение и удаление пакета — разные операции. Физическое удаление схемы/storage допускается только отдельным явным контрактом uninstall с recovery/backup story.
