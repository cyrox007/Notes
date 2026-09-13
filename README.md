# Workspace Organizer

**Версия:** `0.11.0-alpha`  
**Актуально на:** 13 сентября 2026  
**Статус:** active alpha / release hardening

Workspace Organizer — внутреннее PHP-приложение для корпоративной работы: заметки, задачи, личные файлы, профиль, администрирование и real-time Messenger.

После PR #45–#59 основные security-, schema-contract, UI/UX, installer, browser/WSS и production-operations блокеры исходного аудита закрыты: Messenger, Notes, Tasks, Profile, fresh install, versioned DB upgrade, legacy crypto migration, product-wide UI, hosting install, real browser E2E и restore drill имеют отдельные проверяемые контракты.

## Возможности

- **Notes** — XChaCha20-Poly1305 для текста, private attachments, голосовые вложения, view-only sharing по токену.
- **Tasks** — статусы, приоритеты, сроки, категории, подзадачи, фильтры и server-side sort allowlist.
- **File Manager** — личные папки/файлы вне document root, protected download, media и read-only text preview.
- **Messenger v2** — private/group chats, Saved Messages, forwarding, media, voice, reply/edit/delete, delivery/read receipts, reactions, encrypted search, pin/mute/archive, group roles/avatars и multi-device realtime.
- **Profile** — canonical user contract, private avatar, изменение данных/пароля и безопасная деактивация аккаунта.
- **Admin panel** — управление пользователями и custom profile fields без physical delete связанных данных.
- **Responsive UI** — единый design system, desktop/mobile navigation, dashboard, обновлённые формы/карточки/модалки, keyboard focus и reduced-motion support.

## Security model

Ключевые свойства текущего contract:

- passwords — `password_hash` / Argon2id;
- Notes text — `UNIQUE_KEY` + XChaCha20-Poly1305, UID заметки используется как AAD;
- Messenger text/captions — отдельный `MSG_SECRET_KEY` + versioned XChaCha20-Poly1305 payload;
- crypto failures для новых encrypted данных — fail-closed;
- WebSocket identity — подписанный server-issued ticket, client UID не считается доверенным;
- WebSocket origins/actions — allowlist;
- File Manager, Messenger media, Notes attachments и user avatars — `PRIVATE_STORAGE_PATH` вне document root;
- upload MIME — server-side `finfo` + allowlist;
- unsafe HTTP actions — CSRF policy;
- login/registration и upload endpoints — request rate limiting;
- web-registration закрыта без `REGISTRATION_INVITE_CODE`;
- inactive/blocked user повторно проверяется на HTTP и WebSocket paths.

> Messenger использует **server-side encryption at rest**, а не end-to-end encryption. Сервер способен расшифровать сообщения.

> Attachment bytes и avatars защищаются private filesystem + ACL. Они не считаются отдельно зашифрованными at-rest, если конкретный storage flow явно не реализует такое шифрование. Для новых Notes attachments `is_encrypted=0` намеренно отражает реальность.

## Требования

- PHP `8.3+`;
- MySQL `8.x` — основной проверяемый CI path;
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `fileinfo`, `sodium`, `gd`;
- Argon2id support в `password_hash`;
- Apache + `mod_rewrite` либо Nginx с эквивалентным front-controller routing;
- writable private storage вне document root;
- HTTPS + WSS для production Messenger.

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

- проверяет PHP 8.3, extensions, Argon2id и наличие production `vendor/`;
- пытается создать отсутствующую БД, если MySQL account это разрешает;
- импортирует 5 canonical schemas и проверяет 20 обязательных таблиц;
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
```

Fresh contract включает 20 обязательных таблиц. `install.php` предназначен только для новой/пустой БД; существующие установки обновляются versioned migrations.

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

Production: запускайте Workerman через hosting background-process manager, systemd/supervisor/container orchestration и публикуйте браузеру только через WSS reverse proxy.

## Upgrade existing DB

Web-installer **не используется для upgrade** и намеренно отказывается изменять старую/частичную БД.

До обновления сделайте backup БД, `PRIVATE_STORAGE_PATH` и действующих crypto keys.

Проверка migration state:

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

`schema_migrations` сохраняет filename + SHA-256 checksum. Уже применённые migration-файлы нельзя переписывать задним числом — создавайте новый migration.

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

## UI / UX refresh

Интерфейс остаётся server-rendered Smarty без отдельного frontend build pipeline.

Текущий product UI layer включает:

- системный font stack без Google Fonts;
- единые tokens для colors/surfaces/borders/radii/shadows;
- новый responsive sidebar: desktop collapse + mobile drawer/overlay;
- current-route navigation state;
- обновлённый top bar/footer и dashboard;
- унифицированные Notes/Tasks/Profile/File Manager/Admin surfaces;
- Messenger визуально интегрирован в общий shell без изменения realtime logic;
- обновлённые login/register screens;
- keyboard focus, skip-link, aria-live region и доступные labels;
- `prefers-reduced-motion`;
- touch/mobile actions не зависят только от hover;
- File Manager code execution удалён; текстовые/code-файлы открываются только в read-only preview.

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
```

Полный route contract: `core/routerConfig.php`.

## Module notes

### Notes

- server-side sort allowlist;
- owner-only edit/delete;
- private attachment upload/download/delete;
- public view-only share token;
- shared attachment ACL;
- encrypted text fail-closed.

