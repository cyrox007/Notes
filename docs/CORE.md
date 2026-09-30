# Ядро Workspace Organizer

Актуально для стабильной линии **1.0.13**.

## Назначение Core

Core содержит только общие платформенные механизмы. Notes, Tasks, File Manager, Messenger, Profile и Admin являются изолированными модулями и подключаются через общий runtime.

## Bootstrap

HTTP entry point — `index.php`. Базовая загрузка выполняется через `core.php`.

Текущий порядок:

1. `Core\Environment` загружает окружение из `.env`;
2. `Core\RuntimeAutoloader` регистрирует внутреннюю загрузку классов;
3. настраивается безопасность сессии;
4. `ModuleRegistry` обнаруживает manifests;
5. `ModuleLifecycleStore` согласует persisted lifecycle и лицензионные разрешения;
6. `ModuleRuntimeLoader` загружает provider только фактически активных модулей;
7. Router получает core-маршруты и маршруты активных providers.

Runtime 1.0.13 не требует Composer, `vendor/`, Smarty или Workerman.

## Маршрутизация

`core/routerConfig.php` содержит только общесистемные маршруты: главную страницу, аутентификацию, 2FA и восстановление лицензии.

Маршруты прикладных модулей регистрируются их `ModuleRuntimeProvider` только когда модуль входит в активную композицию.

Динамические параметры Router типизируются шаблоном маршрута. Изменяющие HTTP-запросы защищаются CSRF и дополнительно проходят серверную авторизацию.

## Request и Controller

`Core\Request` инкапсулирует GET/POST/JSON/FILES/session.

`Core\Controller` использует внутренний `NativeViewRenderer`. Представления модулей разрешаются через зарегистрированные view roots активного `ModuleRuntimeLoader`.

Общий shell строит доступность разделов из двух условий:

- соответствующая capability активного модуля существует;
- пользователь имеет требуемое permission.

Скрытый пункт меню не является границей безопасности: service/controller повторно проверяет права.

## Модульная композиция

`ModuleRegistry`:

- валидирует `module.json`;
- проверяет зависимости;
- строит детерминированный порядок;
- согласует persisted lifecycle;
- формирует `enabledComposition()`.

`ModuleRuntimeLoader`:

- загружает только isolated entrypoints активных модулей;
- проверяет соответствие provider идентификатору модуля;
- регистрирует capability;
- регистрирует view/assets roots;
- передаёт providers Router.

Полный контракт: [MODULE_PLATFORM.md](MODULE_PLATFORM.md).

## Лицензирование

`LicenseModuleEntitlementService` связывает подписанный `features` лицензии с `license.feature` manifest.

Отсутствующий feature закрыто блокирует модуль, но не удаляет его данные и операторское состояние.

Core-маршрут восстановления лицензии остаётся доступным независимо от Admin runtime.

## Представления

UI формируется PHP-шаблонами через `NativeViewRenderer`.

Общие части находятся в:

- `app/views/core/`;
- `app/views/^shared/`.

Модульные views/assets принадлежат соответствующим `modules/<id>/` и доступны только активному runtime.

CSP использует nonce и не требует `unsafe-eval`.

## База данных

Каноническая fresh-схема определяется `Core\DatabaseOwnership`: core-owned schema плюс схемы установленных модулей.

`database/migrations/` содержит compatibility-upgrades для существующих установок и не является каноническим описанием новой БД.

Подробнее: [DB_ARCHITECTURE.md](DB_ARCHITECTURE.md).

## Private storage

Пользовательские файлы хранятся вне document root в `PRIVATE_STORAGE_PATH`.

Основные пространства:

- `file_manager/`;
- `messenger/`;
- `notes/`;
- `users/`;
- `rate-limit/`;
- `logs/`;
- `legacy/`.

Browser работает только с логическими ID/UID; физический путь не является пользовательским URL.

## Realtime Messenger

Основной гарантированный transport — authenticated HTTP Long Poll.

Нативный `ws_server/server.php` — необязательный быстрый канал. Оба пути используют общую серверную бизнес-логику и authorization boundary.

Подробнее: [MESSENGER_SERVER.md](MESSENGER_SERVER.md).

## RBAC

Доступ складывается из:

1. состояния аккаунта;
2. лицензионного entitlement;
3. RBAC permission;
4. module policy;
5. ACL/ownership конкретного объекта.

Подробнее: [RBAC.md](RBAC.md).

## Обновления

Updater использует отдельный trust-domain Ed25519, проверяет manifest/signature/package до исполнения, ведёт внешний журнал транзакции, backup, maintenance, post-health и rollback/recovery.

Подробнее:

- [UPDATES.md](UPDATES.md)
- [UPDATER_LIVE_APPLY.md](UPDATER_LIVE_APPLY.md)
- [UPDATE_REMOTE_DELIVERY.md](UPDATE_REMOTE_DELIVERY.md)

## Правило развития Core

Новая прикладная функция не переносится в Core ради удобства. Если возможность принадлежит конкретному продукту, она должна жить в модуле и взаимодействовать с платформой через стабильный контракт.
