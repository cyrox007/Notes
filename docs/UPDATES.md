# Подписанные обновления

Workspace Organizer 1.0 использует криптографически подписанный pipeline обновлений. Подпись обновлений намеренно изолирована от лицензирования установки: **license keys не могут разрешать обновление кода, а update keys не могут выпускать лицензии**.

## Модель безопасности

Обновление состоит из трёх artifacts:

1. hosting ZIP package;
2. JSON-файл update manifest;
3. detached Ed25519 signature token для точных bytes manifest.

Формат signature token:

```text
wou1.<key-id>.<base64url-ed25519-signature>
```

Подпись покрывает точные bytes:

```text
WorkspaceOrganizerUpdateManifest/v1\n<manifest bytes>
```

Такое разделение доменов сделано намеренно. Никогда не используйте production license-signing key как production update-signing key.

Runtime-установки содержат только **публичные** update keys в:

```text
config/update_trusted_keys.php
```

Registry намеренно остаётся пустым до выполнения production update-key ceremony. При пустом registry `bin/update.php` и `bin/update_remote.php` завершаются fail-closed, а подписанные обновления остаются отключёнными.

## Контракт manifest

Подписанный manifest содержит как минимум:

```json
{
  "schema": 1,
  "product": "workspace-organizer",
  "version": "1.0.0",
  "version_code": 10000,
  "channel": "stable",
  "issued_at": 1780000000,
  "source_commit": "0123456789abcdef0123456789abcdef01234567",
  "min_source_version_code": 1404,
  "requires_php": "8.1.0",
  "package": {
    "filename": "workspace-organizer-v1.0.0.zip",
    "sha256": "<64 lowercase hex chars>",
    "size": 1234567,
    "format": "zip"
  }
}
```

Подпись защищает version, compatibility floor, source commit, hash/size/name пакета. Action paths отклоняют same-version/downgrade packages; read-only remote checks могут сообщать эти подписанные состояния без загрузки bytes пакета.

## Production update-key ceremony

Выполняйте эту процедуру только на контролируемой/offline машине поставщика.

1. Создайте вне репозитория директорию для signing secrets.
2. Создайте отдельную пару update keys:

```bash
php tools/vendor-update/keygen.php \
  --key-id=update-prod-2026-01 \
  --private-out=/secure/offline/workspace-update-prod-2026-01.update-secret
```

3. Сохраните private key в secret storage поставщика. Он никогда не должен попадать в GitHub source, Actions secrets/artifacts, customer server, support archive, `.env`, database settings или release ZIP.
4. Добавьте **только** напечатанную public key entry в `config/update_trusted_keys.php` через проверенный PR.
5. До поставки этого trust root выполните полный release CI.

Файл private key использует отдельный update-key format, а tooling запрещает создавать или читать его внутри дерева репозитория. В Unix он не должен быть доступен group/other.

## Сборка и подпись release metadata

GitHub workflow `Build hosting package` только собирает ZIP релиз-кандидата и сохраняет его SHA-256 как Actions artifact. Он намеренно не создаёт и не обновляет публичный GitHub Release. Публикация выполняется только после того, как exact ZIP использован для сборки и offline-подписи production update manifest.

После появления финального hosting ZIP проверьте его зафиксированный SHA-256 и соберите manifest из exact файла:

```bash
php tools/vendor-update/build-manifest.php \
  --package=/release/workspace-organizer-v1.0.0.zip \
  --version=1.0.0 \
  --version-code=10000 \
  --channel=stable \
  --source-commit=<full-40-char-release-commit> \
  --min-source-version-code=1404 \
  --requires-php=8.1.0 \
  --out=/release/workspace-organizer-v1.0.0.update.json
```

Затем подпишите **точные bytes manifest**:

```bash
php tools/vendor-update/sign-manifest.php \
  --private-key=/secure/offline/workspace-update-prod-2026-01.update-secret \
  --key-id=update-prod-2026-01 \
  --manifest=/release/workspace-organizer-v1.0.0.update.json \
  --signature-out=/release/workspace-organizer-v1.0.0.update.sig
```

Изменение даже одного byte manifest после подписи делает signature недействительной.

## Проверка на стороне клиента и preflight архива

