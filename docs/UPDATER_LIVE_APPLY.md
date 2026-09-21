# Транзакционное live-применение и rollback

Этот документ описывает destructive-половину подписанного updater Workspace Organizer 1.0. Предполагается, что signed artifact уже прошёл verification, ZIP audit, immutable external staging, вход в maintenance, инициализацию transaction journal, проверенный code/MySQL rollback backup и извлечение проверенного внешнего release candidate.

Live apply layer **не** скачивает обновления и никогда не выполняет `unzip` поверх active application tree.

## Граница безопасности

До live mutation transaction должна существовать во внешнем journal, а эта же transaction должна владеть maintenance mode. `bin/update_apply.php --apply` сначала получает non-blocking operation lock, привязанный к transaction, под внешним updater state root. Lock удерживается на протяжении всей команды apply/recover, включая проверку maintenance, live mutation, rollback и снятие maintenance. Второй apply/recover process для той же transaction получает `operation_busy` и не входит параллельно в destructive path.

Затем apply path выполняет gates:

1. повторно проверяет manifest rollback backup, code snapshot и MySQL dump;
2. заново хеширует полное дерево release candidate;
3. подтверждает, что установленная версия всё ещё соответствует checkpoint в journal;
4. запускает текущий `bin/healthcheck.php --json`;
5. запускает candidate `bin/migrate.php --dry-run`, чтобы проверить migration checksums/schema policy на live database без применения migrations;
6. повторно проверяет candidate и backup непосредственно перед destructive boundary;
7. фиксирует, работал ли WebSocket;
8. переводит journal в `preflight_verified`, затем надёжно записывает `live_mutation_started=true`.

После записи `live_mutation_started` любой сбой process требует `--recover`; новый `--apply` отклоняется.

## Контролируемое переключение кода

Текущая installation layout не является release-symlink layout, поэтому замена всего application root одновременно заменила бы installation-specific state вроде `.env` и mutable storage. Вместо этого updater создаёт приватный sibling scratch directory на том же filesystem и готовит там release-owned top-level entries.

Переключение использует filesystem `rename()` для этих top-level release entries. Файлы не копируются по одному поверх работающего дерева.

Следующие installation/mutable roots сохраняются и не заменяются:

- `.env` и `.env.*`;
- `.git`;
- `vendor` — legacy/excluded; runtime 1.0 сам vendor-free;
- `cache`;
- `compile`;
- `uploads`;
- `notes-private-storage`;
- `.logs`.

Configured mutable paths — `PRIVATE_STORAGE_PATH`, upload locations, updater state/staging/backup/release roots, log path и WebSocket PID path — проверяются до apply. Если mutable path вложен в release-owned top-level directory, apply завершается до mutation, потому что такую layout нельзя безопасно переключить.

Scratch containers остаются private (`0700`), но directories, продвигаемые в live runtime, создаются как `0755`; file modes сохраняются из проверенного candidate/snapshot. Поэтому private `0700` backup directory не может случайно сделать восстановленное приложение недоступным для web/PHP service account.

## Последовательность применения

После destructive boundary:

```text
live_mutation_started
  -> code_switched
  -> migrations_applied
  -> postcheck_verified
  -> committed
```

Операционная последовательность:

```text
controlled code switch
-> live bin/migrate.php
-> live healthcheck
-> exact target Version.php verification
-> bin/migrate.php --status
-> WebSocket restart if it was running before the switch
-> committed
-> maintenance release
```

`committed` — terminal success state. Если удаление maintenance marker после commit не удалось, updater **не** откатывает исправный committed release. Он сообщает `maintenance_release_failed` и оставляет maintenance активным; оператор повторяет `--recover`, который заново проверяет committed version, health и migration status перед снятием maintenance.

## Автоматический rollback

Любая ошибка после `live_mutation_started`, но до `committed`, запускает rollback при активном maintenance:

```text
rollback_started
  -> code_restored
  -> database_restored
  -> rollback_verified
```

После destructive boundary проверенный pre-update backup является авторитетным recovery artifact. Release candidate **не требуется** для rollback и к этому моменту уже может быть удалён или повреждён. Rollback:

1. повторно проверяет внешний rollback backup, включая code manifest/files и MySQL dump metadata;
2. перечисляет текущие live release-owned top-level entries, переносит failed release tree в quarantine и восстанавливает code из проверенного pre-update snapshot, не затрагивая `.env` и preserved mutable roots;
3. восстанавливает MySQL из проверенного consistent snapshot, включая удаление objects, добавленных failed migration;
4. проверяет точный pre-update `Version.php`;
5. запускает восстановленный healthcheck;
6. запускает восстановленный `bin/migrate.php --status`;
7. перезапускает WebSocket, если он работал до apply;
8. записывает `rollback_verified`;
9. только после этого снимает maintenance.

