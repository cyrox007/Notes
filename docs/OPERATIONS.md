# Workspace Organizer — production operations

Этот документ описывает эксплуатационный минимум после установки. Fresh install на обычном shared hosting остаётся web-only через `/install.php`; команды ниже нужны оператору для обновлений, резервного копирования и production-проверок.

## 1. Pre-release / post-deploy checks

Перед релизом и сразу после обновления:

```bash
php bin/migrate.php --dry-run
php bin/healthcheck.php
php bin/migrate_crypto.php --scope=all --dry-run --limit=10000
```

Для существующей установки применяйте миграции только после проверенного backup:

```bash
php bin/migrate.php
php bin/healthcheck.php
```

Messenger использует authenticated HTTP Long Poll как основной transport, а native WebSocket — как необязательный fast path. `WS_ENABLED=0` полностью отключает WS endpoint/ticket и оставляет весь функционал Messenger на Long Poll; `WS_ENABLED=1` включает ускоритель. Если WebSocket включён, держите `php ws_server/server.php start` под process manager и публикуйте `/ws` только через TLS reverse proxy. После изменения `WS_TICKET_SECRET`, `WS_ALLOWED_ORIGINS`, `SITEURL` или WebSocket topology перезапускайте HTTP workers + native WS process и выполняйте browser smoke WebSocket → Long Poll → WebSocket.

## 2. Backup contract

Backup считается полным только если содержит:

- согласованный dump MySQL со schema, triggers и данными;
- приватные пользовательские каталоги `notes/`, `messenger/`, `file_manager/`, `users/` из `PRIVATE_STORAGE_PATH`;
- manifest/checksums, созданные оператором или backup-системой;
- отдельную запись версии приложения/commit, к которой относится backup.

**Никогда не включайте `.env` или активные encryption keys в тот же backup archive.** Секреты должны резервироваться отдельно в secret manager / защищённом vault с независимым доступом.

Рекомендуемый MySQL dump выполняйте с credentials из защищённого client option file, а не из аргументов shell:

```bash
mysqldump \
  --defaults-extra-file=/secure/mysql-client.cnf \
  --single-transaction \
  --quick \
  --triggers \
  --routines \
  --hex-blob \
  DB_NAME > /secure/backups/workspace-db.sql
```

Приватные bytes копируйте из `PRIVATE_STORAGE_PATH` в backup destination вне document root. Не включайте `rate-limit/`, `logs/` и временные файлы: они не являются пользовательскими данными.

После создания backup обязательно сохраните SHA-256 для dump/archive и отправьте копию вне основного сервера.

## 3. Restore drill

Restore считается проверенным только после восстановления в **отдельную временную БД и отдельный private-storage path**. Не проверяйте backup первым восстановлением поверх production.

Последовательность:

1. Создать пустую disposable DB.
2. Импортировать dump MySQL.
3. Развернуть сохранённые `notes/`, `messenger/`, `file_manager/`, `users/` в отдельный private path.
4. Поднять копию приложения с отдельным `.env`, но с теми же data-encryption keys, которые соответствуют backup.
5. Выполнить `php bin/healthcheck.php`.
6. Проверить вход, одну зашифрованную заметку, одно Messenger message, одно private attachment и File Manager download.
7. Удалить disposable environment после фиксации результата drill.

Минимальная частота: перед крупным релизом и регулярно по внутреннему RPO/RTO. Наличие backup без успешного restore drill не считается доказанной стратегией восстановления.

## 4. Encryption key rotation

### `WS_TICKET_SECRET`

Его можно ротировать без перешифрования данных:

1. сгенерировать новый случайный secret (минимум 32 байта/достаточная энтропия);
2. заменить secret в secret manager / `.env`;
3. одновременно перезапустить HTTP workers и native WebSocket process, если WebSocket fast path включён;
4. проверить новый login, HTTP fallback и новый WSS ticket/connection.

Старые короткоживущие socket tickets после ротации перестанут проходить проверку — это ожидаемо.

### `UNIQUE_KEY` и `MSG_SECRET_KEY`

**Не меняйте эти значения напрямую.** Штатная смена master keys выполняется только через maintenance-команду `bin/rotate_data_keys.php`.

Перед ротацией:

1. сделать полный backup и иметь актуальный restore drill;
2. выполнить `php bin/migrate_crypto.php --scope=all --dry-run --limit=10000`; Messenger должен быть в v2, а encrypted Notes — в текущем формате;
3. подготовить old/new secrets в отдельных файлах вне application tree с правами `0600`; raw key values намеренно не принимаются аргументами CLI;
4. включить maintenance с уникальным transaction id. HTTP и WebSocket mutation-paths блокируются на всё время операции.

Пример:

```bash
php bin/maintenance.php \
  --action=enter \
  --transaction=keyrotate-2026-09 \
  --reason='Data encryption key rotation'

php bin/rotate_data_keys.php \
  --transaction=keyrotate-2026-09 \
  --scope=all \
  --old-unique-key-file=/secure/old-unique.key \
  --new-unique-key-file=/secure/new-unique.key \
  --old-msg-key-file=/secure/old-msg.key \
  --new-msg-key-file=/secure/new-msg.key
```

Rotator использует небольшие DB-транзакции и внешний checkpoint. Если процесс завершится после DB commit, но до записи checkpoint, повторный запуск безопасен: строка сначала аутентифицируется target key и не шифруется повторно. State содержит только SHA-256 fingerprints, checkpoints и counters — не сами ключи.

Notes rotation охватывает `notes.content` и encrypted snapshots `note_history.old_content/new_content`; исторический plaintext в note history остаётся byte-identical. Messenger rotation охватывает `messages.message`. Notes/Messenger attachments этими master keys сейчас не шифруются и в эту процедуру не входят.

`--max-batches=N` позволяет контролируемо остановить операцию и затем продолжить той же командой. До `complete + verified` maintenance не снимается.

Для отмены **до переключения .env/secret manager** запустите ту же команду с `--rollback`. Она переводит уже обновлённые строки обратно на old keys и пропускает строки, которые ещё не были переведены.

После успешного forward:

1. оставить maintenance активным;
2. заменить `UNIQUE_KEY` / `MSG_SECRET_KEY` в secret manager или `.env`;
3. перезапустить HTTP workers и WebSocket process;
4. выполнить `php bin/healthcheck.php` и smoke-проверить encrypted Note и Messenger message;
5. снять maintenance той же transaction id;
6. удалить old secrets только после принятого backup/rollback окна.

`DATA_KEY_ROTATION_STATE_PATH` может задавать отдельный внешний state-каталог. Если он пуст, используется `UPDATE_STATE_PATH/data-key-rotation`, затем `PRIVATE_STORAGE_PATH/key-rotation`.

`bin/migrate_crypto.php` остаётся legacy-format migrator; `bin/rotate_data_keys.php` — штатный путь смены master keys.

## 4.1. Отдельный WebSocket-узел

Для одной installation допускается один отдельный realtime-узел. Он не является stateless proxy: native WS process загружает application runtime и обращается к общей MySQL БД, RBAC/module lifecycle, license/runtime policy и Messenger storage.

Операционный минимум remote WS deployment:

- HTTP и WS узлы работают на одном release/commit;
- `WS_TICKET_SECRET` и `MSG_SECRET_KEY` совпадают;
- `PRIVATE_STORAGE_PATH/messenger` доступен обоим узлам с теми же данными;
- `UPDATE_STATE_PATH` является общим, чтобы WS mutations видели updater maintenance;
- `WS_ALLOWED_ORIGINS` содержит origin HTTP-приложения;
- наружу публикуется WSS endpoint, native listener остаётся loopback/private;
- `WS_PID_FILE` задаётся локальным для WS-машины;
- `php ws_server/server.php check` выполняется до запуска, `php bin/ws_doctor.php` — после запуска;
- shared application DB доступна обоим узлам: через неё realtime revision bridge сообщает активным WS-клиентам о durable mutations из HTTP fallback;
- после deploy выполняется browser smoke text + attachment + fallback + reconnect.

Несколько активных WS instances для одной installation пока не поддерживаются: live connection registry локален процессу, а полноценный multi-node pub/sub/presence отсутствует. Не используйте второй WS process как HA/load-balancing решение до отдельной реализации multi-instance contract.

См. `docs/MESSENGER_SERVER.md`.

## 5. Rate limiting и reverse proxy

На одном узле limiter по умолчанию использует `PRIVATE_STORAGE_PATH/rate-limit` и `flock`.

Для нескольких web-узлов:

```env
DEPLOYMENT_NODE_COUNT=2
RATE_LIMIT_STORAGE_PATH=/mnt/workspace-shared/rate-limit-state
TRUSTED_PROXY_IPS=10.0.0.10,10.0.0.11
```

`RATE_LIMIT_STORAGE_PATH` должен быть одним общим POSIX volume, доступным всем узлам, с корректной поддержкой advisory file locking. `bin/healthcheck.php` при `DEPLOYMENT_NODE_COUNT>1` требует явный shared path.

`X-Real-IP` и `X-Forwarded-For` используются для limiter subject только если непосредственный `REMOTE_ADDR` входит в `TRUSTED_PROXY_IPS`. Не добавляйте туда клиентские сети или `0.0.0.0/0`.

Multi-node deployment дополнительно требует sticky PHP sessions или общий session backend. Это инфраструктурный контракт: приложение не объявляет локальные PHP sessions распределёнными автоматически.

## 6. Private storage

`PRIVATE_STORAGE_PATH` и `RATE_LIMIT_STORAGE_PATH` должны находиться вне application/document root. Web-server не должен раздавать их напрямую.

Права по умолчанию:

- private directories: `0700` либо эквивалент с выделенным service account;
- private files: `0600`;
- web/PHP process получает только необходимые права;
- backup process получает read-only доступ к пользовательским private directories, если архитектура хостинга это позволяет.

## 7. Scheduled maintenance

Messenger orphan cleanup запускайте cron/systemd timer каждые 15–60 минут:

```bash
php bin/cleanup_messenger_orphans.php
```

Регулярно контролируйте:

- свободное место private storage и DB;
- PHP/native WebSocket error logs;
- результат `bin/healthcheck.php`;
- срок последнего успешного backup и restore drill;
- наличие legacy crypto rows через `migrate_crypto.php --dry-run`;
- доступность HTTPS; при включённом WebSocket — WSS снаружи reverse proxy; периодически проверяйте и HTTP long-poll fallback.

## 8. Release gate

Релиз считается готовым к production, когда одновременно выполнены:

- installer/upgrade CI зелёный;
- security/domain regression workflows зелёные;
- browser HTTPS/WSS + automatic long-poll fallback/recovery smoke зелёный;
- `bin/healthcheck.php` проходит на target environment;
- существует свежий проверенный backup и зафиксирован restore drill;
- encryption keys и `.env` не входят в публичный release/backup archive.


## Security observability

Workspace Organizer writes structured security/audit events as append-only JSONL outside the application tree. By default the file is:

`PRIVATE_STORAGE_PATH/logs/security-events.jsonl`

Set `SECURITY_EVENT_LOG_PATH` only when a dedicated absolute external path is required. The directory is created with mode 0700 and the event file is kept at 0600 on POSIX systems.

Current 1.0 events cover:

- authentication success/failure/blocked-account/logout;
- authentication rate-limit denials and rate-limiter failures;
- license activation/clear operations;
- module lifecycle transitions;
- updater apply/recovery success and failure.

Sensitive context keys such as passwords, tokens, secrets, authorization/cookie/session/CSRF values are redacted by the logger before serialization. License tokens and signing/private keys must never be logged.

Operational summary:

```bash
php bin/observability.php
php bin/observability.php --window=900 --json
```

The command exits with code 3 when alert thresholds are crossed. Defaults:

- any critical event in the observation window;
- 10 authentication failures/blocked attempts;
- 3 rate-limit denials.

Tune with `OBSERVABILITY_CRITICAL_ALERT`, `OBSERVABILITY_AUTH_FAILURE_ALERT`, `OBSERVABILITY_RATE_LIMIT_ALERT` and `OBSERVABILITY_WINDOW_SECONDS`.

Recommended production scheduling is a cron/systemd timer that runs `php bin/observability.php --json` every few minutes and forwards non-zero/alert results to the operator's existing monitoring channel. This release intentionally does not require a specific external monitoring vendor.

`php bin/healthcheck.php` also verifies that security event storage resolves outside the live application tree and is writable.


## Хранение и окончательная очистка

Workspace Organizer разделяет обычное пользовательское удаление, деактивацию аккаунта и окончательную очистку.

Периоды по умолчанию:

- `RETENTION_SOFT_DELETE_DAYS=30` — хранение объектов, помеченных как удалённые;
- `RETENTION_ACCOUNT_DELETE_DAYS=30` — срок от явной команды администратора «Удалить пользователя» до обезличивания его регистрационных данных;
- `RETENTION_DEACTIVATED_ACCOUNT_DAYS=30` сохранён как параметр совместимости старого CLI и больше не превращает простую деактивацию в автоматическое удаление.

Деактивация пользователя **не запускает удаление по времени**. Пользователь остаётся в базе, вход запрещён, место лицензии освобождается, а его данные и связи сохраняются. Для запуска окончательной очистки администратор должен отдельно выбрать «Удалить»; после этого фиксируются `deletion_requested_at` и `purge_after`.

По истечении срока регистрационные данные обезличиваются. Физический `DELETE FROM users` не выполняется: остаётся техническая tombstone-запись, необходимая для целостности общих сообщений, задач и других объектов. Удаляются логин/email/телефон/ФИО/аватар/TOTP/роли и создаётся непригодный для входа случайный пароль.

Предварительный просмотр ничего не меняет:

```bash
php bin/retention.php
php bin/retention.php --soft-days=30 --json
```

Фактическая очистка требует двух явных флагов:

```bash
php bin/retention.php --apply --yes --json
```

Для обычной эксплуатации эту команду можно запускать cron/systemd timer один раз в сутки. На хостинге без cron суперадминистратор может выполнить обработку просроченных заявок кнопкой **«Очистить просроченные сейчас»** в Admin. Перед включением периодического запуска должна быть подтверждена проверка восстановления из резервной копии.

Правила безопасности:

1. Физически удаляемые soft-delete файлы удаляются раньше соответствующих строк БД. Если файл нельзя удалить безопасно, запись остаётся для повторной попытки.
2. Пути вне управляемого private storage/legacy uploads и symlink-выходы блокируются.
3. Старые soft-delete записи, появившиеся до timestamp-контракта 1.0, начинают срок хранения с момента compatibility migration и не очищаются сразу после обновления.
4. Заметка не удаляется физически, пока хотя бы одно её вложение не прошло собственный срок хранения.
5. Обычная деактивация никогда сама не становится заявкой на удаление.
6. Аккаунт не обезличивается, пока за ним закреплена административная роль, владение группой Messenger или общей Task-доской. Владение нужно передать либо объект удалить.
7. При окончательной очистке совместные сообщения, задачи, заметки и прикладные связи не удаляются каскадно вместе с пользователем.
8. Резервные копии не входят в live retention: очистка рабочей БД не переписывает ранее созданные backup-архивы.

Сервис пишет события `retention.purge_completed`, `retention.account_blocked`, `retention.account_purged` и события ошибок в общий журнал безопасности.

Рекомендуемый порядок:

1. выполнить preview и сохранить JSON-результат;
2. убедиться, что есть свежая успешная проверка восстановления из резервной копии;
3. устранить блокирующее владение общими объектами;
4. выполнить `--apply --yes --json` вручную либо включить ежедневный cron;
5. расследовать код возврата 3: он означает заблокированную/неудачную файловую очистку или ошибку обработки аккаунта.

## User action audit retention

Authenticated mutating HTTP actions and mutating Messenger WebSocket actions are recorded in the core-owned `user_action_log` table. The journal is metadata-only: request bodies, Notes/Messenger content, passwords, tokens, cookies, session/CSRF values and file bytes are not stored. Sensitive detail keys are redacted before persistence.

Admins granted `admin.audit.view` can inspect and filter the journal at `/admin/audit`. Deleted users do not erase history: the foreign key is nulled while actor UID/username snapshots remain.

Default retention is `AUDIT_LOG_RETENTION_DAYS=180`. Preview does not modify data:

```bash
php bin/audit_log.php --json
php bin/audit_log.php --days=180 --json
```

Permanent purge is explicit and bounded:

```bash
php bin/audit_log.php --apply --yes --days=180 --limit=1000 --json
```

Treat audit retention independently from user-content retention. Archive/export requirements, if any, must be satisfied before purge.


## 9. Сервисная диагностика и удалённый пакет поддержки

Для эксплуатационных сбоев используется отдельный сервисный журнал:

`PRIVATE_STORAGE_PATH/logs/service-events.jsonl`