Только verification:

```bash
php bin/update.php \
  --manifest=/path/workspace-organizer-v1.0.0.update.json \
  --signature=/path/workspace-organizer-v1.0.0.update.sig \
  --package=/path/workspace-organizer-v1.0.0.zip \
  --verify-only
```

Команда проверяет:

- доверенный update key ID;
- Ed25519 signature точных bytes manifest;
- поля schema/product manifest;
- корректность времени выпуска;
- target новее установленного `VERSION_CODE`;
- установленная версия удовлетворяет `min_source_version_code`;
- локальный PHP удовлетворяет `requires_php`;
- filename, byte size и SHA-256 пакета;
- согласованность central-directory и local-header ZIP;
- безопасные относительные UTF-8 entry paths;
- отсутствие path traversal, absolute/backslash/colon paths и NUL;
- отсутствие symlink/special Unix entries;
- отсутствие encrypted, multi-disk, ZIP64 или data-descriptor entries в поддерживаемом subset обновлений;
- только поддерживаемые compression methods;
- отсутствие duplicate/case-colliding paths;
- ограничения безопасности per-file/total uncompressed-size и compression-ratio;
- отсутствие пересечения local entry payload regions.

Структурный аудит реализован на чистом PHP и **не** извлекает архив. Он принимает только явно заданные local regular files, но не URL или symlink.

## Внешний verified staging

Для staging проверенного обновления:

```bash
php bin/update.php \
  --manifest=/path/update.json \
  --signature=/path/update.sig \
  --package=/path/package.zip \
  --stage-root=/absolute/path/outside/application
```

Если `--stage-root` не передан, используется `UPDATE_STAGING_PATH`; иначе updater откатывается к `<PRIVATE_STORAGE_PATH>/updates`.

Staging root должен разрешаться за пределами live application tree. Updater:

1. проверяет signature, compatibility, package SHA-256 и структуру ZIP;
2. блокирует staging root от concurrent staging;
3. копирует файлы в случайную временную stage directory;
4. повторно проверяет SHA-256 после копирования;
5. сохраняет exact manifest/signature вместе со stage metadata;
6. атомарно переименовывает временную directory в финальное immutable stage name.

Повторная обработка того же signed artifact идемпотентна и заново проверяет существующие staged files.

## Удалённая доставка подписанного обновления

Remote delivery — это network-ingress слой перед тем же immutable staging contract. Он **не** включает maintenance, не создаёт updater transaction, не извлекает package и не изменяет live files.

Рекомендуемая конфигурация:

```dotenv
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
```

Проверка signed feed без загрузки ZIP:

```bash
php bin/update_remote.php --check-only --json
```

Успешная read-only проверка классифицирует signed feed как `update_available`, `up_to_date`, `ahead_of_feed` или `update_incompatible`. Во всех четырёх состояниях остаются `package_downloaded=false` и `live_files_changed=false`.

Загрузка, verification, audit и staging устанавливаемого обновления:

```bash
php bin/update_remote.php --json
```

Сам feed — discovery metadata, а не trust root. Он указывает только manifest/signature leaf filenames из той же директории. Exact bytes manifest обязаны пройти Ed25519 verification; только после этого verified manifest определяет filename, byte size и SHA-256 пакета. Неподписанный package pointer в feed не имеет полномочий.

Vendor-free HTTPS transport требует `openssl` и работает fail-closed для plain HTTP, literal/private/reserved network targets, портов кроме 443, redirects, transfer-encoded responses, non-identity content encoding, неоднозначного/отсутствующего `Content-Length` и ошибок TLS peer/certificate verification. Сначала разрешается DNS, принимается только public address, затем проверенный address фиксируется для TLS socket, при этом certificate verification продолжает использовать настроенный DNS host.

Package скачивается потоково во внешнюю приватную temporary directory и дополнительно к signed size contract ограничен 512 MiB. Transport требует, чтобы HTTP `Content-Length` совпадал с подписанным size, и вычисляет SHA-256 во время загрузки. Затем повторно выполняются существующий local package verifier и ZIP inspector, после чего применяется обычный immutable `UpdatePackageStager`; временные network-ingress bytes удаляются.

