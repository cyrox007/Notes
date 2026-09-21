# Проверенные release candidates обновления

Этот слой подготавливает подписанное обновление Workspace Organizer к последующему переключению live-системы **без изменения live application tree или базы данных**.

## Предварительные условия

Перед извлечением candidate должны одновременно выполняться все условия:

- manifest/signature/package обновления успешно прошли проверку `bin/update.php` и external staging;
- эта же транзакция владеет updater maintenance mode;
- `bin/update_backup.php` успешно завершён;
- transaction journal находится в состоянии `backup_verified`;
- `live_mutation_started` всё ещё равно `false`.

Если хотя бы одно условие не выполнено, `bin/update_candidate.php` завершается fail-closed.

## Конфигурация

Рекомендуемый production path:

```dotenv
UPDATE_RELEASE_PATH=/var/lib/notes/update-releases
```

Путь должен быть абсолютным, доступным PHP на запись и находиться вне live application/document-root tree. Если он не задан, updater использует `<PRIVATE_STORAGE_PATH>/update-releases`.

Не публикуйте этот каталог напрямую через web server.

## Команда

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001
```

Дополнительные overrides:

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001 \
  --state-root=/var/lib/notes/update-state \
  --candidate-root=/var/lib/notes/update-releases \
  --json
```

Команда повторно проверяет:

- ownership режима maintenance;
- состояние `backup_verified` в transaction journal;
- `live_mutation_started=false`;
- staged manifest/signature по текущему публичному trust registry обновлений;
- совместимость пакета, подписанные filename/size/SHA-256 и ZIP safety contract;
- stage metadata относительно transaction journal и подписанного пакета.

Только после этого архив извлекается во временный внешний каталог candidate.

## Безопасность распаковки

`Core\UpdateReleaseCandidate` не вызывает shell `unzip` и не зависит от `ZipArchive`. Он использует узкое подмножество ZIP, уже принятое `UpdateArchiveInspector`, и поддерживает только обычные файлы/каталоги с методами stored/deflated.

Во время распаковки повторно проверяются local/central ZIP metadata, данные читаются потоково, контролируется заявленный uncompressed size и для каждого файла проверяется CRC32. Извлечённый пакет должен содержать ровно один верхнеуровневый bundle-каталог.

Candidate обязан содержать как минимум:

- `index.php`
- `install.php`
- `core.php`
- `core/Version.php`
- `bin/migrate.php`
- `bin/healthcheck.php`
- `config/update_trusted_keys.php`

Candidate отклоняется, если содержит `.env`, `tools/vendor-license` или `tools/vendor-update`.

`core/Version.php` внутри candidate должен в точности соответствовать подписанным значениям `version` и `version_code` из manifest.

## Проверка дерева файлов

Каждый извлечённый regular file записывается с SHA-256 и размером в байтах в:

```text
.workspace-release-tree.json
```

Каталог candidate публикуется атомарным rename только после успешной проверки этого tree contract. Повторное извлечение того же пакета не перезаписывает candidate: существующее дерево повторно хешируется, и команда завершается ошибкой, если какой-либо файл был изменён.

## Текущая граница безопасности

Успешная команда возвращает `candidate_verified`, но при этом всё ещё:

- не перезаписывает файлы live application;
- не изменяет `.env`;
- не запускает migrations;
- не перезапускает PHP/WebSocket processes;
- не меняет active release;
- не снимает maintenance mode.

Следующий слой updater обязан повторно проверить candidate непосредственно перед любым live switch. Исторический результат `candidate_verified` сам по себе не является достаточным разрешением.
