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

**Не меняйте эти значения напрямую.** Текущие note/message ciphertext привязаны к действующим ключам; простая замена переменной сделает существующие данные нечитаемыми.

До ротации data keys требуется отдельная maintenance-процедура re-encryption с old+new key одновременно, backup и verify. `bin/migrate_crypto.php` предназначен для legacy-format migration и проверки текущих ciphertext, но не является инструментом смены master key.

Поэтому production contract такой: data-encryption keys считаются долгоживущими secrets; их аварийная ротация выполняется только через отдельную re-encryption maintenance операцию, а не редактированием `.env`.

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
