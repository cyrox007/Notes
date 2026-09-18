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

Realtime Messenger требует запущенный Workerman и TLS reverse proxy для `/ws`. После изменения `WS_TICKET_SECRET`, `WS_ALLOWED_ORIGINS`, `SITEURL` или WebSocket topology перезапускайте WS process и выполняйте browser/WSS smoke.

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
3. одновременно перезапустить HTTP workers и Workerman;
4. проверить новый login + WSS connection.

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
- PHP/Workerman error logs;
- результат `bin/healthcheck.php`;
- срок последнего успешного backup и restore drill;
- наличие legacy crypto rows через `migrate_crypto.php --dry-run`;
- доступность HTTPS и WSS снаружи reverse proxy.

## 8. Release gate

Релиз считается готовым к production, когда одновременно выполнены:

- installer/upgrade CI зелёный;
- security/domain regression workflows зелёные;
- browser HTTPS/WSS smoke зелёный;
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
