# Workspace Organizer

**Версия:** `1.0.1`  
**Актуально на:** 20 сентября 2026  
**Статус:** stable

Workspace Organizer — self-hosted PHP-приложение для корпоративной работы: заметки, личные и общие задачи, файлы, профиль, администрирование и real-time Messenger.

`1.0.1` сохраняет stable platform contract `1.0.0` и добавляет штатное подключение независимых модулей, подписанные лимиты пользователей, durable аудит, acceptance-fixes для Auth/Notes/Admin/Sidebar и новые Workspace-интеграции Messenger с Notes/Tasks/File Manager. Базовый stable contract: vendor-free PHP runtime, native view/WebSocket infrastructure, persisted RBAC и module policies, installation-bound offline Ed25519 licensing, signed remote updater с external staging, transactional code+MySQL rollback и проверенный upgrade path с `0.14.0-beta.4`.

## Возможности

- **Notes** — XChaCha20-Poly1305 для текста, writing-first editor, private attachments, first-class voice notes с duration/playback, view-only sharing по токену, autosave/dirty-state, server-side поиск и role policies для количества заметок, вложений, типов/размера файлов и sharing.
- **Tasks** — личные kanban/list задачи, drag-and-drop статусов, приоритеты, сроки, категории, подзадачи, фильтры и server-side поиск/пагинация; beta.4 добавляет общие task boards с ACL, участниками, исполнителями и audience `all_active`.
- **File Manager** — личные папки/файлы вне document root, protected download, media/read-only text preview, grid/list workspace, поиск/сортировка, drag-and-drop upload, storage quota и role policies для размера/типов файлов, общей ёмкости и создания папок.
- **Messenger v2** — private/group chats, Saved Messages, forwarding, media, voice, reply/edit/delete, delivery/read receipts, reactions, encrypted search, pin/mute/archive, group roles/avatars, multi-device realtime и reconnect/offline/session-ended UX; role policies ограничивают частоту сообщений, вложения, voice и group capabilities.
- **Profile** — workspace hub с Notes/Tasks/Files/storage metrics, private avatar, account settings, безопасная деактивация и explicit `is_profile_public` publication model без раскрытия private content.
- **Admin panel** — создание и lifecycle пользователей, managed registration `disabled/open/invite`, ограниченные/revocable инвайты, Role Manager с permission assignment и module policies, custom profile fields, системный лимит File Manager и персональные storage quota overrides без physical delete связанных данных.
- **Responsive UI** — единый design system, desktop/mobile navigation, обновлённые формы/карточки/модалки, keyboard focus, reduced-motion support и общий feedback layer.

## Security model

Ключевые свойства текущего contract:

- passwords — `password_hash` / Argon2id;
- Notes text — `UNIQUE_KEY` + XChaCha20-Poly1305, UID заметки используется как AAD;
- Messenger text/captions — отдельный `MSG_SECRET_KEY` + versioned XChaCha20-Poly1305 payload;
- crypto failures для новых encrypted данных — fail-closed;
- WebSocket identity — подписанный server-issued ticket, client UID не считается доверенным;
- WebSocket origins/actions — allowlist;
- File Manager, Messenger media, Notes attachments и user avatars — `PRIVATE_STORAGE_PATH` вне document root;
- File Manager quota проверяется до записи файла; concurrent uploads одного пользователя сериализуются MySQL advisory lock;
- upload MIME — server-side `finfo` + allowlist;
- unsafe HTTP actions — CSRF policy;
- login/registration и upload endpoints — request rate limiting;
- persisted RBAC отвечает за доступ к действиям, а `role_module_policies` отдельно задаёт количественные/типовые ограничения; server-side middleware/services остаются authorization boundary;
- публичная регистрация по умолчанию закрыта; режимы `disabled/open/invite` управляются администратором, а managed invite-коды хранятся только как SHA-256 hash;
- inactive/blocked user повторно проверяется на HTTP и WebSocket paths.

> Messenger использует **server-side encryption at rest**, а не end-to-end encryption. Сервер способен расшифровать сообщения.

> Attachment bytes и avatars защищаются private filesystem + ACL. Они не считаются отдельно зашифрованными at-rest, если конкретный storage flow явно не реализует такое шифрование. Для новых Notes attachments `is_encrypted=0` намеренно отражает реальность.

## Требования

