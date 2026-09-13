# Workspace Organizer

**Версия:** `0.10.0-alpha`  
**Дата актуализации:** 13 сентября 2026  
**Статус:** active alpha / production hardening

Workspace Organizer — PHP-приложение для внутреннего рабочего пространства: зашифрованные заметки, задачи, личные файлы, профиль, администрирование и real-time Messenger.

После security/contract hardening проект больше не опирается на старые несовместимые схемы Messenger/Tasks/Profile. Fresh install, versioned DB upgrade path и миграция legacy ciphertext имеют отдельные проверяемые контракты.

## Возможности

- **Notes** — зашифрованный текст, private attachments, голосовые вложения, view-only sharing по токену.
- **Tasks** — статусы, приоритеты, сроки, категории, подзадачи, фильтрация и серверный sort allowlist.
- **File Manager** — личные папки и файлы вне document root с авторизованной выдачей.
- **Messenger v2** — private/group chats, Saved Messages, forwarding, media, voice, reply/edit/delete, delivery/read receipts, reactions, encrypted search, pin/mute/archive, group roles/avatars и multi-device realtime.
- **Profile** — canonical user contract, private avatar, изменение данных/пароля и безопасная деактивация аккаунта.
- **Admin panel** — управление пользователями и custom profile fields.
- **Responsive UI** — общий design system, mobile navigation drawer, единая типографика/формы/карточки, keyboard focus и reduced-motion support.

## Важная модель безопасности

- Пароли хешируются через `password_hash` / Argon2id.
- Notes text шифруется через `UNIQUE_KEY` + XChaCha20-Poly1305 с UID заметки как AAD.
- Messenger text/captions шифруются отдельным `MSG_SECRET_KEY` и versioned XChaCha20-Poly1305 payload.
- Crypto failures для новых encrypted данных работают fail-closed.
- WebSocket identity определяется короткоживущим подписанным ticket на сервере, а не UID из браузера.
- WebSocket origins/actions находятся в allowlist.
- File Manager, Messenger media, Notes attachments и user avatars находятся в `PRIVATE_STORAGE_PATH`, а не в public uploads.
- Upload MIME определяется сервером (`finfo`) и сверяется с allowlist.
- Unsafe HTTP actions проходят CSRF policy.
- Login/registration и upload endpoints имеют встроенный request rate limit.
- Регистрация закрыта, пока явно не задан `REGISTRATION_INVITE_CODE`.

> **Не E2E:** Messenger использует server-side encryption at rest. Сервер способен расшифровать сообщения и поэтому это не end-to-end encryption.

> **Attachments:** Messenger/Notes/File Manager attachment bytes и avatars защищены private filesystem + ACL. Они не считаются отдельно зашифрованными at-rest, если конкретный storage path явно не реализует такое шифрование. Для новых Notes attachments `is_encrypted=0` намеренно отражает реальность.

## Требования

- PHP `8.3+`;
- MySQL `8.x` (основной проверяемый CI path; совместимость с MariaDB требует отдельной проверки);
- Composer;
- PHP extensions: `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`; `gd` нужен для image/avatar flows;
- Apache + mod_rewrite или Nginx с эквивалентным front-controller routing;
- writable private directory вне document root;
- HTTPS/WSS для production.

## Быстрый fresh install

### 1. Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

Для development/CI допускается обычный:

```bash
composer install
```

### 2. Environment

```bash
cp default.env .env
```

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

Секреты удобно генерировать так:

```bash
openssl rand -hex 32
```

Никогда не коммитьте `.env` и не используйте одинаковые ключи для prod/stage/dev.

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

Корневой каталог должен принадлежать пользователю PHP-FPM/веб-процесса и **не должен** быть static location веб-сервера. Чувствительные файлы создаются с private permissions; rate-limit state хранится с режимом `0600`.

### 4. Canonical database schema

Для новой пустой БД применяются все пять схем:

```bash
mysql -u root -p workspace < database/messenger_schema.sql
mysql -u root -p workspace < database/notes_schema.sql
mysql -u root -p workspace < database/file_manager_schema.sql
mysql -u root -p workspace < database/user_fields_schema.sql
mysql -u root -p workspace < database/tasks_schema.sql
```

Fresh contract включает 20 обязательных таблиц: users/Messenger, Notes, File Manager, custom fields и Tasks.

Web-installer `install.php` предназначен только для **новой/пустой** БД. Если он обнаруживает неполную legacy DB, он не пытается «достроить» её поверх существующих данных и направляет на CLI migration path.

После успешной установки наличие `.env` блокирует повторный запуск web-installer.

### 5. Регистрация пользователей

По умолчанию web-registration закрыта. Чтобы разрешить создание аккаунтов по invite URL, задайте длинный случайный секрет:

```env
REGISTRATION_INVITE_CODE=<long-random-invite-secret>
```

Ссылка:

```text
/auth/registration/<REGISTRATION_INVITE_CODE>
```

Пустое или неверное значение возвращает `404`. Созданный установщиком administrator не зависит от web-registration.

### 6. WebSocket

Development:

```bash
php ws_server/server.php start
```

Production: запускайте Workerman через systemd/supervisor/container orchestration и публикуйте только через WSS reverse proxy.

## Обновление существующей установки

Перед любым обновлением:

1. Сделайте backup БД.
2. Сделайте backup `PRIVATE_STORAGE_PATH`.
3. Сохраните действующие crypto keys.
4. Выполните dry-run миграций на staging.
5. Проверьте runtime healthcheck.
6. После этого обновляйте production.

### Versioned DB migrations

Проверить состояние:

```bash
php bin/migrate.php --status
```

Показать pending migrations без изменений:

```bash
php bin/migrate.php --dry-run
```

Применить:

```bash
php bin/migrate.php
```

`schema_migrations` хранит имя и SHA-256 checksum каждого применённого файла. Изменение уже применённой migration приводит к fail-closed ошибке — создавайте новый migration-файл вместо редактирования истории.

### Legacy crypto migration

После DB migration сначала запускайте dry-run:

```bash
php bin/migrate_crypto.php --scope=all --dry-run --limit=1000
```

Затем миграцию партиями:

```bash
php bin/migrate_crypto.php --scope=all --limit=1000
```

Продолжение после определённого primary-key ID:

```bash
php bin/migrate_crypto.php --scope=messenger --limit=1000 --after-id=5000
```

Опция:

```text
--allow-plaintext-notes
```

нужна только для явно подтверждённых legacy Notes, которые исторически были помечены encrypted, но фактически содержат plaintext. Не включайте её без предварительного dry-run/backup.

После успешного перевода старых Messenger ciphertext удалите временный `MSG_LEGACY_SECRET_KEY`.

## Production healthcheck

Новый CLI smoke-check:

```bash
php bin/healthcheck.php
```

или JSON для monitoring:

```bash
php bin/healthcheck.php --json
```

Он проверяет:

- PHP version и обязательные extensions;
- наличие активных crypto/WebSocket secrets;
- доступность и writable state private storage;
- корректность `SITEURL` / `WS_PUBLIC_URL` (`https` требует `wss`);
- соединение с БД;
- присутствие 20 таблиц current schema contract.

Ненулевой exit code означает, что deployment нельзя считать healthy.

## Rate limiting

Встроенные лимиты:

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Состояние хранится под `PRIVATE_STORAGE_PATH/rate-limit` с `flock`. Это корректный baseline для одного приложения/узла либо нескольких процессов с общим filesystem.

Для горизонтально масштабированной установки с разными локальными дисками дополнительно используйте shared limiter (Redis/API gateway/reverse proxy/WAF). Не доверяйте `X-Forwarded-For`, пока доверенные proxy явно не настроены.

## HTTP/CSP baseline

Apache `.htaccess`:

- запрещает directory listing;
- блокирует `database`, `.logs`, `vendor`, `.env`, Composer metadata;
- блокирует `install.php` после появления `.env`;
- задаёт `nosniff`, Referrer Policy, SAMEORIGIN, Permissions Policy и COOP;
- CSP запрещает objects, ограничивает forms/base/frame ancestors текущим origin;
- разрешает `ws:`/`wss:` для realtime;
- сохраняет `cdnjs.cloudflare.com` только потому, что File Manager пока использует внешний Ace editor.

HSTS намеренно не включён в repository `.htaccess`: его следует задавать на production TLS reverse proxy только после подтверждения постоянного HTTPS.

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

## UI / UX

Текущий интерфейс использует server-rendered Smarty без отдельного frontend build pipeline.

Product UI layer:

- системный font stack без Google Fonts;
- единые tokens для цвета, surface, border, radius и shadow;
- responsive sidebar: desktop collapse + mobile drawer;
- active navigation state по текущему route;
- sticky top toolbar;
- обновлённые dashboard/auth/Notes/Tasks/Profile/File Manager/Admin surfaces;
- Messenger подключён к общим tokens без переписывания его realtime component logic;
- keyboard focus, skip-link, aria-live notification region;
- `prefers-reduced-motion` support;
- touch devices не зависят исключительно от hover для file/message actions.

Внешний Ace editor File Manager пока загружается с cdnjs. Для полностью self-contained/offline deployment его следует vendoring-нуть локально отдельным изменением.

## Notes

Основной flow:

```text
POST /notes/                         создать заметку
GET  /notes/{uid}/edit               открыть редактор
POST /notes/{uid}/edit               сохранить
POST /notes/{uid}/delete             soft-delete
POST /notes/upload/{uid}             attachment upload
GET  /notes/attachment/{fileUid}     owner download
POST /notes/attachment/delete/{id}   attachment delete
POST /notes/share/{uid}              создать public view share
POST /notes/unshare/{uid}            отключить share
GET  /notes/shared/{token}           public view
```

Notes sort fields выбираются только из серверного allowlist.

## Tasks

Canonical `tasks_schema.sql` является частью fresh install. Tasks поддерживают:

- pending / in_progress / completed / cancelled;
- low / medium / high / urgent priority;
- due dates и overdue filter;
- subtasks;
- user/global categories;
- category relations;
- серверный filter/sort allowlist.

## Messenger v2

На current `master`/release contract входят:

- socket-ticket auth, origin/action allowlists;
- private/group dialogs;
- Saved Messages;
- forwarding text/media с независимыми media copies;
- reply/edit/delete-for-me/delete-for-all;
- delivered/read cursors;
- private media + byte-range streaming;
- voice recording/player;
- realtime reactions;
- multi-device fanout;
- pin/mute/archive;
- owner/admin/member group management;
- private group avatars;
- media replies и orphan cleanup;
- bounded encrypted search.

### Ограничение encrypted search

Поиск не хранит plaintext index: он расшифровывает ограниченное число последних доступных сообщений (`MESSENGER_SEARCH_SCAN_LIMIT`, default `1000`). Это осознанный privacy/complexity trade-off, а не полнотекстовый индекс всей истории.

## Profile / account lifecycle

- canonical `users` fields;
- private user avatar с authenticated delivery;
- email normalization/uniqueness;
- смена пароля требует текущий пароль;
- account self-delete заменён на деактивацию (`is_active=0`), связанные Notes/данные сохраняются;
- владелец активной группы должен передать ownership перед деактивацией;
- WebSocket повторно проверяет active/role state.

## Cleanup / scheduled maintenance

Messenger orphan media:

```bash
php bin/cleanup_messenger_orphans.php
```

Запускайте по cron/systemd timer, например каждые 15–60 минут.

Также регулярно контролируйте:

- размер private storage;
- права каталогов/файлов;
- backup/restore test;
- состояние DB migrations;
- удаление временных legacy keys после завершения migration;
- application/web/proxy logs.

## CI

GitHub Actions покрывают:

- security baseline;
- Composer validate/install/audit и PHP syntax;
- crypto fail-closed/migration scenarios;
- clean canonical schemas;
- Notes private storage;
- Tasks contract;
- Profile/user lifecycle;
- installer + versioned DB upgrade;
- Messenger groups/media/search/voice/reactions/forwarding;
- product UI/static contract;
- file-backed rate limiter integration;
- production healthcheck на MySQL 8.4.

Security-sensitive функции должны иметь integration/runtime test, а не только lint/grep.

## Что ещё не следует считать решённым

Проект всё ещё имеет статус **alpha**. Перед публичным/критичным production deployment остаются инфраструктурные задачи:

- полноценный browser/WSS end-to-end smoke в реальном reverse-proxy окружении;
- централизованный rate limiter для multi-node deployment;
- централизованные metrics/alerts/log aggregation;
- документированный и регулярно проверяемый disaster recovery/restore процесс;
- процедура key rotation с контролируемым re-encryption;
- при необходимости — scalable blind-index/search architecture вместо bounded decrypt scan;
- vendoring Ace editor, если запрещены внешние CDN.

## Документация

- [`CHANGELOG.md`](CHANGELOG.md) — история и Unreleased.
- [`docs/CORE.md`](docs/CORE.md) — Router/Request/DB/Crypto/WebSocket/private storage architecture.
- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — пользовательские сценарии.
- [`TASKS_MODULE_README.md`](TASKS_MODULE_README.md) — дополнительная документация Tasks.
- [`default.env`](default.env) — environment variables и security comments.

## Production checklist

Перед выкладкой убедитесь, что:

- `composer install --no-dev --optimize-autoloader` завершился успешно;
- `.env` не доступен через HTTP;
- `UNIQUE_KEY`, `MSG_SECRET_KEY`, `WS_TICKET_SECRET` уникальны и >= 32 случайных символов;
- `PRIVATE_STORAGE_PATH` находится вне document root;
- HTTPS + WSS настроены на reverse proxy;
- `WS_ALLOWED_ORIGINS` содержит только реальные trusted origins;
- `php bin/migrate.php --status` показывает ожидаемое состояние;
- legacy crypto migration выполнена/проверена при необходимости;
- `php bin/healthcheck.php` возвращает `Healthcheck: OK`;
- backup БД и private storage создан и restore проверен;
- orphan cleanup запланирован;
- registration invite/rate limits настроены осознанно;
- логи/мониторинг и disk-space alerts подключены.
