# Production-развёртывание Workspace Organizer

Этот документ дополняет `README.md` и описывает актуальный production-путь для линии Workspace Organizer 1.x. Перед критичным deployment обязательны staging, актуальный backup и проверенный restore drill.

## 1. Рекомендуемая схема

Штатный single-node вариант:

```text
Browser
  |
  | HTTPS / WSS
  v
Reverse proxy (Nginx/Apache/LB)
  |                    |
  | FastCGI/HTTP       | WebSocket proxy
  v                    v
PHP-FPM / Apache PHP   Native PHP WebSocket server
  |                    | 127.0.0.1:27800
  +----------+---------+
             |
      MySQL + private storage
```

Browser не должен иметь прямого доступа к `PRIVATE_STORAGE_PATH`, MySQL или внутреннему native WebSocket port.

Native WebSocket process можно вынести на **один отдельный WS-узел**. Полный контракт такого режима, включая общую DB, secrets, private storage и maintenance state, описан в `docs/MESSENGER_SERVER.md`.

## 2. Требования перед deployment

Минимум:

- PHP 8.1+ как technical compatibility floor; для Internet-facing production рекомендуется поддерживаемая ветка PHP, сейчас 8.3+;
- MySQL 8.x;
- extensions `mysqli`, `pdo_mysql`, `mbstring`, `json`, `fileinfo`, `sodium`, `gd`;
- PHP CLI для operator commands и native WebSocket runtime;
- writable `PRIVATE_STORAGE_PATH` вне document root;
- HTTPS certificate;
- WSS reverse proxy или отдельный WSS endpoint;
- уникальные secrets для этого environment.

Runtime 1.x vendor-free: production bundle не требует `composer install` и каталога `vendor/`.

Перед deployment:

```bash
php bin/healthcheck.php --json
php bin/migrate.php --status
php bin/migrate.php --dry-run
```

## 3. Secrets

Минимальные secrets:

```env
UNIQUE_KEY=<random-at-least-32-chars>
MSG_SECRET_KEY=<random-at-least-32-chars>
WS_TICKET_SECRET=<random-at-least-32-chars>
```

Генерация случайного значения, например:

```bash
openssl rand -hex 32
```

Правила:

1. prod/stage/dev используют разные значения;
2. secrets не хранятся в Git;
3. backup secrets защищается независимо от обычного backup данных;
4. `MSG_LEGACY_SECRET_KEY` используется только на время legacy crypto migration;
5. `UNIQUE_KEY` и `MSG_SECRET_KEY` нельзя менять напрямую без штатного re-encryption/rotation flow;
6. `WS_TICKET_SECRET` можно ротировать с coordinated restart HTTP/WS runtime.

## 4. Private storage

Пример:

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

Требования к root:

- находится вне document root;
- writable для нужного PHP/service account;
- не публикуется web server как static location;
- входит в backup/restore process;
- physical paths никогда не выдаются browser как публичные URL.

Приложение создаёт чувствительные directories/files с private permissions (`0700`/`0600`) там, где flow это поддерживает.

## 5. База данных

### Чистая установка

Fresh installation использует web installer и composition-aware canonical schemas на пустой БД.

### Существующая установка

Перед compatibility upgrade:

```bash
php bin/migrate.php --status
php bin/migrate.php --dry-run
```

После проверки:

```bash
php bin/migrate.php
```

`schema_migrations` — журнал compatibility upgrade scripts, а не канонический источник текущей схемы. Уже применённый migration/upgrade SQL нельзя переписывать: для следующего изменения создаётся новый immutable script.

## 6. Legacy crypto migration и rotation keys

Перед migration/rotation сохраните backup БД, private storage и соответствующие crypto keys.

Проверка legacy payload:

```bash
php bin/migrate_crypto.php --scope=all --dry-run --limit=1000
```

Применение:

```bash
php bin/migrate_crypto.php --scope=all --limit=1000
```

Для крупных таблиц используйте bounded batches и `--after-id`.

`--allow-plaintext-notes` разрешён только после ручного подтверждения legacy plaintext case. По умолчанию unknown encrypted payload блокирует migration.

Штатная смена `UNIQUE_KEY`/`MSG_SECRET_KEY` выполняется через `bin/rotate_data_keys.php` под maintenance. Подробности — `docs/OPERATIONS.md`.

## 7. HTTP reverse proxy

Production browser traffic должен приходить только по HTTPS.

Repository `.htaccess` содержит application-level baseline для Apache, но edge proxy всё равно отвечает за:

- TLS termination;
- HTTP -> HTTPS redirect;
- HSTS после подтверждения постоянного HTTPS;
- request/body limits, согласованные с upload limits приложения;
- access/error logs;
- optional edge rate limiting;
- WebSocket Upgrade.

### HSTS

Включайте на TLS proxy только когда HTTP fallback больше не нужен, например:

```text
Strict-Transport-Security: max-age=31536000; includeSubDomains
```

`preload` добавляйте только если последствия для всех subdomains осознаны.

## 8. WebSocket / WSS

Штатная same-host configuration:

```env
SITEURL=https://workspace.example.com
WS_PUBLIC_URL=wss://workspace.example.com/ws
WS_ALLOWED_ORIGINS=https://workspace.example.com
WS_HOST=127.0.0.1
WS_PORT=27800
```

Запуск:

```bash
php ws_server/server.php start
php ws_server/server.php status
php bin/ws_doctor.php
```

Native listener обычно остаётся на loopback, а reverse proxy проксирует public `/ws` к `127.0.0.1:27800`.

Не публикуйте raw `ws://host:27800` в Internet production environment.

