# Workspace Organizer

**Версия:** `0.14.0-beta.2`  
**Актуально на:** 15 сентября 2026  
**Статус:** beta.2 / WebSocket deployment hotfix; следующая основная цель — `1.0.0` stable

Workspace Organizer — внутреннее PHP-приложение для корпоративной работы: заметки, задачи, личные файлы, профиль, администрирование и real-time Messenger.

Версия `0.14.0-beta.2` сохраняет beta hardening baseline и исправляет deployment-контракт realtime Messenger: браузер использует same-origin `ws(s)://<site>[/base]/ws`, фронтовый Apache/Nginx/Caddy завершает TLS/WebSocket Upgrade, а Workerman безопасно остаётся внутренним listener на `127.0.0.1:27800`. Добавлены Open Server 6+ bridge/диагностика и regression coverage на PHP 8.1/8.3. Дальнейшая работа в `master` по-прежнему направлена на `1.0.0` stable: module isolation, updater/recovery, licensing, observability и остальные stable blockers.

## Возможности

- **Notes** — XChaCha20-Poly1305 для текста, writing-first editor, private attachments, first-class voice notes с duration/playback, view-only sharing по токену, autosave/dirty-state и server-side поиск.
- **Tasks** — kanban/list режимы, drag-and-drop статусов, быстрое создание, приоритеты, сроки, категории, подзадачи, фильтры и server-side поиск/пагинация.
- **File Manager** — личные папки/файлы вне document root, protected download, media/read-only text preview, grid/list workspace, поиск/сортировка, drag-and-drop upload и storage quota.
- **Messenger v2** — private/group chats, Saved Messages, forwarding, media, voice, reply/edit/delete, delivery/read receipts, reactions, encrypted search, pin/mute/archive, group roles/avatars, multi-device realtime и reconnect/offline/session-ended UX.
- **Profile** — workspace hub с Notes/Tasks/Files/storage metrics, private avatar, account settings, безопасная деактивация и explicit `is_profile_public` publication model без раскрытия private content.
- **Admin panel** — управление пользователями, custom profile fields, системным лимитом File Manager и персональными storage quota overrides без physical delete связанных данных; список пользователей поддерживает server-side поиск/пагинацию.
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
- web-registration закрыта без `REGISTRATION_INVITE_CODE`;
- inactive/blocked user повторно проверяется на HTTP и WebSocket paths.

> Messenger использует **server-side encryption at rest**, а не end-to-end encryption. Сервер способен расшифровать сообщения.

> Attachment bytes и avatars защищаются private filesystem + ACL. Они не считаются отдельно зашифрованными at-rest, если конкретный storage flow явно не реализует такое шифрование. Для новых Notes attachments `is_encrypted=0` намеренно отражает реальность.

## Требования

- PHP `8.1+` — технический compatibility floor; для Internet-facing production рекомендуется поддерживаемая ветка PHP, сейчас `8.3+`;
- MySQL `8.x` — основной проверяемый CI path;
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `fileinfo`, `sodium`, `gd`;
- для realtime Messenger/Workerman: POSIX-compatible host, PHP CLI, `pcntl`, `posix`, long-running process и WebSocket reverse proxy;
- Argon2id support в `password_hash`;
- Apache + `mod_rewrite` либо Nginx с эквивалентным front-controller routing;
- writable private storage вне document root;
- HTTPS + WSS для production Messenger.

Подробная матрица Open Server 6+, legacy-compatible Open Server 5.4.x, shared hosting и VPS/VDS: [`docs/DEPLOYMENT_COMPATIBILITY.md`](docs/DEPLOYMENT_COMPATIBILITY.md).

**Composer на конечном shared hosting не обязателен**, если используется готовый hosting bundle из GitHub Release. Composer нужен при установке непосредственно из source tree и для development/CI.

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

- проверяет PHP 8.1+, extensions, Argon2id и наличие production `vendor/`;
- пытается создать отсутствующую БД, если MySQL account это разрешает;
- импортирует 8 canonical schemas и создаёт current contract из 27 обязательных таблиц;
- создаёт `cache`/`compile`;
- подбирает и создаёт `PRIVATE_STORAGE_PATH` вне document root;
- создаёт private пространства `file_manager`, `messenger`, `notes`, `users`, `rate-limit`, `logs`, `legacy`;
- определяет `SITEURL` и `BASE_PATH`, включая установку в подкаталог;
- формирует same-site `WS_PUBLIC_URL` вида `/ws` и `WS_ALLOWED_ORIGINS`;
- генерирует отдельные `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET`;
- создаёт первый superadmin;
- только после успешной финализации атомарно создаёт `.env` и блокирует повторный запуск installer.

Если установка оборвалась до создания admin, `.env` ещё не существует и мастер можно безопасно запустить повторно.

Подробная пошаговая инструкция: [`docs/HOSTING_INSTALL.md`](docs/HOSTING_INSTALL.md).

### Установка из исходников

Для development, VPS или собственного build pipeline:

```bash
composer install --no-dev --optimize-autoloader
```

