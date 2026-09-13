# Workspace Organizer

**Версия:** `0.10.0-alpha`  
**Актуально на:** 13 сентября 2026  
**Статус:** active alpha / production hardening

Workspace Organizer — внутреннее PHP-приложение для корпоративной работы: заметки, задачи, личные файлы, профиль, администрирование и real-time Messenger.

После PR #45–#55 основные security- и schema-contract блокеры исходного аудита закрыты: Messenger, Notes, Tasks, Profile, fresh install, versioned DB upgrade и legacy crypto migration имеют отдельные проверяемые контракты. Текущий этап — UI/UX и production-readiness.

## Возможности

- **Notes** — XChaCha20-Poly1305 для текста, private attachments, голосовые вложения, view-only sharing по токену.
- **Tasks** — статусы, приоритеты, сроки, категории, подзадачи, фильтры и server-side sort allowlist.
- **File Manager** — личные папки/файлы вне document root, protected download, media и read-only text preview.
- **Messenger v2** — private/group chats, Saved Messages, forwarding, media, voice, reply/edit/delete, delivery/read receipts, reactions, encrypted search, pin/mute/archive, group roles/avatars и multi-device realtime.
- **Profile** — canonical user contract, private avatar, изменение данных/пароля и безопасная деактивация аккаунта.
- **Admin panel** — управление пользователями и custom profile fields.
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
- Composer;
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`; `gd` нужен для avatar/image flows;
- Apache + `mod_rewrite` либо Nginx с эквивалентным front-controller routing;
- writable private storage вне document root;
- HTTPS + WSS для production.

## Fresh install

### 1. Dependencies

```bash
composer install --no-dev --optimize-autoloader
cp default.env .env
```

Для development/CI можно использовать обычный `composer install`.

### 2. Environment

Минимальный production-набор:

```env
DBDRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBUSER=workspace
DBPASS=<strong-db-password>
DBNAME=workspace

SITEURL=https://workspace.example.com
BASE_PATH=/

UNIQUE_KEY=<random-secret-at-least-32-chars>
MSG_SECRET_KEY=<random-secret-at-least-32-chars>
WS_TICKET_SECRET=<random-secret-at-least-32-chars>

PRIVATE_STORAGE_PATH=/var/lib/notes/private
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
```

Генерация случайного секрета:

```bash
openssl rand -hex 32
```

Не коммитьте `.env` и не используйте одинаковые secrets между prod/stage/dev.

### 3. Private storage

Рекомендуемая структура:

```text
/var/lib/notes/private/
├── file_manager/
├── messenger/
├── notes/
├── users/
└── rate-limit/
```

Этот каталог должен принадлежать PHP/web process и **не должен** быть static location веб-сервера.

### 4. Database

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

### 5. Registration

По умолчанию web-registration закрыта. Для invite registration задайте:

```env
REGISTRATION_INVITE_CODE=<long-random-invite-secret>
```

После этого используется URL:

```text
/auth/registration/<REGISTRATION_INVITE_CODE>
```

Неверный или незаданный invite возвращает `404`.

### 6. WebSocket

Development:

```bash
php ws_server/server.php start
```

Production: запускайте Workerman через systemd/supervisor/container orchestration и публикуйте браузеру только через WSS reverse proxy.

## Upgrade existing DB

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

Healthcheck проверяет PHP/extensions, secrets, private storage, HTTPS/WSS consistency, DB connection и current schema contract. Ненулевой exit code означает, что deployment нельзя считать healthy.

## Rate limiting

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Baseline limiter хранит state под `PRIVATE_STORAGE_PATH/rate-limit`, использует `flock` и подходит для одного узла либо процессов с общим filesystem. Для multi-node deployment используйте shared limiter на reverse proxy/API gateway/Redis/WAF.

## HTTP / CSP baseline

Repository `.htaccess`:

- запрещает directory listing;
- закрывает `database`, `.logs`, `vendor`, `.env` и Composer metadata;
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

## Scheduled maintenance

Messenger orphan cleanup:

```bash
php bin/cleanup_messenger_orphans.php
```

Рекомендуемый cron/systemd timer: каждые 15–60 минут.

Также контролируйте disk space, права private storage, migration state, logs, backup/restore tests и удаление временных legacy keys.

## CI

GitHub Actions покрывают security baseline, PHP/Composer, clean schemas, DB upgrade, crypto migration, Notes/Tasks/Profile contracts и Messenger groups/media/search/voice/reactions/forwarding. Текущий product-readiness workflow дополнительно проверяет UI wiring, Linux bootstrap paths, rate limit middleware, CSP и healthcheck contract.

Полноценный browser + WSS smoke через production reverse proxy остаётся отдельным pre-release deployment test; repository CI не выдаёт его за уже выполненный.

## Документация

- [`CHANGELOG.md`](CHANGELOG.md) — история и Unreleased.
- [`docs/CORE.md`](docs/CORE.md) — архитектура ядра.
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — пользовательские сценарии.
- [`docs/PRODUCTION.md`](docs/PRODUCTION.md) — deployment, backup/restore, WSS, rate limiting и operations checklist.
- [`TASKS_MODULE_README.md`](TASKS_MODULE_README.md) — дополнительная документация Tasks.
- [`default.env`](default.env) — environment variables и security comments.

## Что остаётся до production release

Проект всё ещё **alpha**. Главные оставшиеся инфраструктурные задачи:

- browser/WSS E2E в реальном reverse-proxy окружении;
- shared rate limiter при multi-node deployment;
- централизованные metrics/alerts/log aggregation;
- регулярный disaster-recovery restore drill;
- процедура key rotation с контролируемым re-encryption;
- при росте Messenger — scalable encrypted-search architecture вместо bounded decrypt scan;
- постепенный вынос inline Smarty JS/CSS для CSP без `unsafe-inline`.

## Production checklist

Перед выкладкой:

1. `composer install --no-dev --optimize-autoloader` проходит без ошибок.
2. `.env` и service directories недоступны по HTTP.
3. `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET` уникальны и случайны.
4. `PRIVATE_STORAGE_PATH` находится вне document root.
5. HTTPS + same-site WSS reverse proxy настроены.
6. `WS_ALLOWED_ORIGINS` содержит только trusted origins.
7. `php bin/migrate.php --status` показывает ожидаемое состояние.
8. Legacy crypto migration выполнена/проверена, если нужна.
9. `php bin/healthcheck.php` возвращает `Healthcheck: OK`.
10. Backup БД/private storage создан и restore реально проверен.
11. Orphan cleanup запланирован.
12. Registration invite/rate limits настроены осознанно.
13. Logs, metrics и disk-space alerts подключены.
