# Workspace Organizer 0.14 — RBAC и границы авторизации

## Цель

0.14 отделяет **состояние учётной записи** от **роли и полномочий**. Предыдущая модель хранила в `users.role` одновременно и authorization role (`1`, `111`, `888`), и состояния (`899` inactive, `999` blocked). Такая схема не масштабируется на пользовательские роли и может уничтожить исходную роль при блокировке/разблокировке.

Архитектурная модель основана на полезных принципах `cyrox007/BaseProjectPython`: отдельные roles/permissions, связи user↔role и role↔permission и централизованная permission check. Код и Python ORM-модели не копируются; модель адаптирована под PHP-приложение, существующий ACL и модульную архитектуру 0.14.

## Четыре независимых уровня доступа

1. **Account state** — может ли учётная запись вообще выполнять authenticated действия: `active`, `inactive`, `blocked`.
2. **Module entitlement / license** — доступна ли установленной поставке соответствующая коммерческая возможность или модуль. Этот слой будет подключён позже вместе с licensing subsystem.
3. **RBAC permission** — имеет ли пользователь право использовать функцию: например `admin.users.manage` или `notes.use`.
4. **Resource ACL / ownership** — имеет ли пользователь право именно на этот объект: конкретную заметку, файл, задачу, диалог или сообщение.

Наличие `notes.use` не разрешает читать чужую заметку. Наличие `messenger.use` не расширяет dialog membership. License entitlement не заменяет пользовательские permissions. Эти проверки нельзя объединять в один флаг.

## Схема данных

### `roles`

Содержит стабильный строковый `code`, название, описание и признак системной роли.

Начальные системные роли:

- `superadmin`;
- `admin`;
- `user`.

В дальнейшем могут появиться кастомные роли без введения новых числовых magic constants в ядро.

### `permissions`

Permission — стабильный namespaced identifier. Поле `module_id` указывает владельца права.

Начальный набор:

- `admin.access`;
- `admin.users.manage`;
- `admin.settings.manage`;
- `admin.roles.manage`;
- `notes.use`;
- `tasks.use`;
- `files.use`;
- `messenger.use`;
- `profile.use`.

Имена прав являются контрактом. UI label может меняться, permission code — только через совместимую миграцию.

### `role_permissions`

Many-to-many связь roles → permissions.

### `user_roles`

Many-to-many связь users → roles. Поддерживается несколько ролей на одного пользователя. Хранятся время назначения и, если применимо, actor `assigned_by`.

## Account state

В `users` вводится:

```text
account_status = active | inactive | blocked
```

`is_active` временно сохраняется как legacy compatibility field. В следующей фазе login/session/WS gates будут переведены на явный account-state contract и `is_active` будет либо сведён к совместимому derived field, либо удалён отдельной миграцией после доказанного upgrade path.

`inactive` и `blocked` **не являются ролями**.

## Переход со старой модели

Upgrade migration преобразует legacy state следующим образом:

- `role=1` → role `superadmin`, account `active`;
- `role=111` → role `admin`, account `active`;
- `role=888` → role `user`, account `active`;
- `role=899` или `is_active=0` → role `user`, account `inactive`;
- `role=999` → role `user`, account `blocked`.

На transition-период `users.role` остаётся в таблице. Compatibility triggers поддерживают `account_status` для старых code paths, но **не удаляют `user_roles`** при блокировке/разблокировке. Поэтому независимая authorization identity больше не зависит от status code.

Удалять legacy `users.role` разрешено только после того, как HTTP, WebSocket, installer, admin UI, migrations и tests перестанут от него зависеть.

## `PermissionService`

Центральный read boundary:

- проверяет, что account существует и активен;
- проверяет, что permission зарегистрирован;
- затем вычисляет grant через `user_roles → role_permissions → permissions`;
- unknown permission fail-closed;
- `superadmin` получает bypass только для **существующего зарегистрированного permission**;
- blocked/inactive account не получает effective permissions независимо от назначенных ролей.

Это защищает от опечаток и от появления неявного «superadmin может всё, даже то, чего система не знает».

## Модульный контракт

Permissions принадлежат модулям. Текущий Phase 3 хранит ownership в `permissions.module_id`. Следующая стадия должна перенести декларацию permissions в module manifests и проверять:

- module id существует в `ModuleRegistry`;
- permission namespace принадлежит модулю;
- два модуля не могут объявить один permission;
- disabled / incompatible / quarantined module не активирует свои routes даже если RBAC grant существует;
- uninstall не удаляет silently информацию, необходимую для безопасного восстановления/аудита.

## Следующая фаза

После стабилизации foundation:

1. `RequirePermission` middleware;
2. перевод Admin routes:
   - admin shell → `admin.access`;
   - user state operations → `admin.users.manage`;
   - settings → `admin.settings.manage`;
   - role editor → `admin.roles.manage`;
3. `AdminUserService` также проверяет PermissionService, чтобы controller/middleware нельзя было обойти прямым service call;
4. status operations перестают переписывать authorization role;
5. затем module-owned route providers и manifest-owned permission declarations.

RBAC не должен внедряться как UI-only feature: каждый security-sensitive endpoint и service boundary должен иметь integration/negative contract.