- PHP `8.1+` — технический compatibility floor; для Internet-facing production рекомендуется поддерживаемая ветка PHP, сейчас `8.3+`;
- MySQL `8.x` — основной проверяемый CI path;
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `fileinfo`, `sodium`, `gd`;
- для realtime Messenger/native WebSocket runtime: POSIX-compatible host, PHP CLI, `pcntl`, long-running process и WebSocket reverse proxy;
- Argon2id support в `password_hash`;
- Apache + `mod_rewrite` либо Nginx с эквивалентным front-controller routing;
- writable private storage вне document root;
- HTTPS + WSS для production Messenger.

Подробная матрица Open Server 6+, legacy-compatible Open Server 5.4.x, shared hosting и VPS/VDS: [`docs/DEPLOYMENT_COMPATIBILITY.md`](docs/DEPLOYMENT_COMPATIBILITY.md).

**Composer не является runtime-зависимостью 1.0.** Готовый hosting bundle и source tree запускаются без `vendor/`; Composer может использоваться только как development/tooling utility, но production package не зависит от него.

## Fresh install на обычном хостинге

Fresh install должен поднимать проект **без ручного импорта SQL, ручного создания `.env` и запуска Composer/CLI на хостинге**.

Рекомендуемый сценарий:

1. Скачайте `workspace-organizer-v*.zip` из GitHub Release.
2. Загрузите и распакуйте его в нужный каталог сайта.
3. Если MySQL-пользователь хостинга не имеет `CREATE DATABASE`, один раз создайте пустую БД через панель хостинга.
4. Откройте в браузере:

```text
https://example.com/install.php
```

или, при установке в подкаталог:

```text
https://example.com/workspace/install.php
```

5. Укажите MySQL credentials и создайте первого администратора.

Web-installer автоматически:

- проверяет PHP 8.1+, необходимые extensions и Argon2id; production runtime не требует `vendor/`;
- пытается создать отсутствующую БД, если MySQL account это разрешает;
- импортирует composition-aware canonical schemas и создаёт current contract из 34 обязательных таблиц;
- создаёт `cache`/`compile`;
- подбирает и создаёт `PRIVATE_STORAGE_PATH` вне document root;
- создаёт private пространства `file_manager`, `messenger`, `notes`, `users`, `rate-limit`, `logs`, `legacy`;
- определяет `SITEURL` и `BASE_PATH`, включая установку в подкаталог;
- формирует same-site `WS_PUBLIC_URL` вида `/ws` и `WS_ALLOWED_ORIGINS`;
- генерирует отдельные `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET`;
- создаёт первого superadmin;
- только после успешной финализации атомарно создаёт `.env` и блокирует повторный запуск installer.

Если установка оборвалась до создания admin, `.env` ещё не существует и мастер можно безопасно запустить повторно.

Подробная пошаговая инструкция: [`docs/HOSTING_INSTALL.md`](docs/HOSTING_INSTALL.md).

### Установка из исходников

Исходный tree 1.0 является vendor-free и не требует `composer install` для запуска. После checkout/deploy можно использовать `/install.php`; вручную копировать `default.env` и импортировать SQL для **fresh install** не требуется.

### Private storage

Пример production-структуры:

```text
/var/lib/notes/private/
├── file_manager/
├── messenger/
├── notes/
├── users/
├── rate-limit/
├── logs/
└── legacy/
```

Canonical root вложений Notes — `PRIVATE_STORAGE_PATH/notes/`; browser никогда не получает этот physical path как URL.

На shared hosting installer предпочитает каталог в домашнем каталоге аккаунта, **выше `public_html` / document root**. Если тариф запрещает PHP запись вне web-root, такой тариф не соответствует security contract проекта.

### Database

Canonical fresh schemas:

```text
database/messenger_schema.sql
database/notes_schema.sql
database/file_manager_schema.sql
database/user_fields_schema.sql
database/tasks_schema.sql
database/access_control_schema.sql
database/audit_schema.sql
database/settings_schema.sql
database/module_lifecycle_schema.sql
```

Fresh contract включает 34 обязательные таблицы: persisted `module_lifecycle`, RBAC + `role_module_policies`, а также `task_boards`, `task_board_members`, `task_board_items` и `task_board_assignees`. `system_settings` хранит редактируемые системные значения, а `user_storage_quotas` — только персональные overrides лимита; фактический used space всегда рассчитывается из canonical `user_files`, чтобы не поддерживать рассинхронизируемый usage counter. `install.php` предназначен только для новой/пустой БД. Для существующих установок используются compatibility upgrade SQL; они не заменяют canonical `*_schema.sql` как описание текущей схемы.