Package никогда не скачивается при `--check-only`, если более новое signed update несовместимо, либо action compatibility gate отклоняет same-version/downgrade/source-floor/runtime условия.

Подробные правила network, publishing и failure boundaries находятся в `docs/UPDATE_REMOTE_DELIVERY.md`.

## Автоматическая проверка и обновление через интерфейс администратора

Интерфейс администратора использует тот же подписанный транзакционный обновлятор и не создаёт параллельную реализацию замены файлов.

Модель доступа:

- фоновая и ручная проверка signed feed требуют `admin.settings.manage`;
- фоновая проверка выполняется после входа и повторяется каждые пять минут, не скачивая ZIP;
- при появлении совместимого релиза в общей шапке появляется системное уведомление;
- установка из уведомления — один POST + CSRF и дополнительно требует роль `superadmin`;
- кнопка «Обновить до …» внутри одного запроса повторно получает подписанный feed, фиксирует `version_code` и SHA-256 пакета и запускает существующий `bin/update_run.php`;
- если feed изменился между проверкой и фактическим запуском, updater завершает операцию до изменения рабочих файлов;
- ручные check и staging остаются диагностическими инструментами, но не являются обязательными шагами обычного пользовательского обновления;
- feed URL и channel задаются только серверной конфигурацией и не принимаются из браузерного ввода.

Обычный пользовательский путь после переходной версии:

```text
сервер публикует подписанный stable-релиз
  -> активная установка обнаруживает его автоматически
  -> в интерфейсе появляется уведомление
  -> суперадминистратор нажимает «Обновить до …»
  -> signed check + exact release binding
  -> staging + backup + candidate
  -> maintenance + live apply + migrations
  -> post-update healthcheck
  -> committed + снятие maintenance
```

От пользователя не требуются PowerShell, SSH, ручной PHP CLI, копирование bootstrap-файлов, редактирование `.env`, ручной запуск миграций или распаковка ZIP поверх рабочей установки. Аварийный CLI recovery сохраняется только как операторский путь на случай аппаратного сбоя или принудительного завершения процесса.

## Диагностика готовности updater

Перед проверкой или применением обновления запустите read-only readiness doctor:

```bash
php bin/update_doctor.php --json
```

Он не выполняет network request и не изменяет состояние. Проверяются local trust registry, обязательные PHP extensions, `proc_open`, настройки HTTPS feed/channel, формат online credentials при их включении, внешние staging/state/backup/release paths и DB configuration, необходимая для rollback backup.

Страница Admin Updates показывает то же local operator-readiness summary, не раскрывая private credential contents и absolute staged-package paths.

## Первый переход с 1.0.1 на 1.0.2

Опубликованная версия `1.0.1` уже содержит runtime подписанной transaction/apply/bootstrap цепочки, но была выпущена до convenience wrapper `bin/update_run.php`, readiness doctor и retention command из `1.0.2`. **Не** копируйте отдельные новые PHP-файлы updater в live tree 1.0.1.

Поддерживаемый первый переход использует существующую границу **trusted external bootstrap**:

1. Получите официальный hosting ZIP 1.0.2, `update.json`, `update.sig` и опубликованный SHA-256 через release channel.
2. Проверьте checksum release ZIP до использования как источника bootstrap runner.
3. Извлеките trusted bundle 1.0.2 во временную directory, **отдельную от live application tree 1.0.1**. Bundle содержит только public update trust root; private signing key никогда в него не входит.
4. Храните signed ZIP/manifest/signature 1.0.2 как локальные files и запустите bootstrap из временного дерева 1.0.2 против exact live source:

```bash
php /secure/workspace-1.0.2-runner/bin/update_bootstrap.php \
  --app-root=/srv/workspace \
  --manifest=/secure/release/update.json \
  --signature=/secure/release/update.sig \
  --package=/secure/release/workspace-organizer-v1.0.2.zip \
  --transaction=update-1-0-1-to-1-0-2 \
  --expected-source-version=1.0.1 \
  --expected-source-version-code=10001 \
  --stage-root=/var/lib/notes/update-staging \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --candidate-root=/var/lib/notes/update-releases \
  --json
```