После этого также можно использовать `/install.php`; вручную копировать `default.env` и импортировать SQL для **fresh install** не требуется.

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
database/settings_schema.sql
database/module_lifecycle_schema.sql
```

Fresh contract включает 27 обязательных таблиц, включая persisted `module_lifecycle` registry. `system_settings` хранит редактируемые системные значения, а `user_storage_quotas` — только персональные overrides лимита; фактический used space всегда рассчитывается из canonical `user_files`, чтобы не поддерживать рассинхронизируемый usage counter. `install.php` предназначен только для новой/пустой БД. Для существующих установок используются compatibility upgrade SQL; они не заменяют canonical `*_schema.sql` как описание текущей схемы.

После успешной установки наличие `.env` блокирует повторный запуск web-installer.

### Registration

По умолчанию web-registration закрыта. Для invite registration задайте:

```env
REGISTRATION_INVITE_CODE=<long-random-invite-secret>
```

После этого используется URL:

```text
/auth/registration/<REGISTRATION_INVITE_CODE>
```

Неверный или незаданный invite возвращает `404`.

### WebSocket

Installer записывает same-site URL вида:

```env
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_HOST=127.0.0.1
WS_PORT=27800
```

На production hosting маршрут `/ws` должен проксироваться на локальный Workerman process. Это единственная часть, которую невозможно универсально стартовать web-installer'ом на каждом типе shared hosting: тариф должен поддерживать long-running PHP process/WebSocket proxy.

Development/VPS:

```bash
php ws_server/server.php start
```

Production: запускайте Workerman через hosting background-process manager, systemd/supervisor/container orchestration и публикуйте браузеру только через WSS reverse proxy. Полный runbook: [`docs/MESSENGER_SERVER.md`](docs/MESSENGER_SERVER.md).

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

Healthcheck проверяет PHP/extensions, secrets, private storage и его размещение вне application root, HTTPS/WSS/origin consistency, DB connection и current schema contract. Ненулевой exit code означает, что deployment нельзя считать healthy.

## Rate limiting

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Single-node limiter хранит state под `PRIVATE_STORAGE_PATH/rate-limit` и использует `flock`. Для multi-node deployment задайте отдельный `RATE_LIMIT_STORAGE_PATH` на общем POSIX volume с рабочими advisory locks; `DEPLOYMENT_NODE_COUNT>1` заставляет healthcheck требовать такой shared path. Заголовки `X-Real-IP`/`X-Forwarded-For` учитываются только от адресов из явного `TRUSTED_PROXY_IPS`.

## HTTP / CSP baseline

Repository `.htaccess`:

- запрещает directory listing;
- закрывает от прямой HTTP-выдачи `app`, `bin`, `core`, `database`, `docs`, `vendor`, `ws_server`, `.github`, `.git`, `.logs`, `.env/default.env` и repository metadata;
- блокирует `install.php` после появления `.env`;
- задаёт `nosniff`, Referrer Policy, SAMEORIGIN, Permissions Policy и COOP;
- CSP запрещает objects, ограничивает base/forms/frame ancestors;
- внешние JS CDN не требуются;
- `unsafe-eval` удалён после отказа от браузерного code runner в File Manager.

Пока остаётся `unsafe-inline`, потому что часть legacy Smarty templates содержит inline script/style blocks. Это известный CSP-hardening debt, а не разрешение для новых inline-скриптов.

HSTS намеренно задаётся на production TLS reverse proxy, а не в repository `.htaccess`.

## UI / UX 0.13

Интерфейс остаётся server-rendered Smarty без отдельного frontend build pipeline.

Текущий product UI layer включает:

- системный font stack без Google Fonts;
- единые tokens для colors/surfaces/borders/radii/shadows;
- responsive sidebar: desktop collapse + mobile drawer/overlay;
- current-route navigation state;
- Tasks kanban/list switch, drag/drop статусов и quick-create;
- Notes writing-first editor, attachments/share/voice workspace и local draft protection;
- Profile hub с workspace counters, storage usage/quota и explicit publication controls;
- File Manager grid/list, local search/sort и drag-and-drop upload;
- Messenger connection recovery states и fresh-ticket WSS reconnect;
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
/tasks/              задачи
/files/              личные файлы
/messenger/          Messenger
/profile/            профиль
/admin/              admin panel
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
- encrypted text fail-closed.

### Tasks

`database/tasks_schema.sql` входит в canonical install. Поддерживаются kanban/list views, drag-and-drop status, statuses/priorities/due dates/subtasks/categories, server-side search/filter/sort/pagination и быстрые inline actions.

### Messenger v2

Current contract включает private/group dialogs, Saved Messages, forwarding, media/voice, replies/edit/delete, delivered/read cursors, reactions, multi-device fanout, pin/mute/archive, group ownership/admin roles/avatars, orphan cleanup, bounded encrypted search и reconnect/offline/session-ended UI с fresh WebSocket ticket перед reconnect.

Encrypted search не хранит plaintext index: он расшифровывает только ограниченное число последних доступных сообщений (`MESSENGER_SEARCH_SCAN_LIMIT`, default `1000`).

### Profile

Private avatar выдаётся через authenticated endpoint. Self-delete заменён на deactivation (`is_active=0`), данные не каскадно удаляются; group owner должен сначала передать ownership. Собственный hub показывает bounded workspace metrics и storage quota; чужой профиль получает только whitelist metadata объектов, явно опубликованных владельцем через `is_profile_public`.

### Admin

Admin lifecycle использует safe deactivation вместо physical delete. Реактивация восстанавливает согласованный `role + is_active`; administrative targets и group owners защищены отдельными checks. Custom profile fields используют canonical `user_fields`.

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

`0.13 installer schema contract` явно проверяет publication fields Notes/Tasks/Files, voice-note duration, settings/quota schemas и Messenger installer config.

`System settings and storage quota` проверяет canonical settings schema, admin ACL, default/per-user quota, live usage из `user_files`, reset override и quota overflow denial на MySQL 8.4.

`Hosting installer` выполняет настоящий HTTP fresh-install через cookies/CSRF на MySQL в hosting-like `public_html/workspace`, проверяет subdirectory detection, private storage вне document root, 27-table contract, quota seed, admin account, generated `.env`, блокировку повторного installer и итоговый healthcheck.

`Build hosting package` собирает upload-ready ZIP с production `vendor/`; теги `v*-*` публикуются как GitHub prerelease, а stable tag без suffix — как обычный Release.

`Browser HTTPS and WSS E2E` поднимает PHP + Workerman + TLS Nginx + MySQL и реальные Chromium-сессии: проверяет login, основные модули, authenticated WSS, realtime delivery и 0.13 reconnect recovery.

Отдельные browser lifecycle workflows проверяют Notes, Tasks, File Manager, Profile и Admin, включая реальную quota-ошибку и DB/storage fault injection без production test hooks.

`Production operations` проверяет shared rate-limit storage, trusted proxy contract, positive/negative multi-node healthcheck, MySQL dump/checksum/restore, private-storage restore и rotation `WS_TICKET_SECRET`.

`0.14 beta readiness` проверяет beta identity, module/security lifecycle artifacts, release publishing contract и синхронизацию Version/README/CHANGELOG.

`Master release gate` запускается на каждом PR и после каждого push/merge в `master`: повторно проверяет объединённый commit — Composer/security audit, полный PHP/JS lint, canonical schema import, production healthcheck, согласованность версии, governance contract и upload-ready hosting bundle.

## Документация

- [`CHANGELOG.md`](CHANGELOG.md) — история и Unreleased.
- [`docs/CORE.md`](docs/CORE.md) — архитектура ядра.
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — пользовательские сценарии.
- [`docs/HOSTING_INSTALL.md`](docs/HOSTING_INSTALL.md) — fresh install на shared hosting без Composer/CLI.
- [`docs/PRODUCTION.md`](docs/PRODUCTION.md) — deployment, WSS, rate limiting и production checklist.
- [`docs/OPERATIONS.md`](docs/OPERATIONS.md) — backup/restore drill, multi-node rate limiting, trusted proxies и key-rotation procedures.
- [`docs/RELEASE_GOVERNANCE.md`](docs/RELEASE_GOVERNANCE.md) — required checks, branch protection и review policy.
- [`docs/PRODUCT_UX_0.13.md`](docs/PRODUCT_UX_0.13.md) — закрытый 0.13 scope и beta backlog.
- [`TASKS_MODULE_README.md`](TASKS_MODULE_README.md) — дополнительная документация Tasks.
- [`default.env`](default.env) — environment variables и security comments.

## 0.14 beta и путь к `1.0.0` stable

`0.14.0-beta.1` — первая официальная beta-точка. Beta patch releases при необходимости публикуются как `v0.14.0-beta.N`; они не открывают новый feature cycle. Основная ветка после beta.1 развивается в сторону `1.0.0` stable.

Перед `1.0.0` должны быть закрыты оставшиеся platform/stability blockers:

- module-owned bootstrap/routes/assets/socket registration и фактическая изоляция отключённых модулей;
- deterministic package compositions и dependency preflight для разных наборов модулей;
- signed core/module update metadata, staged transactional update, rollback/recovery и health verification;
- централизованный licensing/entitlement contract с безопасным offline/expiry behavior без удаления пользовательских данных;
- structured observability, security/audit events, metrics/alerts, load/soak и cross-browser/mobile regression evidence;
- transactional/resumable re-encryption procedure для безопасной ротации `UNIQUE_KEY` / `MSG_SECRET_KEY`;
- постепенный вынос inline Smarty JS/CSS для CSP без `unsafe-inline`;
- явный retention/permanent-purge contract и подтверждённый beta-период без P0/P1 data-loss/security дефектов;
- scalable encrypted-search architecture только если beta load tests покажут, что bounded decrypt scan не соответствует заявленному масштабу.

Подробный hardening roadmap: [`docs/BETA_HARDENING_0.14.md`](docs/BETA_HARDENING_0.14.md).
