# Workspace Organizer — документация ядра

Актуально для `0.11.0-alpha`, 13.09.2026.

## 1. Загрузка приложения

HTTP entry point — `index.php`. Он определяет `SITEPATH`, настраивает runtime/error logging, подключает `core.php`, затем Router и route config.

`core.php`:

- подключает Composer autoload;
- загружает `.env` через `vlucas/phpdotenv`;
- регистрирует namespace/path autoload;
- подключает базовые `core/*` классы;
- рекурсивно загружает `app/models`, `app/services`, `app/controllers`, `app/socket`, `app/handlers`, `app/middlewares`.

Имена bootstrap-файлов должны совпадать с реальным регистром имени на диске. Это обязательный Linux contract: `core/config.php`, `core/view.php`, `core/request.php` и другие lowercase-файлы нельзя подключать как `Config.php`/`View.php`.

## 2. Маршрутизация

Route source of truth — `core/routerConfig.php`.

Пример:

```php
$router->group('/notes')
    ->add('GET', '/', [NoteController::class, 'index'], [LoginRequared::class], 'notes')
    ->add('POST', '/', [NoteController::class, 'create'], [LoginRequared::class], 'note_create')
    ->endGroup();
```

Динамические параметры:

- `{int:id}` -> digits -> `int`;
- `{str:uid}` -> `\w`/hyphen contract, подходит для UUID/token-like values текущего Router.

Router нормализует URL, проверяет method, выполняет middleware по порядку и вызывает controller с `Request` первым аргументом.

Именованные routes используются через `Router::redirect()` и Smarty `{route_path ...}`.

## 3. Запросы / CSRF

`Core\Request` инкапсулирует GET, POST, FILES, SERVER, JSON body и session.

Примеры:

```php
$request->get('sort', 'created_at');
$request->post('name', '');
$request->json('action');
$request->session('user_id');
$request->hasFile('file');
```

Sanitize не заменяет domain validation. Enum, длины, ID/UID, ownership, MIME, paths и business rules проверяются отдельно.

State-changing HTTP action не должен использовать GET. Unsafe methods проходят CSRF policy; browser bootstrap также автоматически добавляет CSRF header для same-origin `fetch`/XHR.

## 4. Контроллеры / Smarty

`Core\Controller` инициализирует Smarty, helpers и общий request/render contract.

Template helpers:

- `{route_path name="..."}`;
- `{csrf_token}`;
- `{session key="..."}`;
- `{jsonParse ...}`.

Пользовательский текст рендерится с escaping. Не создавайте HTML/JS из user-controlled string без отдельного безопасного renderer.

## 5. Middleware

Основные middleware:

- `LoginRequared` — authenticated active user;
- `IsAdmin` — admin route gate;
- `CSRFMiddleware` — state-changing request protection;
- `AuthRateLimit` — login/registration fixed-window limit;
- `UploadRateLimit` — upload endpoint limit;
- `StorageQuotaLimit` — File Manager quota gate перед физической записью файла.

По умолчанию rate limiter state хранится под `PRIVATE_STORAGE_PATH/rate-limit`, файл блокируется `flock`, directory/file permissions — private. Для нескольких web-узлов используется отдельный общий `RATE_LIMIT_STORAGE_PATH` на POSIX volume с рабочими advisory locks.

`StorageQuotaLimit` захватывает per-user MySQL advisory lock, проверяет текущий used space и effective quota, а lock удерживается до завершения HTTP upload request. Поэтому два одновременных upload одного пользователя не могут оба зарезервировать один и тот же остаток квоты.

Middleware определяет класс доступа к endpoint, но не заменяет resource ACL. Note/File/Dialog/Message/Task ownership проверяется в Controller/Service.

## 6. Слой базы данных

В проекте остаются два слоя:

### ORM

`Core\ORM` удобен для простого model CRUD/select.

### DatabaseManager

`Core\DatabaseManager` используется для parameterized SQL, транзакций, locking-aware operations и явных security-sensitive contracts.

Правила:

- user values передаются parameters;
- SQL identifiers/order columns никогда не берутся напрямую из request;
- sort/filter keys преобразуются через server allowlist;
- ACL condition является частью SQL/service contract, а не только UI filter.

Постепенно новый security-sensitive код следует писать через явные Service + DatabaseManager contracts вместо добавления новой магии в legacy ORM.

## 7. Схема базы данных и обновления

Canonical fresh schemas:

```text
database/messenger_schema.sql
database/notes_schema.sql
database/file_manager_schema.sql
database/user_fields_schema.sql
database/tasks_schema.sql
database/settings_schema.sql
```

Current fresh contract содержит 22 обязательные таблицы. `system_settings` хранит редактируемые системные значения, а `user_storage_quotas` — только per-user quota overrides. Использованный объём хранилища не кэшируется отдельным счётчиком: `StorageQuotaService` вычисляет его из активных строк `user_files`, поэтому delete/restore файлов не требует синхронизации отдельной usage-таблицы.

Existing DB обновляется только через versioned runner:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
```

Runner использует явный dependency order, delimiter-aware parsing и `schema_migrations` с SHA-256 checksum. Applied migration нельзя переписывать; для следующего изменения создаётся новый файл.

Web installer предназначен для empty/fresh DB и не заменяет upgrade runner.

## 8. Криптография

### Notes

`App\Helpers\CryptMethods`:

- key source `UNIQUE_KEY`;
- HKDF-SHA256;
- libsodium XChaCha20-Poly1305;
- UID заметки используется как AAD;
- failure — fail-closed.

Нельзя сохранять plaintext в flow, объявленном encrypted.

### Messenger

Messenger использует отдельный `MSG_SECRET_KEY` и versioned XChaCha20-Poly1305 payload. Это server-side encryption at rest, **не E2E**.

Legacy ciphertext переносится отдельным resumable CLI:

```bash
php bin/migrate_crypto.php --scope=all --dry-run --limit=1000
php bin/migrate_crypto.php --scope=all --limit=1000
```

Unknown legacy Notes payload не должен автоматически трактоваться как plaintext.

`WS_TICKET_SECRET` можно ротировать с coordinated restart HTTP/WS процессов; ранее выданные socket tickets после смены секрета перестают проходить проверку. `UNIQUE_KEY` и `MSG_SECRET_KEY` нельзя заменять напрямую в `.env`: для них требуется отдельный old-key -> new-key re-encryption process с верификацией.

## 9. Приватное хранилище

```env
PRIVATE_STORAGE_PATH=/var/lib/notes/private
```

Layout:

```text
PRIVATE_STORAGE_PATH/
├── file_manager/
├── messenger/
├── notes/
├── users/
├── rate-limit/
├── logs/
└── legacy/
```

Инварианты:

1. storage вне document root;
2. browser получает opaque ID/UID, не physical path;
3. endpoint повторно проверяет ACL;
4. resolved path остаётся внутри разрешённого root;
5. MIME определяется сервером;
6. private files не обслуживаются static web location;
7. attachment encryption status должен отражать реальную защиту, а не желаемую.

Notes attachment bytes сейчас private + ACL, но не отдельно encrypted at-rest; `is_encrypted=0` является намеренным contract.

## 10. Браузерный и quota-контракт File Manager

File Manager не является code execution environment.

- media открывается через protected `/files/get/{id}/`;
- текстовые/code-файлы могут показываться только read-only;
- user file content не подставляется в `eval`, `srcdoc` или executable script context;
- внешние editor CDN не требуются;
- effective storage quota = per-user override из `user_storage_quotas` либо `file_manager_default_quota_bytes` из `system_settings`;
- used bytes = `SUM(user_files.size)` только для активных non-folder rows;
- upload выше effective quota отклоняется до `move_uploaded_file`;
- concurrent uploads одного пользователя сериализуются advisory lock.

## 11. WebSocket / Messenger

Entry point — `ws_server/server.php`.

Security contract:

- browser получает short-lived socket ticket через authenticated HTTP;
- server связывает connection с user identity;
- client-supplied identity fields не считаются доверенными;
- Origin проверяется по `WS_ALLOWED_ORIGINS`;
- `Socket:method` находится в explicit allowlist;
- active/role state повторно проверяется, поэтому deactivation/blocking отзывает действия существующего connection;
- один user может иметь несколько active connections, события fan-out идут на все его devices/tabs.

Socket handler должен оставаться transport layer; authorization/business logic живёт в Service.

Production-like E2E поднимает настоящий native PHP WebSocket server за TLS Nginx reverse proxy и проверяет две независимые Chromium-сессии, authenticated WSS и realtime message fan-out.

## 12. Архитектура интерфейса

UI остаётся server-rendered Smarty без Node build pipeline.

Структура:

- `app/views/core/common.css` — design tokens/global shell;
- `app/views/core/theme-refresh.css` — product-wide compatibility/visual layer поверх legacy module CSS;
- `app/views/core/accessibility.css` — reusable accessibility utilities;
- `app/views/^shared/*` — sidebar/header/footer;
- module CSS/JS — локальная логика.

Новый UI не должен возвращать external font/CDN dependency без отдельного обоснования.

CSP сейчас не требует `unsafe-eval`; `unsafe-inline` остаётся временно из-за legacy inline Smarty blocks. Целевое направление — static assets + nonce/hash CSP.

## 13. Регистрация / rate limiting

Web-registration закрыта без:

```env
REGISTRATION_INVITE_CODE=<secret>
```

Rate limit config:

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Single-node deployment использует private file-backed limiter. Multi-node deployment задаёт `DEPLOYMENT_NODE_COUNT>1` и отдельный shared `RATE_LIMIT_STORAGE_PATH`; healthcheck отклоняет multi-node config с локальным storage. `X-Real-IP`/`X-Forwarded-For` доверяются только если immediate proxy входит в `TRUSTED_PROXY_IPS`.

## 14. Проверка состояния

```bash
php bin/healthcheck.php
php bin/healthcheck.php --json
```

Проверяются PHP version/extensions, required secrets, private storage, SITEURL/WSS consistency, deployment node count, rate-limit storage, trusted proxy allowlist, DB connection, 22-table schema contract и наличие валидного default File Manager quota seed.

Healthcheck — deployment gate, а не замена application monitoring.

## 15. Как добавлять новый модуль

Рекомендуемый порядок:

1. определить DB contract и migration;
2. определить Service/ACL boundaries;
3. реализовать Controller/Socket;
4. зарегистрировать HTTP/socket route в explicit config/allowlist;
5. добавить domain validation и resource ACL;
6. добавить UI без расширения CSP без необходимости;
7. добавить integration/runtime workflow;
8. обновить README/CHANGELOG/USER_GUIDE/CORE при изменении contract.

## 16. Инварианты безопасности

Обязательные правила:

- state-changing action не GET;
- unsafe HTTP request проходит CSRF;
- session/socket identity приоритетнее user ID из browser payload;
- upload не исполняется web server/browser;
- physical storage path не возвращается клиенту;
- crypto failure не ведёт к plaintext fallback;
- SQL identifiers/sort fields — allowlist;
- soft-delete учитывается в read/ACL paths;
- realtime broadcast не расширяет права Service read path;
- deactivated/blocked account не продолжает authenticated HTTP/WS actions;
- новый production-sensitive contract сопровождается integration test.

## 17. Эксплуатация в production

Deployment, reverse proxy, WSS, healthcheck и release checklist описаны в [`PRODUCTION.md`](PRODUCTION.md). Backup/restore drill, shared rate limiting, trusted proxy contract и key-rotation procedures описаны в [`OPERATIONS.md`](OPERATIONS.md).

После merge в `master` отдельный `Master release gate` повторно проверяет уже объединённый commit: Composer audit, PHP/JS syntax, canonical DB schemas, production healthcheck, version contract и upload-ready hosting bundle.