Так как mutable paths под release-owned top-level directories отклоняются до apply, rollback может безопасно считать каждый non-preserved live top-level entry принадлежащим релизу. Это позволяет удалять target-only entries без обращения к candidate tree.

Если любой шаг rollback нельзя проверить, journal по возможности записывает `rollback_failed`, а maintenance остаётся активным. Recovery artifacts не удаляются.

## Восстановление после сбоя

Recovery не зависит от выживания исходного PHP process. Запустите:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover
```

Дополнительные `--state-root` и `--backup-root` переопределяют настроенные external updater locations.

Journal — durable source of truth. Recovery учитывает фазу:

- `backup_verified`, `candidate_verified`, `preflight_verified` при `live_mutation_started=false`: live mutation не происходила, maintenance можно снять;
- `live_mutation_started`, `code_switched`, `migrations_applied`, `postcheck_verified`: начать rollback от проверенного checkpoint;
- `rollback_started`: повторить/закончить code restore; операция намеренно идемпотентна, если предыдущий process умер до записи phase marker;
- `code_restored`: продолжить database restore вместо попытки начать state graph заново;
- `database_restored`: продолжить verification restored version/health/schema;
- `rollback_failed`: повторить rollback от проверенного checkpoint;
- `rollback_verified`: повторно проверить восстановленную installation и снять maintenance;
- `committed`: повторно проверить target installation и снять maintenance.

Если recovery тоже завершается ошибкой, не выключайте maintenance force-способом только ради открытия UI. Изучите внешний transaction journal и сохраните verified backup. Исходный candidate полезен для diagnostics, но не является rollback dependency после `live_mutation_started`.

## CLI

Применение candidate, уже привязанного к той же verified updater transaction:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --candidate-dir=/var/lib/notes/update-releases/<candidate> \
  --apply
```

Машиночитаемый вывод:

```bash
php bin/update_apply.php ... --apply --json
```

Recovery:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover --json
```

Команда намеренно требует, чтобы maintenance уже был активен и принадлежал той же transaction. Предыдущие staging/backup/candidate commands остаются отдельными checkpoints, чтобы оператор мог проверить artifacts до пересечения destructive boundary.

Одновременно transaction может принадлежать только одной live apply/recover команде. Если другой process уже удерживает transaction operation lock, команда завершается с `operation_busy` и не меняет updater state.

## Lifecycle WebSocket

До mutation updater фиксирует, запущен ли native WebSocket process. Если он был запущен, successful apply или rollback использует:

```bash
php ws_server/server.php restart -d
```

и после этого подтверждает `status`. Daemon restart требует Unix `pcntl`. Если WebSocket работает, но updater не может безопасно его перезапустить, apply завершается **до** `live_mutation_started`. Установки, управляемые внешним process supervisor, могут вместо этого stop/drain socket service через supervisor до apply; тогда updater фиксирует его как неработающий и не придумывает restart mechanism, который не способен проверить.

## Recovery artifacts и очистка

Critical path apply/rollback намеренно не удаляет:

- verified staged package;
- verified release candidate;
- verified rollback backup;
- transaction journal;
- sibling switch/rollback scratch, оставшийся после операции.

Cleanup/retention — отдельная post-commit maintenance задача. Rollback backup и transaction journal не должны исчезать только потому, что update достиг terminal state. Retention candidate желателен для diagnostics/reproducibility, но rollback correctness после destructive boundary от него не зависит.

## Обязательная проверка перед merge/release

Live-apply gate должен проходить на поддерживаемых версиях PHP и реальном MySQL. Он проверяет:

- повторное хеширование candidate;
- controlled release-owned code switch;
- сохранение `.env`, cache и uploads;
- отказ от mutable paths, вложенных в release-owned roots;
- контракт directory permissions;
- transaction-scoped single-owner locking apply/recover;
- verified code rollback при отсутствии исходного candidate;
- удаление/quarantine target-only top-level entries failed release;
- полный MySQL rollback, включая удаление таблицы failed migration;
- восстановление triggers/data;
- journal transitions и rollback retry contract;
- CLI invariants destructive boundary.

Поздний release drill всё равно обязан проверить полный signed upgrade Beta4 -> 1.0 и injected post-mutation failure на реальном установленном приложении до того, как release gate 1.0 считается завершённым.