После успешной установки наличие `.env` блокирует повторный запуск web-installer.

### Registration

По умолчанию публичная регистрация закрыта. Администратор управляет политикой в `/admin/registration` и может выбрать один из трёх режимов:

- `disabled` — самостоятельная регистрация запрещена;
- `open` — свободная регистрация;
- `invite` — регистрация только по действующему управляемому инвайту.

В invite-only режиме администратор создаёт приглашения с названием, сроком действия и лимитом использований. Полный код показывается один раз; в `system_settings` сохраняется только SHA-256 hash и служебные метаданные. Инвайт можно отозвать, а исчерпанный или просроченный код автоматически перестаёт действовать. Создание аккаунта и расход инвайта выполняются одной транзакцией.

Администратор также может создавать обычных пользователей напрямую из `/admin/` независимо от публичного режима регистрации. Такое создание не выдаёт admin/superadmin права: новый аккаунт получает каноническую RBAC-роль `user`. Суперадминистратор управляет прикладными ролями, permission assignment и module policies через `/admin/roles`.

`REGISTRATION_INVITE_CODE` из `.env` сохранён только как compatibility fallback для старых установок, пока администратор ни разу не сохранил новую явную политику в БД. После сохранения режима env-код больше не является отдельным authorization path.

### WebSocket

Installer по умолчанию настраивает один native WebSocket process рядом с приложением:

```env
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_HOST=127.0.0.1
WS_PORT=27800
```

На production hosting публичный `/ws` обычно проксируется на `127.0.0.1:27800`. Long-running PHP process запускается отдельно через hosting background-process manager, systemd/Supervisor или другой process manager:

```bash
php ws_server/server.php start
php bin/ws_doctor.php
```

Поддерживается и **один отдельный WebSocket-узел**, например `wss://ws.example.com/ws`. Такой узел должен работать на том же release/commit, использовать ту же application DB, `WS_TICKET_SECRET`, `MSG_SECRET_KEY`, общий Messenger private storage и общий maintenance `UPDATE_STATE_PATH`.

Несколько одновременно активных WS instances для одной installation пока не поддерживаются как production topology: connection registry находится в памяти process, а cross-node pub/sub/fan-out ещё не реализован.

Полный runbook для локального и удалённого режима: [`docs/MESSENGER_SERVER.md`](docs/MESSENGER_SERVER.md). Open Server/OSPanel: [`docs/OPEN_SERVER_WEBSOCKET.md`](docs/OPEN_SERVER_WEBSOCKET.md).

## Upgrade existing DB

Web-installer **не используется для upgrade** и намеренно отказывается изменять старую/частичную БД.

До обновления сделайте backup БД, `PRIVATE_STORAGE_PATH` и действующих crypto keys.

Проверка состояния compatibility upgrades:

```bash
php bin/migrate.php --status
```

Dry-run:

```bash
php bin/migrate.php --dry-run
```

Apply:

```bash
php bin/migrate.php
```

`bin/migrate.php` — upgrade runner для уже существующих SQL-скриптов совместимости, а не источник canonical schema. Внутренняя таблица `schema_migrations` хранит filename + SHA-256 checksum уже применённых upgrade scripts, чтобы повторный запуск был идемпотентным и изменение ранее применённого SQL обнаруживалось fail-closed. Уже применённый upgrade SQL не переписывается задним числом — добавляется новый compatibility script.

### Legacy crypto migration

Сначала dry-run:

```bash
php bin/migrate_crypto.php --scope=all --dry-run --limit=1000
```

Затем bounded/resumable migration:

```bash
php bin/migrate_crypto.php --scope=all --limit=1000
php bin/migrate_crypto.php --scope=messenger --limit=1000 --after-id=5000
```

`--allow-plaintext-notes` используйте только для вручную подтверждённых legacy Notes, которые исторически были помечены encrypted, но фактически содержали plaintext.

После успешной миграции legacy Messenger ciphertext удалите временный `MSG_LEGACY_SECRET_KEY`.

## Production healthcheck

```bash
php bin/healthcheck.php
php bin/healthcheck.php --json
```

Healthcheck проверяет PHP/extensions, secrets, private storage и его размещение вне application root, HTTPS/WSS/origin consistency, DB connection и current 32-table schema contract. Ненулевой exit code означает, что deployment нельзя считать healthy.