Process запускайте как managed service через systemd/Supervisor/аналогичный process manager с restart policy и отдельным service account.

Для отдельного WS-сервера и ограничений multi-instance используйте `docs/MESSENGER_SERVER.md`. Несколько активных WS instances одной installation пока не являются поддерживаемой HA/load-balancing topology.

## 9. CSP и browser security

Текущий Core формирует CSP с per-request nonce. Policy:

- не требует `unsafe-eval`;
- запрещает inline event/style attributes;
- допускает только контролируемые inline `<script>/<style>` с nonce;
- ограничивает `connect-src` для HTTP/WebSocket transport;
- не требует внешних JS CDN для runtime.

File Manager не является code execution environment: пользовательские text/code files открываются read-only и не выполняются через `eval`, `srcdoc` или executable script context.

## 10. Rate limiting и proxy trust

Application baseline:

```env
MAX_LOGIN_ATTEMPTS=5
AUTH_RATE_LIMIT_WINDOW_SECONDS=300
UPLOAD_RATE_LIMIT_ATTEMPTS=60
UPLOAD_RATE_LIMIT_WINDOW_SECONDS=60
```

Single-node limiter по умолчанию использует file-backed state под `PRIVATE_STORAGE_PATH/rate-limit`.

Для нескольких web-узлов задайте общий `RATE_LIMIT_STORAGE_PATH` с рабочим `flock` и `DEPLOYMENT_NODE_COUNT>1`. `X-Real-IP`/`X-Forwarded-For` учитываются только от адресов из `TRUSTED_PROXY_IPS`.

Multi-node HTTP deployment также требует sticky PHP sessions или общего session backend.

## 11. Healthcheck

После deploy/migration:

```bash
php bin/healthcheck.php
php bin/healthcheck.php --json
```

Healthcheck проверяет, в зависимости от packaged composition:

- PHP version и required extensions;
- обязательные secrets;
- private storage и его расположение вне application root;
- security event storage;
- SITEURL/WSS/origin consistency;
- deployment node/rate-limit storage;
- trusted proxy allowlist;
- DB connection;
- текущий composition-aware database contract;
- обязательные settings конкретных modules.

Ненулевой exit code означает, что deployment нельзя считать healthy.

Для WebSocket дополнительно:

```bash
php bin/ws_doctor.php
php ws_server/server.php status
```

## 12. Scheduled jobs

Messenger orphan cleanup:

```bash
php bin/cleanup_messenger_orphans.php
```

Рекомендуемая частота: 15–60 минут в зависимости от нагрузки.

Также планируйте:

- DB backup;
- private storage backup;
- backup verification/restore drill;
- disk usage checks;
- log rotation;
- observability summary;
- retention preview.

## 13. Backup и restore

Backup считается рабочим только после restore drill.

Минимальный набор:

1. consistent MySQL dump/snapshot;
2. пользовательские части `PRIVATE_STORAGE_PATH`;
3. manifest/checksums backup;
4. application release/commit identifier.

Активные secrets/`.env` храните отдельно от обычного data archive.

Restore drill в изолированной среде должен проверять:

- login;
- decrypt существующей Note;
- чтение Messenger history;
- скачивание protected attachments;
- Tasks/Profile/File Manager state;
- WebSocket connection;
- `php bin/healthcheck.php`.

Подробный contract — `docs/OPERATIONS.md`.

## 14. Observability

Минимально контролируйте:

- HTTP 4xx/5xx rate;
- auth failures/429/503;
- upload failures/413/415/429;
- PHP errors/exceptions;
- native WebSocket disconnect/restart failures;
- DB connection errors;
- updater/migration/healthcheck failures;
- free space private storage/DB;
- orphan cleanup errors;
- security events.

Не логируйте passwords, crypto keys, raw session cookies, socket tickets, license tokens или decrypted message/note bodies.

Встроенный summary:

```bash
php bin/observability.php --json
```

## 15. Обновления

Для установленной 1.0.2+ нормальный operator flow:

```bash
php bin/update_doctor.php --json
php bin/update_run.php --yes --json
```

Обновление использует signed manifest/package, external staging, maintenance, verified code+MySQL rollback backup, external candidate, migrations/healthcheck и automatic rollback.

Для первого перехода `1.0.1 -> 1.0.2` используйте trusted external bootstrap, описанный в `docs/UPDATES.md`.

## 16. Процедура deployment/release

Рекомендуемый порядок:

1. зафиксировать target commit/release;
2. убедиться, что release-relevant GitHub Actions зелёные;
3. создать и проверить backup;
4. развернуть тот же artifact на staging;
5. выполнить `php bin/migrate.php --dry-run`;
6. выполнить compatibility migrations, если они требуются;
7. выполнить необходимые crypto migration/rotation actions;
8. запустить `php bin/healthcheck.php`;
9. запустить `php bin/ws_doctor.php` на WS-узле;
10. browser smoke: login -> Profile -> Notes -> Tasks -> Files -> Messenger;
11. WSS reconnect/multi-tab smoke;
12. production deploy/update;
13. повторить healthcheck/observability review;
14. при update failure следовать transaction recovery/rollback, а не заменять файлы вручную.

## 17. Дополнительные production-задачи

Уровень production maturity поддерживается постоянными операционными практиками:

- регулярный disaster-recovery drill;
- мониторинг capacity/free space;
- проверка backup freshness;
- controlled key rotation;
- cross-browser/mobile regression;
- load/soak evidence;
- актуальный release governance;
- поддержка совместимой upgrade path;
- отдельная будущая работа для horizontal multi-instance WebSocket, если она понадобится.