Bootstrap проверяет exact installed source version и signed manifest/package, создаёт rollback artifacts, строит verified external candidate, транзакционно переключает код, выполняет migrations/health checks и автоматически откатывает код + базу данных при failed post-switch verification. Runner directory никогда не должна пересекаться с live application tree.

После успешного перехода на 1.0.2 последующие обновления используют установленный operator wrapper:

```bash
php bin/update_run.php --yes --json
```

Release CI содержит exact drill `v1.0.1` → synthetic signed `1.0.2` success/rollback, закреплённый на опубликованном commit 1.0.1. Production release acceptance всё равно повторяет переход с финальными production-signed artifacts 1.0.2.

## Однокомандный operator flow

`1.0.2` добавляет operator wrapper поверх уже существующих updater transaction boundaries. Он не создаёт вторую реализацию updater и не ослабляет verification signature, backup, candidate или rollback.

Для обычного production path:

```bash
php bin/update_run.php --yes --json
```

Команда выполняет существующие фазы в следующем порядке:

```text
signed remote stage
  -> enter maintenance
  -> verified code + MySQL rollback backup
  -> verified external release candidate
  -> transactional live apply
  -> migrations + health/version/schema verification
  -> commit + maintenance release
```

Custom transaction ID и внешние roots можно передать явно:

```bash
php bin/update_run.php \
  --transaction=update-2026-001 \
  --stage-root=/var/lib/notes/update-staging \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --candidate-root=/var/lib/notes/update-releases \
  --yes --json
```

Wrapper автоматически снимает maintenance только если ошибка произошла **до** вызова live apply. После пересечения destructive boundary единственным владельцем rollback/recovery и снятия maintenance остаётся `UpdateApplyCommand`. Wrapper никогда принудительно не открывает writes после apply failure.

Recovery после crash/interruption использует тот же transaction journal:

```bash
php bin/update_run.php \
  --recover \
  --transaction=update-2026-001 \
  --yes --json
```

Существующие отдельные команды остаются поддерживаемыми для диагностики и контролируемой ручной эксплуатации.

## Retention cleanup recovery-artifacts

Проверенные rollback backups и внешние release candidates намеренно сохраняются после перехода transaction в terminal state. `1.0.2` добавляет отдельную retention command, чтобы крупные artifacts не росли без ограничений:

```bash
php bin/update_retention.php --json
```

По умолчанию выполняется preview. Стандартная policy рассматривает terminal artifacts старше 30 дней и всегда сохраняет две самые новые terminal transactions. При необходимости значения задаются явно:

```bash
php bin/update_retention.php --older-than-days=60 --keep=3 --json
```

Для удаления обязательны оба destructive flags:

```bash
php bin/update_retention.php --apply --yes --older-than-days=30 --keep=2 --json
```

Контракт безопасности:

- подходят только journals в terminal states `committed` или `rollback_verified`;
- `rollback_failed` и все incomplete/pre-mutation/live-mutation recovery states никогда не удаляются retention-механизмом;
- transaction journals сохраняются как лёгкие historical evidence;
- signed staged packages сохраняются; retention удаляет только transaction rollback-backup directories и внешние release candidates;
- candidate, на который ссылается любая retained/non-terminal transaction, защищён;
- corrupt journals или unsafe/out-of-root artifact paths приводят destructive cleanup к fail-closed;
- destructive cleanup запрещён, пока updater maintenance активен;
- dry-run не требует `--yes` и ничего не меняет.

Команда использует общий updater transaction lock во время планирования/удаления journal-owned artifacts, поэтому journal state не может измениться под уже принятым retention decision.

## Maintenance mode updater

Maintenance updater хранится в файле и намеренно независим от MySQL. Его marker должен располагаться вне application tree, чтобы оставаться доступным во время database migrations или замены кода.

Рекомендуемая production configuration:

```dotenv
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
UPDATE_STATE_PATH=/var/lib/notes/update-state
UPDATE_BACKUP_PATH=/var/lib/notes/update-backups
UPDATE_RELEASE_PATH=/var/lib/notes/update-releases
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
```

Если explicit updater state/storage paths не заданы, компоненты updater используют безопасные subdirectories внутри `PRIVATE_STORAGE_PATH`, где это поддерживается.

Operator CLI:

```bash
php bin/maintenance.php --action=status
php bin/maintenance.php --action=enter --transaction=update-2026-001 --reason='Обновление приложения'
php bin/maintenance.php --action=leave --transaction=update-2026-001
```

Валидная transaction владеет marker. Concurrent/другая transaction не может заменить это ownership. Переходы `enter`/`leave` сериализуются filesystem lock.

Если marker повреждён, runtime работает fail-closed и считает maintenance активным. Recovery выполняется явно:

```bash
php bin/maintenance.php --action=leave --force
```

Пока maintenance активен:

- `index.php` возвращает HTTP `503 Service Unavailable` с `Retry-After` **до bootstrap базы данных/модулей**;
- invalid/corrupt marker также возвращает 503, а не молча разрешает writes;
- уже открытые Messenger WebSocket connections не могут выполнять mutating actions, потому что общая runtime mutation policy повторно проверяет maintenance state;
- оператор может выполнить recovery через CLI даже при недоступности HTTP или MySQL.

Ранний HTTP gate сделан намеренно. Не переносите enforcement maintenance исключительно в обычный router middleware: это слишком поздно, если база данных недоступна во время обновления.

## Transaction journal и проверенный rollback backup

До live-code switch или migrations updater transaction должна владеть maintenance mode и быть привязана к одному уже verified staged artifact. Journal хранится вне live application tree и MySQL в `UPDATE_STATE_PATH/transactions`.

Journal записывает immutable transaction identity:

- transaction ID;
- installed и target version/version code;
- signed package SHA-256;
- verified stage directory;
- transaction state/history;
- факт начала любой live mutation;
- hashes и locations rollback artifacts и verified release candidate.

Один transaction ID нельзя незаметно перепривязать к другому package, stage, backup или candidate. Записи journal сериализуются и заменяются атомарно.

Создавайте rollback checkpoint только после того, как та же transaction вошла в maintenance:

```bash
php bin/update_backup.php \
  --transaction=update-2026-001 \
  --stage-dir=/var/lib/notes/update-staging/<verified-stage>
```

Опциональные `--state-root` и `--backup-root` переопределяют `UPDATE_STATE_PATH` и `UPDATE_BACKUP_PATH`.

`bin/update_backup.php` повторно проверяет staged manifest/signature, installed/target compatibility, package SHA-256 и ZIP structure до изменения backup state. Затем он создаёт два rollback artifacts во внешней temporary directory и атомарно публикует их только после verification:

1. **Snapshot кода.** Runtime/release files копируются вместе с per-file SHA-256, size и mode metadata. Snapshot намеренно исключает `.env`, cache/compile, uploads, private storage и другие mutable paths, чтобы rollback не мог перезаписать secrets или user-owned files.
2. **MySQL dump.** Dump создаётся через `mysqli` внутри `START TRANSACTION WITH CONSISTENT SNAPSHOT`, поэтому shared-hosting deployments не зависят от binary `mysqldump`. Сохраняются каждая base table, данные и triggers. Backup работает fail-closed при наличии неподдерживаемых views/routines/events или non-InnoDB tables, потому что такой snapshot не был бы полным/consistent rollback artifact.

Верхнеуровневый `backup.json`, code manifest и SQL dump проверяются по hash. Повтор того же backup transaction идемпотентен только пока существующие artifacts проходят byte-for-byte verification.

Успешный backup checkpoint оставляет maintenance активным и journal state в `backup_verified` с `live_mutation_started=false`.

## Проверенный внешний release candidate

Verified ZIP никогда не извлекается в live application tree. После `backup_verified` соберите release candidate вне application root:

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001 \
  --candidate-root=/var/lib/notes/update-releases
```

Команда повторно проверяет ту же transaction/staged signed package и извлекает данные во внешнюю candidate directory. Каждый extracted file проверяется по ZIP CRC/size, неподдерживаемые filesystem entries отклоняются, target `core/Version.php` валидируется, затем записывается tree manifest `.workspace-release-tree.json` с SHA-256. Создание candidate не переключает live code и не запускает migrations.

## Transactional live apply и автоматический rollback

Применяйте ранее verified candidate только пока та же transaction продолжает владеть maintenance:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --candidate-dir=/var/lib/notes/update-releases/<candidate> \
  --apply
```

