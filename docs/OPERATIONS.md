# Workspace Organizer — эксплуатация в production

Этот документ описывает эксплуатационный минимум после установки. Fresh install на обычном shared hosting остаётся web-only через `/install.php`; команды ниже нужны оператору для обновлений, резервного копирования и production-проверок.

## 1. Проверки перед релизом и после deployment

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

Realtime Messenger требует запущенный native PHP WebSocket process (`ws_server/server.php`) и TLS/WSS endpoint. Process может работать рядом с HTTP-приложением либо на одном отдельном WS-узле. После изменения `WS_TICKET_SECRET`, `WS_ALLOWED_ORIGINS`, `SITEURL` или WebSocket topology перезапускайте WS process и выполняйте browser/WSS smoke. Для remote WS topology обязательны одинаковый release/commit, общая application DB, одинаковые Messenger/ticket secrets, общий Messenger private storage и общий `UPDATE_STATE_PATH`; полный контракт описан в `docs/MESSENGER_SERVER.md`.

## 2. Контракт резервного копирования

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

## 3. Проверка восстановления

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

## 4. Ротация ключей шифрования

### `WS_TICKET_SECRET`

Его можно ротировать без перешифрования данных:

1. сгенерировать новый случайный secret (минимум 32 байта/достаточная энтропия);
2. заменить secret в secret manager / `.env`;
3. одновременно перезапустить HTTP workers и native WebSocket process;
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

## 4.1. Отдельный WebSocket-узел

Для одной installation допускается один отдельный realtime-узел. Он не является stateless proxy: native WS process загружает application runtime и обращается к общей MySQL БД, RBAC/module lifecycle, license/runtime policy и Messenger storage.

Операционный минимум remote WS deployment:

- HTTP и WS узлы работают на одном release/commit;
- `WS_TICKET_SECRET` и `MSG_SECRET_KEY` совпадают;
- `PRIVATE_STORAGE_PATH/messenger` доступен обоим узлам под тем же absolute path;
- `UPDATE_STATE_PATH` является общим, чтобы WS mutations видели updater maintenance;
- `WS_ALLOWED_ORIGINS` содержит origin HTTP-приложения;
- наружу публикуется WSS endpoint, native listener остаётся loopback/private;
- `php bin/ws_doctor.php` запускается на самом WS-узле;
- после deploy выполняется browser smoke text + attachment + reconnect.

Несколько активных WS instances для одной installation пока не поддерживаются: live connection registry локален процессу, а cross-node pub/sub/fan-out отсутствует. Не используйте второй WS process как HA/load-balancing решение до отдельной реализации multi-instance contract.

См. `docs/MESSENGER_SERVER.md`.

## 5. Ограничение частоты запросов и reverse proxy

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

## 6. Приватное хранилище

`PRIVATE_STORAGE_PATH` и `RATE_LIMIT_STORAGE_PATH` должны находиться вне application/document root. Web-server не должен раздавать их напрямую.

Права по умолчанию:

- private directories: `0700` либо эквивалент с выделенным service account;
- private files: `0600`;
- web/PHP process получает только необходимые права;
- backup process получает read-only доступ к пользовательским private directories, если архитектура хостинга это позволяет.

## 7. Регламентные задачи

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
- доступность HTTPS и WSS снаружи reverse proxy.

## 8. Release gate

Релиз считается готовым к production, когда одновременно выполнены:

- installer/upgrade CI зелёный;
- security/domain regression workflows зелёные;
- browser HTTPS/WSS smoke зелёный;
- `bin/healthcheck.php` проходит на target environment;
- существует свежий проверенный backup и зафиксирован restore drill;
- encryption keys и `.env` не входят в публичный release/backup archive.


## Наблюдаемость безопасности

Workspace Organizer записывает структурированные security/audit events в append-only JSONL вне дерева приложения. По умолчанию используется файл:

`PRIVATE_STORAGE_PATH/logs/security-events.jsonl`

Задавайте `SECURITY_EVENT_LOG_PATH` только когда требуется отдельный абсолютный внешний path. Каталог создаётся с mode 0700, а event file хранится с 0600 на POSIX-системах.

Текущие события линии 1.0 покрывают:

- успешную/неуспешную аутентификацию, blocked-account и logout;
- отказы authentication rate limit и сбои rate limiter;
- операции активации/очистки лицензии;
- lifecycle transitions модулей;
- успешные и неуспешные apply/recovery updater.

Чувствительные context keys — passwords, tokens, secrets, authorization/cookie/session/CSRF values — редактируются logger до serialization. License tokens и signing/private keys никогда не должны попадать в logs.

Операционная сводка:

```bash
php bin/observability.php
php bin/observability.php --window=900 --json
```

