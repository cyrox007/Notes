# Production deployment — Workspace Organizer

Этот документ дополняет `README.md` и описывает production-путь для stable-линии `1.0.x` (актуальный release candidate — `1.0.2`). Перед критичным deployment обязательны staging, backup и restore test.

## 1. Рекомендуемая схема

```text
Browser
  |
  | HTTPS / WSS
  v
Reverse proxy (Nginx/Apache/LB)
  |                    |
  | FastCGI/HTTP       | WebSocket proxy
  v                    v
PHP-FPM / Apache PHP   native WebSocket server
  |                    |
  +----------+---------+
             |
      MySQL + private storage
```

Browser не должен иметь прямого доступа к `PRIVATE_STORAGE_PATH`, MySQL или внутреннему native WebSocket server port.

## 2. Pre-deploy requirements

Минимум:

- PHP 8.1+ как технический compatibility floor; для Internet-facing production используйте поддерживаемую ветку PHP, сейчас рекомендуется 8.3+;
- MySQL 8.x;
- runtime не требует Composer/vendor dependencies;
- extensions `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`, `gd`;
- writable `PRIVATE_STORAGE_PATH` вне document root;
- TLS certificate и HTTPS для browser traffic;
- обычные long-lived HTTP requests и достаточная параллельность PHP workers для Messenger fallback;
- для рекомендуемого native WebSocket fast path: WSS reverse proxy, PHP CLI и возможность держать long-running process; `pcntl`/`posix` рекомендуются на Unix для daemon/signal/process-control функций;
- уникальные secrets для этого environment.

Production package является vendor-free; `composer install` для запуска приложения и WebSocket runtime не требуется.

## 3. Secrets

Минимальные secrets:

```env
UNIQUE_KEY=<random-at-least-32-chars>
MSG_SECRET_KEY=<random-at-least-32-chars>
WS_TICKET_SECRET=<random-at-least-32-chars>
```

Генерация:

```bash
openssl rand -hex 32
```

Правила:

1. prod/stage/dev используют разные значения;
2. secrets не хранятся в Git;
3. backup secrets должен быть защищён так же строго, как backup БД;
4. `MSG_LEGACY_SECRET_KEY` существует только на время legacy migration;
5. не меняйте encryption keys без re-encryption plan.

## 4. Private storage

Пример:

```text
/var/lib/notes/private/
├── file_manager/
├── messenger/
├── notes/
├── users/
└── rate-limit/
```

Рекомендуемые свойства root:

- владелец — PHP/web service account;
- доступ только требуемым service accounts;
- не находится внутри web root;
- не экспортируется web server как static location;
- входит в backup/restore process.

Приложение создаёт чувствительные каталоги/файлы с private permissions (`0700`/`0600`) там, где flow это поддерживает.

## 5. Database deployment

### Fresh install

Fresh installation использует canonical schemas/web installer на пустой БД.

### Existing installation

Перед миграцией:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
```

После review:

```bash
php bin/migrate.php
```

Applied migration checksums хранятся в `schema_migrations`. Не редактируйте уже применённый migration-файл: создавайте новый.

## 6. Legacy crypto migration

Перед запуском сохраните backup БД и текущие crypto keys.

```bash
php bin/migrate_crypto.php --scope=all --dry-run --limit=1000
php bin/migrate_crypto.php --scope=all --limit=1000
```

Для крупных таблиц используйте `--after-id` и bounded batches.

`--allow-plaintext-notes` разрешён только после ручного подтверждения legacy plaintext case. По умолчанию unknown encrypted payload блокирует migration.

## 7. HTTP reverse proxy

Production browser traffic должен приходить только по HTTPS.

Repository `.htaccess` содержит application-level baseline headers для Apache, но edge proxy всё равно должен отвечать за:

- TLS termination;
- HTTP -> HTTPS redirect;
- HSTS после подтверждения постоянного HTTPS;
- request/body limits, согласованные с upload limits приложения;
- access/error logs;
- optional edge rate limiting;
- WebSocket upgrade.

### HSTS

Включайте на TLS proxy после подтверждения, что installation постоянно обслуживается только по HTTPS. Messenger HTTP long-poll fallback работает поверх HTTPS и не требует сохранения plain HTTP, например:

```text
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

`preload` добавляйте только если понимаете последствия для всех subdomains.

## 8. Realtime Messenger: WebSocket / WSS + HTTP fallback

Environment:

```env
SITEURL=https://workspace.example.com
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
```

Native WebSocket server слушает внутренний port. Reverse proxy должен проксировать `/ws` с Upgrade/Connection headers.

Не публикуйте raw `ws://host:27800` в Internet production environment.

Native WebSocket process запускайте как managed service (systemd/Supervisor/container/hosting process manager), с restart policy и отдельным service account. Перед первым стартом используйте `php ws_server/server.php check`, после запуска — `php bin/ws_doctor.php`.