## Rate limiting

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Single-node limiter хранит state под `PRIVATE_STORAGE_PATH/rate-limit` и использует `flock`. Для multi-node deployment задайте отдельный `RATE_LIMIT_STORAGE_PATH` на общем POSIX volume с рабочими advisory locks; `DEPLOYMENT_NODE_COUNT>1` заставляет healthcheck требовать такой shared path. Заголовки `X-Real-IP`/`X-Forwarded-For` учитываются только от адресов из явного `TRUSTED_PROXY_IPS`.

## HTTP / CSP baseline

Repository `.htaccess` отвечает за static/access headers и routing; Content-Security-Policy формируется PHP Core:

- запрещает directory listing;
- закрывает от прямой HTTP-выдачи `app`, `bin`, `core`, `database`, `docs`, `vendor`, `ws_server`, `.github`, `.git`, `.logs`, `.env/default.env` и repository metadata;
- блокирует `install.php` после появления `.env`;
- задаёт `nosniff`, Referrer Policy, SAMEORIGIN, Permissions Policy и COOP;
- Core CSP запрещает objects, ограничивает base/forms/frame ancestors и использует per-request nonce;
- внешние JS CDN не требуются;
- `unsafe-eval` удалён после отказа от браузерного code runner в File Manager.

CSP формируется Core на каждый HTML request с криптографическим nonce. `script-src-attr 'none'` и `style-src-attr 'none'` запрещают inline event/style attributes, а `unsafe-inline` больше не входит в policy. Intentional inline `<script>/<style>` допускаются только с per-request nonce и контролируются отдельным CSP contract.

HSTS намеренно задаётся на production TLS reverse proxy, а не в repository `.htaccess`.

## UI / UX 0.13 + beta.4 collaboration

Интерфейс остаётся server-rendered на native PHP views без отдельного frontend build pipeline; bundled product modules больше не зависят от Smarty runtime.

Текущий product UI layer включает:

- системный font stack без Google Fonts;
- единые tokens для colors/surfaces/borders/radii/shadows;
- responsive sidebar: desktop collapse + mobile drawer/overlay; видимые пункты модулей рассчитываются из effective RBAC;
- current-route navigation state;
- Tasks kanban/list switch, drag/drop статусов и quick-create;
- shared Tasks boards с отдельным Kanban, board ACL, участниками и assignees;
- Notes writing-first editor, attachments/share/voice workspace и local draft protection;
- Profile hub с workspace counters, storage usage/quota и explicit publication controls;
- File Manager grid/list, local search/sort и drag-and-drop upload;
- Messenger connection recovery states и fresh-ticket WSS reconnect;
- Role Manager с адаптивными формами permission/policy assignment;
- общий toast/inline feedback/confirmation layer;
- server-side findability для Notes, Tasks и Admin users;
- keyboard focus, skip-link, aria-live region и доступные labels;
- `prefers-reduced-motion`;
- touch/mobile actions не зависят только от hover;
- File Manager code execution удалён; текстовые/code-файлы открываются только в read-only preview.

Полный 0.13 scope и отложенный beta polish: [`docs/PRODUCT_UX_0.13.md`](docs/PRODUCT_UX_0.13.md).

## Основные URL

```text
/                    dashboard
/auth/login          вход
/notes/              заметки
/tasks/              личные задачи
/tasks/boards        общие доски задач
/files/              личные файлы
/messenger/          Messenger
/profile/            профиль
/admin/              admin panel
/admin/roles         роли, permissions и module policies
/admin/settings      system settings и storage quotas
```

Полный route contract: `core/routerConfig.php`.

## Module notes

### Notes

- writing-first 0.13 editor и first-class voice attachments;
- server-side search/pagination/sort allowlist;
- owner-only edit/delete;
- private attachment upload/download/delete;
- public view-only share token;
- shared attachment ACL;
- encrypted text fail-closed;
- beta.4 role policies ограничивают количество заметок, sharing и attachment limits/types.

### Tasks

`database/tasks_schema.sql` входит в canonical install. Личные задачи сохраняют прежний ownership contract и поддерживают kanban/list views, drag-and-drop status, statuses/priorities/due dates/subtasks/categories и server-side search/filter/sort/pagination. Beta.4 добавляет отдельные shared boards для выбранной команды или `all_active`, board-level ACL, несколько исполнителей и ограничения на создание/размер досок через role policies.

### Messenger v2