Команда завершается с code 3 при превышении alert thresholds. Значения по умолчанию:

- любое critical event в observation window;
- 10 authentication failures/blocked attempts;
- 3 отказа rate limit.

Пороговые значения настраиваются через `OBSERVABILITY_CRITICAL_ALERT`, `OBSERVABILITY_AUTH_FAILURE_ALERT`, `OBSERVABILITY_RATE_LIMIT_ALERT` и `OBSERVABILITY_WINDOW_SECONDS`.

Рекомендуемый production-вариант — cron/systemd timer, который каждые несколько минут запускает `php bin/observability.php --json` и отправляет non-zero/alert results в существующий канал мониторинга оператора. Релиз намеренно не требует конкретного внешнего monitoring vendor.

`php bin/healthcheck.php` также проверяет, что security event storage расположен вне live application tree и доступен на запись.


## Retention и безвозвратная очистка

Workspace Organizer 1.0 отделяет обычные пользовательские soft-delete/deactivation от необратимого физического purge.

Retention windows по умолчанию задаются через:

- `RETENTION_SOFT_DELETE_DAYS=30`;
- `RETENTION_DEACTIVATED_ACCOUNT_DAYS=30`.

Soft-deleted Notes, attachments заметок, entries File Manager, Messenger messages/attachments, Tasks, items общих досок и удалённые task categories сохраняются до соответствующего cutoff. Deactivated accounts хранятся независимо от content soft-delete.

По умолчанию выполняется preview, который никогда не изменяет данные:

```bash
php bin/retention.php
php bin/retention.php --soft-days=30 --account-days=30 --json
```

Permanent purge намеренно требует явного запуска и необратим:

```bash
php bin/retention.php --apply --yes --json
```

Не планируйте автоматический `--apply --yes`, пока для deployment нет актуального evidence backup/restore drill.

Правила безопасности:

1. Physical managed files удаляются до hard-delete соответствующих DB metadata. Если файл нельзя безопасно удалить, row остаётся для повторной попытки.
2. Paths вне managed private/legacy upload roots и symlink escapes блокируются.
3. Старые soft-deleted attachment rows, существовавшие до timestamp contract 1.0, начинают retention clock с момента migration и не очищаются сразу после upgrade.
4. Soft-deleted Note физически не удаляется, пока хотя бы одно attachment не завершило собственное retention window.
5. Deactivated administrative identities никогда не удаляются автоматически.
6. Deactivated account нельзя purge, пока он владеет Messenger group или shared/all-active Task board. Сначала ownership нужно передать либо явно вывести collaborative object из эксплуатации.
7. Пользовательская deactivation остаётся неразрушительной: существующий Admin action только отключает authentication и удаляет avatar. Permanent account deletion существует только в retention CLI.
8. Backup archives находятся вне live retention policy. Purge live data не переписывает и не удаляет ранее созданные backups; их retention управляется отдельной backup policy оператора.

Purge command создаёт structured security events, включая `retention.purge_completed`, `retention.account_blocked`, `retention.account_purged` и failure events. Просматривайте их через security observability pipeline.

Рекомендуемая production-процедура:

1. запустить preview и сохранить JSON result;
2. подтвердить недавний успешный backup/restore drill;
3. устранить blocked ownership;
4. запустить `--apply --yes --json`;
5. расследовать exit code 3 — он означает blocked/failed filesystem cleanup или account failures;
6. снова запустить preview; остаться должны только намеренно blocked/newly retained rows.

Cron/systemd timer может часто запускать preview. Если включён автоматический permanent purge, используйте отдельный проверенный timer с явным `--apply --yes`, сохраняйте JSON output и создавайте alert при любом non-zero exit status.


## Retention журнала действий пользователей

Авторизованные mutating HTTP actions и mutating WebSocket actions Messenger записываются в принадлежащую Core таблицу `user_action_log`. Журнал хранит только metadata: request bodies, содержимое Notes/Messenger, passwords, tokens, cookies, session/CSRF values и bytes файлов не сохраняются. Чувствительные detail keys редактируются до persistence.

Admins с `admin.audit.view` могут просматривать и фильтровать журнал в `/admin/audit`. Удаление пользователя не стирает историю: foreign key становится null, а snapshots actor UID/username сохраняются.

Retention по умолчанию — `AUDIT_LOG_RETENTION_DAYS=180`. Preview не изменяет данные:

```bash
php bin/audit_log.php --json
php bin/audit_log.php --days=180 --json
```

Permanent purge запускается явно и ограничен по объёму:

```bash
php bin/audit_log.php --apply --yes --days=180 --limit=1000 --json
```

Рассматривайте audit retention отдельно от user-content retention. Требования archive/export, если они есть, должны быть выполнены до purge.