Непосредственно перед live mutation команда:

1. повторно проверяет rollback backup и candidate tree;
2. подтверждает, что installed version всё ещё совпадает с checkpoint;
3. запускает pre-update `bin/healthcheck.php --json`;
4. запускает candidate `bin/migrate.php --dry-run`, включая migration checksum/schema validation;
5. ещё раз проверяет backup/candidate;
6. записывает текущее running state WebSocket;
7. записывает `preflight_verified`, затем надёжно фиксирует `live_mutation_started=true`.

Live tree не перезаписывается file-by-file, а updater никогда не распаковывает ZIP поверх него. Поскольку текущая installation не использует release-symlink layout, updater готовит release-owned top-level entries в приватной sibling directory на том же filesystem и активирует их контролируемыми операциями `rename()`. `.env` и настроенное mutable storage остаются на месте. Unsafe mutable paths внутри release-owned top-level directory заставляют apply завершиться до mutation.

После code switch updater выполняет migrations, live healthcheck, verification exact target-version и `bin/migrate.php --status`. Если native WebSocket работал до apply, он перезапускается и проверяется до перехода transaction в `committed`. Maintenance снимается только после verification committed installation.

Любая ошибка после `live_mutation_started` и до `committed` автоматически восстанавливает код из verified snapshot, MySQL из verified consistent dump, проверяет exact pre-update version, healthcheck и migration status и только затем записывает `rollback_verified` и снимает maintenance.

Если rollback нельзя подтвердить, journal state по возможности становится `rollback_failed`, а maintenance остаётся активным. Recovery artifacts сохраняются.

Recovery после crash/process death выполняется явно и учитывает фазу:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover
```

Recovery продолжается из durable journal (`rollback_started`, `code_restored`, `database_restored`, `rollback_failed`, `rollback_verified` или `committed`), а не предполагает, что исходный PHP process остался жив. Ошибка удаления maintenance marker после `committed` или `rollback_verified` никогда не превращает verified terminal state в destructive rollback.

Подробное руководство оператора и state machine находится в `docs/UPDATER_LIVE_APPLY.md`.

## Текущая граница updater

Signed-update stack сейчас обеспечивает:

- verification signed update и разделение trust roots;
- validation compatibility/package hash;
- ZIP safety audit без извлечения;
- discovery подписанного feed через public HTTPS с read-only status classification;
- загрузку exact signed package с ограничениями DNS/TLS/HTTP framing;
- administrator read-only signed-feed check и superadmin immutable staging UI;
- внешний immutable staging для local или remote ingress;
- DB-independent ownership/recovery maintenance;
- внешний transaction journal;
- verified checkpoint rollback кода + MySQL;
- verified extraction/tree manifest внешнего release candidate;
- pre-healthcheck и migration dry-run/checksum gate;
- controlled live code switch с сохранением installation/mutable state;
- выполнение migrations внутри той же transaction;
- exact-version/post-health/schema verification;
- verification WebSocket restart, когда updater владеет его lifecycle;
- автоматический rollback кода/MySQL;
- crash-resumable recovery с fail-closed maintenance.

Он всё ещё **не**:

- выполняет установку без явного действия суперадминистратора: обновление намеренно требует одного подтверждающего нажатия;
- создаёт production private keys лицензий/обновлений — production key ceremony намеренно остаётся отдельной процедурой;
- автоматически удаляет старые scratch/network-ingress temporary artifacts, не принадлежащие journal;
- заменяет собственную drain/restart policy внешнего process supervisor;
- отменяет необходимость финального реального Beta4 -> 1.0 upgrade/rollback release drill.

Эти пункты относятся к release-delivery/operations. Они не должны ослаблять signed transaction или вводить прямой shortcut «распаковать поверх live».

## Ротация ключей

Используйте перекрывающиеся public trust roots:

1. release A доверяет старому key;
2. release B доверяет старому + новому keys;
3. последующие updates подписываются новым private key;
4. после завершения поддерживаемого upgrade window более поздний release может удалить старый public key.

Слишком раннее удаление старого public key может оставить installations, которые ещё не прошли rotation release, без возможности обновления.
