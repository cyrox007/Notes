# Workspace Organizer

**Версия:** `1.0.4`  
**Актуально на:** 24 сентября 2026  
**Статус:** stable

Workspace Organizer — self-hosted PHP-приложение для корпоративной работы: заметки, личные и общие задачи, файлы, профиль, администрирование и real-time Messenger.

`1.0.4` — исправляющий релиз линии 1.0: уплотняет Admin → Updates, исправляет поиск PHP CLI в web-SAPI Windows/OSPanel за счёт обнаружения `php.exe` рядом с фактическим `PHP_BINARY` и в абсолютных каталогах `PATH`, а сквозной update-drill теперь начинает проверку с exact `v1.0.3` и устанавливает текущий `1.0.4`. Релиз предназначен в том числе для реальной проверки штатного обновления установленной `1.0.3` через подписанный канал. Базовый stable contract остаётся прежним: vendor-free PHP runtime, native view/WebSocket infrastructure, persisted RBAC и module policies, installation-bound offline Ed25519 licensing, signed remote updater с external staging, transactional code+MySQL rollback и проверенный upgrade path с `0.14.0-beta.4`.

## Возможности

- **Notes** — XChaCha20-Poly1305 для текста, writing-first editor, private attachments, first-class voice notes с duration/playback, view-only sharing по токену, autosave/dirty-state, server-side поиск и role policies для количества заметок, вложений, типов/размера файлов и sharing.
- **Tasks** — личные kanban/list задачи, drag-and-drop статусов, приоритеты, сроки, категории, подзадачи, фильтры и server-side поиск/пагинация; beta.4 добавляет общие task boards с ACL, участниками, исполнителями и audience `all_active`.
- **File Manager** — личные папки/файлы вне document root, protected download, media/read-only text preview, grid/list workspace, поиск/сортировка, drag-and-drop upload, storage quota и role policies для размера/типов файлов, общей ёмкости и создания папок.
- **Messenger v2** — private/group chats, Saved Messages, forwarding, media, voice, reply/edit/delete, delivery/read receipts, reactions, encrypted search, pin/mute/archive, group roles/avatars, multi-device realtime, WebSocket-first transport с HTTP long-poll fallback и reconnect/offline/session-ended UX; role policies ограничивают частоту сообщений, вложения, voice и group capabilities.
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
- Messenger работает через обычный authenticated HTTP long poll даже без WebSocket process; для низкой задержки и меньшей нагрузки рекомендуется PHP CLI + long-running native WebSocket process и WebSocket endpoint/proxy; daemon mode на Unix дополнительно требует `pcntl`;
- Argon2id support в `password_hash`;
- Apache + `mod_rewrite` либо Nginx с эквивалентным front-controller routing;
- writable private storage вне document root;
- HTTPS для production; WSS рекомендуется для низколатентного Messenger fast path, при его недоступности работает authenticated HTTP long poll.

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

Installer записывает same-site URL вида:

```env
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_HOST=127.0.0.1
WS_PORT=27800
```

На production hosting WebSocket остаётся предпочтительным realtime transport: публичный `/ws` обычно проксируется на локальный native WebSocket process, а long-running PHP process запускается отдельно через hosting background-process manager, systemd/Supervisor или аналогичный process manager:

```bash
php ws_server/server.php check
php ws_server/server.php start
php bin/ws_doctor.php
```

Если WebSocket недоступен, browser автоматически переключает Messenger на `/messenger/realtime/poll` + `/messenger/realtime/action`. Fallback использует те же server-side permissions/license/maintenance gates и тот же Messenger dispatcher; после восстановления WebSocket клиент бесшовно возвращается на него. `MESSENGER_LONG_POLL_TIMEOUT_SECONDS` по умолчанию равен 15 секундам (допустимо 5–25).

Поддерживается также **один отдельный WebSocket-узел**, например `wss://ws.example.com/ws`, при условии одинакового release/commit, общей application DB, согласованных `WS_TICKET_SECRET`/`MSG_SECRET_KEY`, общего Messenger private storage и общего maintenance `UPDATE_STATE_PATH`. Shared DB realtime revision bridge синхронизирует durable HTTP-fallback mutations с активными WS-клиентами. Несколько одновременно активных WS instances одной installation пока не поддерживаются как HA/load-balancing topology.