При необходимости путь можно переопределить через `SERVICE_LOG_PATH`. Журнал хранится вне дерева приложения, ограничен по размеру и ротируется в один предыдущий файл. В него записываются технические этапы updater, автоматического recovery, rollback, ошибки БД, bootstrap и фатальные ошибки PHP. Пользовательский контент в сервисный журнал не записывается.

Контекст событий очищается до записи. Пароли, токены, лицензия, cookies, сессии, CSRF, ключи и другие секретные поля заменяются на `[redacted]`. Пути дерева приложения/private storage заменяются логическими обозначениями. SQL и параметры пользовательских запросов в сервисные события ошибок БД не записываются.

Суперадминистратор может открыть **Admin → Обновления → Диагностика поддержки** и нажать **«Создать ZIP для поддержки»**. Система в этот момент формирует неизменяемый диагностический ZIP и выдаёт одноразовый доступ.

Вместо полного `phpinfo()` архив содержит безопасный профиль хостинга: версию/SAPI PHP, лимиты выполнения и загрузки, наличие `open_basedir`, `disable_functions`, process API, OPcache, обязательные расширения, реальные file/flock/rename-пробы во внешнем private storage, версию MySQL/MariaDB и карту объявленных привилегий текущего DB-пользователя, включая `CREATE ROUTINE` и `ALTER ROUTINE`. Физические пути, DB user/host и исходный `SHOW GRANTS` не включаются.

ZIP имеет схему `2` и содержит `manifest.json`, `hosting-profile.json`, `health.json`, `maintenance.json`, `service-events.json`, `updater-transactions.json`, `security-summary.json`, `privacy.json` и `README.txt`. ZIP создаётся без обязательной зависимости от PHP-расширения `zip`.

Пакет намеренно **не содержит** `.env`, дамп БД, пользовательские заметки/сообщения/файлы, пароли, токены лицензии, ключи шифрования или приватные ключи подписи.

Одноразовый доступ действует 15 минут, имеет жёсткий максимум 30 минут и автоматически прекращает работу после первого скачивания. На диске хранится только SHA-256 токена, а не сам токен. После успешной выдачи grant и сформированный ZIP удаляются.

Endpoint выдачи обрабатывается после загрузки `.env`, но до проверки схемы БД, maintenance/recovery barrier и запуска модулей. Поэтому уже созданный пакет можно получить даже если основное приложение после этого перешло в 503 из-за обновления или несовместимой схемы.

Для человека доступна одноразовая ссылка с query-token. Для будущей интеграции с jsint предпочтителен HTTPS GET на `/support-diagnostics` с `Authorization: Bearer <token>`. Ответ имеет `Content-Type: application/zip`, заголовки `X-Notes-Diagnostics-Schema`, `X-Notes-Diagnostics-Bundle-Id`, `X-Notes-Diagnostics-SHA256`; получатель должен проверить SHA-256 ответа и затем контрольные суммы файлов из `manifest.json`.

Постоянного удалённого доступа к журналам нет. Для каждого обращения поддержки пользователь должен явно создать новый пакет и самостоятельно разрешить его получение.

Подробный контракт: `docs/SUPPORT_DIAGNOSTICS.md`.


## Защита от поисковых роботов и внешних сканеров

Workspace Organizer является закрытым рабочим приложением и не предназначен для поисковой индексации.

Проверки после развёртывания:

```bash
curl -I https://workspace.example/
curl https://workspace.example/robots.txt
```

Для динамического ответа ожидается `X-Robots-Tag: noindex, nofollow, noarchive, nosnippet, noimageindex`. В `robots.txt` должен присутствовать `Disallow: /`.

Ранний `CrawlerDefense` перехватывает известные scanner-probes до обычного Router. События `crawler.probe_detected`, `crawler.trap_hit` и `crawler.rate_limited` пишутся в общий security event log. IP-адрес в событии не сохраняется напрямую: используется короткий HMAC/хэш-идентификатор клиента.

Защита намеренно не использует долгие задержки ответа: tarpit на уровне PHP мог бы позволить внешнему клиенту занять все workers. Повторяющиеся scanner-probes ограничиваются коротким `429` с `Retry-After`.

Crawler-trap не является заменой сетевому firewall/WAF. На публичном сервере дополнительно рекомендуется ограничивать административный доступ на уровне reverse proxy/VPN, если инфраструктура это позволяет.