Если WebSocket недоступен, Messenger автоматически переходит на authenticated HTTP long poll через `/messenger/realtime/poll` и `/messenger/realtime/action`. Этот transport использует canonical Messenger dispatcher, RBAC/role policies, maintenance и license gates. Long-poll request освобождает PHP session lock; timeout задаётся `MESSENGER_LONG_POLL_TIMEOUT_SECONDS` (default 15, range 5–25). Клиент продолжает WS reconnect в фоне и после `Authorized` отменяет fallback.

Допускается один отдельный WS-узел. Он должен работать на том же release/commit, использовать ту же application DB, `WS_TICKET_SECRET`, `MSG_SECRET_KEY`, Messenger private storage и общий `UPDATE_STATE_PATH`. Shared DB realtime revision bridge уведомляет активные WS-клиенты о durable mutations из HTTP fallback. Несколько одновременно активных WS instances одной installation пока не поддерживаются как HA/load-balancing topology.

## 9. CSP and browser security

Current application CSP использует per-request cryptographic nonce для допустимого inline bootstrap, запрещает `unsafe-inline` и `unsafe-eval`, а CI фиксирует этот contract. File Manager не выполняет пользовательский код в браузере; text/code files открываются read-only. External JavaScript origins не добавляйте без отдельного review CSP/trust boundary.

## 10. Rate limiting

Application baseline:

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

State хранится в `PRIVATE_STORAGE_PATH/rate-limit` с file locks.

Это подходит для:

- одного web node;
- нескольких PHP workers на общем filesystem.

Для нескольких независимых nodes применяйте shared limiter (Redis, API gateway, load balancer/WAF). Edge limiter должен дополнять, а не отменять application authorization/CSRF checks.

## 11. Healthcheck

После deploy/migration:

```bash
php bin/healthcheck.php
```

Для monitoring:

```bash
php bin/healthcheck.php --json
```

Проверяются:

- PHP >= 8.3;
- required extensions;
- crypto/WebSocket secrets;
- writable private storage;
- SITEURL / WS_PUBLIC_URL scheme consistency;
- DB connection;
- 20 required current tables.

Exit code `1` означает unhealthy deployment.

## 12. Scheduled jobs

Messenger orphan cleanup:

```bash
php bin/cleanup_messenger_orphans.php
```

Рекомендуемая частота: 15–60 минут в зависимости от нагрузки.

Дополнительно планируйте:

- DB backup;
- private storage backup;
- backup verification;
- disk usage checks;
- log rotation.

## 13. Backup and restore

Backup считается рабочим только после restore test.

Минимальный набор:

1. MySQL dump / consistent snapshot;
2. `PRIVATE_STORAGE_PATH`;
3. соответствующие encryption/WebSocket secrets;
4. application release/commit identifier;
5. список applied migrations.

Restore drill должен проверять:

- вход пользователя;
- decrypt существующей Note;
- чтение Messenger history;
- скачивание protected attachments;
- Tasks/Profile state;
- WebSocket connection при включённом fast path;
- Messenger delivery через HTTP long poll при недоступном WebSocket и автоматический возврат на WS после восстановления.

## 14. Observability

Минимально отслеживайте:

- HTTP 4xx/5xx rate;
- auth 429/503;
- upload failures/413/415/429;
- PHP errors/exceptions;
- native WebSocket server disconnect/restart rate и HTTP errors/timeouts на `/messenger/realtime/*`;
- DB connection errors;
- migration/healthcheck failures;
- disk usage private storage;
- orphan cleanup errors.

Не логируйте passwords, crypto keys, raw session cookies, socket tickets или decrypted message/note bodies.

## 15. Release procedure

Рекомендуемый порядок:

1. freeze target commit;
2. full GitHub Actions green;
3. backup production;
4. deploy на staging из того же commit;
5. `php bin/migrate.php --dry-run`;
6. применить migrations;
7. выполнить нужный crypto migration;
8. `php bin/healthcheck.php`;
9. browser smoke: login -> Profile -> Notes -> Tasks -> Files -> Messenger;
10. WebSocket delivery + forced WebSocket loss → HTTP Long Poll → automatic WebSocket recovery smoke;
11. production deploy;
12. повторный healthcheck и monitoring review;
13. rollback при несовместимом contract failure.

## 16. Known production limitations

- одна installation поддерживает один active native WS process; multi-instance WS HA/load-balancing с distributed presence пока не поддерживается;
- typing/activity являются ephemeral WebSocket enhancement и в HTTP fallback не имеют той же оперативности, что durable state;
- long poll зависит от способности web stack держать длительные HTTP requests и от достаточного числа PHP workers;
- encrypted Messenger search остаётся bounded decrypt-scan, а не plaintext/full-text index;
- centralized metrics/log aggregation и график disaster-recovery drills остаются обязанностью production-окружения/оператора.