Полный runbook: [`docs/MESSENGER_SERVER.md`](docs/MESSENGER_SERVER.md).

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

Current contract включает private/group dialogs, Saved Messages, forwarding, media/voice, replies/edit/delete, delivered/read cursors, reactions, multi-device fanout, pin/mute/archive, group ownership/admin roles/avatars, orphan cleanup, bounded encrypted search и WebSocket-first/HTTP-long-poll realtime recovery с fresh WebSocket ticket перед reconnect. Durable fallback mutations bridge-ятся через shared DB revision к активным WS-клиентам; typing/activity остаются WebSocket-only enhancement. Beta.4 применяет server-side role policies к message rate, attachment limits/types, созданию/размеру групп и voice messages.

Encrypted search не хранит plaintext index: он расшифровывает только ограниченное число последних доступных сообщений (`MESSENGER_SEARCH_SCAN_LIMIT`, default `1000`).

### Profile

Private avatar выдаётся через authenticated endpoint. Self-delete заменён на deactivation (`is_active=0`), данные не каскадно удаляются; group owner должен сначала передать ownership. Собственный hub показывает bounded workspace metrics и storage quota; чужой профиль получает только whitelist metadata объектов, явно опубликованных владельцем через `is_profile_public`.

### Admin

Admin lifecycle использует safe deactivation вместо physical delete. Administrative targets и group owners защищены отдельными checks. Custom profile fields используют canonical `user_fields`.

`/admin/roles` позволяет superadmin создавать прикладные роли, назначать роли пользователям, управлять boolean permissions и отдельными типизированными policies Notes/Tasks/Files/Messenger. Системные роли не удаляются, текущий superadmin защищён от самоблокировки/самоснятия, а изменения доступа применяются через persisted RBAC при следующей серверной проверке.

`/admin/settings` управляет default File Manager quota и персональными overrides. Изменение квоты повторно авторизуется внутри service-layer; File Manager upload проверяет эффективный лимит до физической записи файла. Для одного пользователя concurrent uploads сериализуются advisory lock, поэтому параллельные запросы не могут независимо занять один и тот же остаток квоты.

## Scheduled maintenance

Messenger orphan cleanup:

```bash
php bin/cleanup_messenger_orphans.php
```

Рекомендуемый cron/systemd timer: каждые 15–60 минут.

Также контролируйте disk space, права private storage, compatibility-upgrade state, logs, backup/restore tests и удаление временных legacy keys.

## CI

GitHub Actions покрывают security baseline, PHP/Composer, clean schemas, DB compatibility upgrades, crypto migration, Notes/Tasks/Profile contracts и Messenger groups/media/search/voice/reactions/forwarding. Workflow `Product UI and production quality` дополнительно проверяет UI/accessibility wiring, File Manager safe preview, Linux bootstrap paths, rate limit middleware, CSP/web-root protection, healthcheck contract и freshness документации.

`0.14 installer schema contract` явно проверяет publication fields Notes/Tasks/Files, voice-note duration, settings/quota schemas, persisted RBAC/module policies и shared task-board tables.

`0.14 Beta 4 role policies` и `0.14 Beta 4 shared task boards` проверяют policy composition/enforcement, Role Manager wiring, board ACL и compatibility migrations.

`System settings and storage quota` проверяет canonical settings schema, admin ACL, default/per-user quota, live usage из `user_files`, reset override и quota overflow denial на MySQL 8.4.

`Hosting installer` выполняет настоящий HTTP fresh-install через cookies/CSRF на MySQL в hosting-like `public_html/workspace`, проверяет subdirectory detection, private storage вне document root, 32-table contract, quota seed, admin account, generated `.env`, блокировку повторного installer и итоговый healthcheck.

`Build hosting package` собирает upload-ready ZIP с production `vendor/`; теги `v*-*` публикуются как GitHub prerelease, а stable tag без suffix — как обычные Release.

`Browser HTTPS and WSS E2E` поднимает PHP + native WebSocket server + TLS Nginx + MySQL и реальные Chromium-сессии: проверяет login, основные модули, authenticated WSS, realtime delivery и 0.13 reconnect recovery.

Отдельные browser lifecycle workflows проверяют Notes, Tasks, File Manager, Profile и Admin, включая реальную quota-ошибку и DB/storage fault injection без production test hooks.