### Tasks

`database/tasks_schema.sql` входит в canonical install. Поддерживаются statuses/priorities/due dates/subtasks/categories и server-side filter/sort allowlists.

### Messenger v2

Current contract включает private/group dialogs, Saved Messages, forwarding, media/voice, replies/edit/delete, delivered/read cursors, reactions, multi-device fanout, pin/mute/archive, group ownership/admin roles/avatars, orphan cleanup и bounded encrypted search.

Encrypted search не хранит plaintext index: он расшифровывает только ограниченное число последних доступных сообщений (`MESSENGER_SEARCH_SCAN_LIMIT`, default `1000`).

### Profile

Private avatar выдаётся через authenticated endpoint. Self-delete заменён на deactivation (`is_active=0`), данные не каскадно удаляются; group owner должен сначала передать ownership.

### Admin

Admin lifecycle использует safe deactivation вместо physical delete. Реактивация восстанавливает согласованный `role + is_active`; administrative targets и group owners защищены отдельными checks. Custom profile fields используют canonical `user_fields`.

## Scheduled maintenance

Messenger orphan cleanup:

```bash
php bin/cleanup_messenger_orphans.php
```

Рекомендуемый cron/systemd timer: каждые 15–60 минут.

Также контролируйте disk space, права private storage, migration state, logs, backup/restore tests и удаление временных legacy keys.

## CI

GitHub Actions покрывают security baseline, PHP/Composer, clean schemas, DB upgrade, crypto migration, Notes/Tasks/Profile contracts и Messenger groups/media/search/voice/reactions/forwarding. Workflow `Product UI and production quality` дополнительно проверяет UI/accessibility wiring, File Manager safe preview, Linux bootstrap paths, rate limit middleware, CSP/web-root protection, healthcheck contract и freshness документации.

`Hosting installer` выполняет настоящий HTTP fresh-install через cookies/CSRF на MySQL в hosting-like `public_html/workspace`, проверяет subdirectory detection, private storage вне document root, admin account, generated `.env`, блокировку повторного installer и итоговый healthcheck.

`Build hosting package` собирает upload-ready ZIP с production `vendor/`; на tag `v*` ZIP публикуется как release asset.

`Browser HTTPS and WSS E2E` поднимает PHP + Workerman + TLS Nginx + MySQL и две реальные Chromium-сессии: проверяет login, основные модули, authenticated WSS, создание приватного диалога и Alice→Bob realtime message без reload.

`Production operations` проверяет shared rate-limit storage, trusted proxy contract, positive/negative multi-node healthcheck, MySQL dump/checksum/restore, private-storage restore и rotation `WS_TICKET_SECRET`.

`Master release gate` запускается на каждом PR и после каждого push/merge в `master`: повторно проверяет объединённый commit — Composer/security audit, полный PHP/JS lint, canonical schema import, production healthcheck, согласованность версии и upload-ready hosting bundle.

## Документация

- [`CHANGELOG.md`](CHANGELOG.md) — история и Unreleased.
- [`docs/CORE.md`](docs/CORE.md) — архитектура ядра.
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — пользовательские сценарии.
- [`docs/HOSTING_INSTALL.md`](docs/HOSTING_INSTALL.md) — fresh install на shared hosting без Composer/CLI.
- [`docs/PRODUCTION.md`](docs/PRODUCTION.md) — deployment, WSS, rate limiting и production checklist.
- [`docs/OPERATIONS.md`](docs/OPERATIONS.md) — backup/restore drill, multi-node rate limiting, trusted proxies и key-rotation procedures.
- [`TASKS_MODULE_README.md`](TASKS_MODULE_README.md) — дополнительная документация Tasks.
- [`default.env`](default.env) — environment variables и security comments.

## Что остаётся до production release

Проект всё ещё **alpha**. После закрытия исходного аудита основными оставшимися задачами являются:

- централизованные metrics/alerts/log aggregation и наблюдаемость production deployment;
- transactional re-encryption procedure для безопасной ротации `UNIQUE_KEY` / `MSG_SECRET_KEY`;
- постепенный вынос inline Smarty JS/CSS для CSP без `unsafe-inline`;
- при росте Messenger — scalable encrypted-search architecture вместо bounded decrypt scan.

## Production checklist

Перед выкладкой:

1. Для shared hosting используется готовый hosting bundle с `vendor/`; при deploy из source `composer install --no-dev --optimize-autoloader` проходит без ошибок.
2. Fresh install успешно завершается через `/install.php` без ручного SQL/`.env`.
3. `.env`, application source и service directories недоступны по HTTP.
4. `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET` уникальны и случайны.
5. `PRIVATE_STORAGE_PATH` находится вне document/application root.
6. HTTPS + same-site WSS reverse proxy настроены.
7. `WS_ALLOWED_ORIGINS` содержит только trusted origins.
8. Для existing DB `php bin/migrate.php --status` показывает ожидаемое состояние.
9. Legacy crypto migration выполнена/проверена, если нужна.
10. `php bin/healthcheck.php` возвращает `Healthcheck: OK`.
11. Backup БД/private storage создан и restore реально проверен.
12. Orphan cleanup запланирован.
13. Registration invite/rate limits настроены осознанно.
14. Logs, metrics и disk-space alerts подключены.
15. Последний `Master release gate` на объединённом commit `master` завершён успешно.