`Production operations` проверяет shared rate-limit storage, trusted proxy contract, positive/negative multi-node healthcheck, MySQL dump/checksum/restore, private-storage restore и rotation `WS_TICKET_SECRET`.

`0.14 beta readiness` проверяет beta identity, module/security lifecycle artifacts, release publishing contract и синхронизацию Version/README/CHANGELOG.

`Master release gate` запускается на каждом PR и после каждого push/merge в `master`: повторно проверяет объединённый commit — Composer/security audit, полный PHP/JS lint, canonical schema import, production healthcheck, согласованность версии, governance contract и upload-ready hosting bundle.

## Документация

- [`CHANGELOG.md`](CHANGELOG.md) — история и Unreleased.
- [`docs/CORE.md`](docs/CORE.md) — архитектура ядра.
- [`docs/MODULE_DEVELOPMENT.md`](docs/MODULE_DEVELOPMENT.md) — создание, установка и lifecycle нового модуля.
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — пользовательские сценарии.
- [`docs/HOSTING_INSTALL.md`](docs/HOSTING_INSTALL.md) — fresh install на shared hosting без Composer/CLI.
- [`docs/PRODUCTION.md`](docs/PRODUCTION.md) — deployment, WSS, rate limiting и production checklist.
- [`docs/OPERATIONS.md`](docs/OPERATIONS.md) — backup/restore drill, multi-node rate limiting, trusted proxies и key-rotation procedures.
- [`docs/RELEASE_GOVERNANCE.md`](docs/RELEASE_GOVERNANCE.md) — required checks, branch protection и review policy.
- [`docs/PRODUCT_UX_0.13.md`](docs/PRODUCT_UX_0.13.md) — закрытый 0.13 scope и beta backlog.
- [`TASKS_MODULE_README.md`](TASKS_MODULE_README.md) — дополнительная документация Tasks.
- [`default.env`](default.env) — environment variables и security comments.

## 1.0 release readiness

Основные platform/stability blockers исходного beta-аудита уже закрыты в ветке `1.0`:

- vendor-free distributable runtime;
- isolated module-owned runtime и composition-aware database/install/update/health ownership;
- signed staged updater с transactional apply, durable recovery и code+DB rollback;
- installation-wide licensing и Core recovery control plane;
- production license/update Ed25519 keypairs прошли offline ceremony; в репозитории и customer bundle остаются только public trust roots;
- structured security observability и operational alert thresholds;
- resumable/rollback-safe rotation `UNIQUE_KEY` / `MSG_SECRET_KEY`;
- nonce-based CSP без `unsafe-inline`;
- explicit retention/permanent-purge contract с filesystem/DB safety guards;
- browser lifecycle coverage для основных product modules и exact published 1.0.1 → 1.0.2 upgrade/rollback drill;
- cross-browser/mobile + authenticated load/soak release-evidence harness.

Перед окончательным выпуском `v1.0.4` остаются только релизные проверки, а не новые возможности:

1. проверить GitHub branch protection/ruleset для `master` и `1.0`;
2. получить зелёный полный CI, cross-browser/mobile и load/soak evidence на exact release head `1.0.4`;
3. подтвердить fresh backup/restore drill, обязательный Admin E2E `v1.0.3 → 1.0.4` с exact-схемой предыдущего релиза, Windows compatibility CI и ручную проверку OSPanel 5.2.2; исторический путь `1.0.2 → 1.0.3` остаётся отдельной совместимостью через bootstrap;
4. подтвердить отсутствие открытых P0/P1 data-loss/security/release blockers;
5. собрать immutable `workspace-organizer-v1.0.4.zip`, сверить SHA-256/source SHA и подписать exact update manifest production update key;
6. после strict acceptance слить exact release head в `master`, поставить `v1.0.4` и публиковать только проверенные immutable artifacts.

Scalable encrypted-search redesign не является release blocker сам по себе; он требуется только если измерения на заявленном масштабе покажут, что bounded decrypt scan не выдерживает принятого performance envelope.

Финальный порядок действий: [`docs/RELEASE_ACCEPTANCE.md`](docs/RELEASE_ACCEPTANCE.md). Исторический hardening roadmap: [`docs/BETA_HARDENING_0.14.md`](docs/BETA_HARDENING_0.14.md).
