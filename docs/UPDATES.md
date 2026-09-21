# Подписанные обновления

Workspace Organizer 1.0 использует криптографически подписанный pipeline обновлений. Подпись обновлений намеренно отделена от лицензирования установки: **license keys не могут разрешать обновление кода, а update keys не могут выпускать лицензии**.

## Модель безопасности

Обновление состоит из трёх артефактов:

1. hosting ZIP package;
2. JSON manifest обновления;
3. detached Ed25519 signature token для точных bytes manifest.

Формат signature token:

```text
wou1.<key-id>.<base64url-ed25519-signature>
```

Подпись покрывает точные bytes:

```text
WorkspaceOrganizerUpdateManifest/v1\n<manifest bytes>
```

Такое разделение trust domains намеренно. Никогда не используйте production license-signing key как production update-signing key.

Runtime-установки содержат только **public** update keys в:

```text
config/update_trusted_keys.php
```

Если trust registry пуст, `bin/update.php` и `bin/update_remote.php` завершаются fail-closed, а signed updating отключён. Production release должен содержать только заранее одобренные public update keys; private signing key никогда не входит в установку.

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

Подпись защищает version, compatibility floor, source commit и hash/size/name package. Action paths отклоняют package той же версии или downgrade; read-only remote checks могут сообщать такие signed states без скачивания bytes package.

## Production-церемония update key

Проводите её только на контролируемой/офлайн vendor machine.

1. Создайте вне репозитория directory для signing secrets.
2. Создайте отдельный update keypair:

```bash
php tools/vendor-update/keygen.php \
  --key-id=update-prod-2026-01 \
  --private-out=/secure/offline/workspace-update-prod-2026-01.update-secret
```

3. Храните private key в vendor secret storage. Он никогда не должен попадать в GitHub source, Actions secrets/artifacts, на customer server, в support archive, `.env`, database settings или release ZIP.
4. Добавьте **только** выведенную public key entry в `config/update_trusted_keys.php` отдельным проверенным PR.
5. Перед поставкой этого trust root запустите полный release CI.

Private key file использует отдельный update-key format; tooling отказывается создавать/читать его внутри repository tree. На Unix он не должен быть доступен group/other.

## Сборка и подпись release metadata

GitHub workflow `Build hosting package` только собирает ZIP release candidate и записывает его SHA-256 как Actions artifact. Он намеренно не создаёт и не обновляет public GitHub Release. Публикация выполняется только после того, как exact ZIP использован для сборки и офлайн подписи production update manifest.

После появления финального hosting ZIP проверьте записанный SHA-256 и соберите manifest именно из этого файла:

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

Изменение хотя бы одного byte manifest после подписи делает signature недействительной.

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

- trusted update key id;
- Ed25519 signature точных bytes manifest;
- поля schema/product manifest;
- корректность issue time;
- что target новее установленного `VERSION_CODE`;
- что installed version удовлетворяет `min_source_version_code`;
- что локальный PHP удовлетворяет `requires_php`;
- filename, byte size и SHA-256 package;
- согласованность ZIP central-directory и local-header;
- безопасные relative UTF-8 paths entries;
- отсутствие path traversal, absolute/backslash/colon paths и NUL;
- отсутствие symlink/special Unix entries;
- отсутствие encrypted, multi-disk, ZIP64 или data-descriptor entries в поддерживаемом update subset;
- только поддерживаемые compression methods;
- отсутствие duplicate/case-colliding paths;
- limits per-file/total uncompressed-size и compression-ratio;
- отсутствие пересекающихся local entry payload regions.

Structural audit реализован на чистом PHP и **не** распаковывает archive. Принимаются только явные local regular files, а не URLs или symlinks.

## Внешний проверенный staging

Чтобы поместить проверенное обновление в stage:

```bash
php bin/update.php \
  --manifest=/path/update.json \
  --signature=/path/update.sig \
  --package=/path/package.zip \
  --stage-root=/absolute/path/outside/application
```

Если `--stage-root` не указан, используется `UPDATE_STAGING_PATH`; иначе updater использует fallback `<PRIVATE_STORAGE_PATH>/updates`.

Staging root должен находиться вне live application tree. Updater:

1. проверяет signature, compatibility, SHA-256 package и ZIP structure;
2. блокирует staging root от concurrent staging;
3. копирует данные в случайный временный stage directory;
4. повторно проверяет SHA-256 после копирования;
5. сохраняет точные manifest/signature и stage metadata;
6. атомарно переименовывает temporary directory в финальное immutable stage name.

Повтор того же signed artifact идемпотентен и повторно проверяет существующие staged files.

## Удалённая доставка подписанного обновления

Remote delivery — это network-ingress слой перед тем же immutable staging contract. Он **не** включает maintenance, не создаёт updater transaction, не распаковывает package и не изменяет live files.

Рекомендуемая configuration:

```dotenv
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
```

Проверить signed feed без скачивания ZIP:

```bash
php bin/update_remote.php --check-only --json
```

Успешный read-only check классифицирует signed feed как `update_available`, `up_to_date`, `ahead_of_feed` или `update_incompatible`. Во всех четырёх states остаются `package_downloaded=false` и `live_files_changed=false`.

Скачать, проверить, выполнить audit и положить installable update в stage:

```bash
php bin/update_remote.php --json
```

Сам feed является discovery metadata, а не trust root. Он указывает только leaf filenames manifest/signature в том же directory. Точные bytes manifest обязаны пройти Ed25519 verification; после этого проверенный manifest задаёт filename package, byte size и SHA-256. Неподписанный pointer package в feed не имеет никаких полномочий.

Vendor-free HTTPS transport требует `openssl` и fail-closed отклоняет обычный HTTP, literal/private/reserved network targets, порты не 443, redirects, transfer-encoded responses, non-identity content encoding, неоднозначный/отсутствующий `Content-Length` и ошибки TLS peer/certificate verification. DNS сначала разрешается; принимается только public address, который затем закрепляется за TLS socket, а certificate verification продолжает использовать настроенный DNS host.

Package скачивается потоково во временный private external directory и дополнительно ограничен 512 MiB помимо signed size contract. Transport требует равенства HTTP `Content-Length` подписанному size и рассчитывает SHA-256 во время скачивания. Затем снова запускаются существующий local package verifier и ZIP inspector, после чего работает обычный immutable `UpdatePackageStager`; временные network ingress bytes удаляются.

Package никогда не скачивается при `--check-only`, если более новое signed update несовместимо или если action compatibility gate отклоняет same-version/downgrade/source-floor/runtime conditions.

Подробности network, publishing и failure boundaries находятся в `docs/UPDATE_REMOTE_DELIVERY.md`.

## Проверка администратором и staging UI

Administrator UI предоставляет те же неразрушительные primitives remote delivery по `/admin/updates`, не создавая вторую реализацию updater.

Модель доступа:

- page и signed-feed check требуют `admin.settings.manage`;
- check — GET/read-only операция и не скачивает bytes package;
- staging package — POST + CSRF и дополнительно требует role `superadmin`;
- global license mutation guard продолжает применяться к staging;
- feed URL и channel задаются только server configuration и никогда не принимаются из browser input.

UI показывает installed version, configured channel/feed label, readiness trust-root/runtime и результат signed check (`update_available`, `up_to_date`, `ahead_of_feed`, `update_incompatible`). Release notes и package metadata поступают только из verified manifest и экранируются перед rendering.

Когда доступно compatible update, superadmin может явно скачать, повторно проверить, выполнить ZIP audit и опубликовать package в immutable external staging. Controller сохраняет в session только безопасный summary: target version, signing key id, package hash и archive counts. Absolute staging path намеренно не сохраняется в web state и не отображается.

Этот первый UI layer останавливается на staging. В нём нет browser action для входа в maintenance, создания transaction journal, rollback backup, извлечения release candidate, migrations, live code switch, apply или recovery. Эти destructive operations остаются CLI/operator transaction boundaries до появления отдельно проверенного browser transaction flow.

## Диагностика готовности updater

Перед проверкой или применением update запустите read-only readiness doctor:

```bash
php bin/update_doctor.php --json
```

Он не выполняет network request и не вносит mutations. Проверяются local trust registry, обязательные PHP extensions, `proc_open`, configuration HTTPS feed/channel, shape online credentials при их включении, external staging/state/backup/release paths и DB configuration, необходимая для rollback backup.

Страница Admin Updates показывает тот же local operator-readiness summary, не раскрывая содержимое private credentials и absolute staged-package paths.

## Первый переход 1.0.1 -> 1.0.2

Опубликованная `1.0.1` уже содержит signed transaction/apply/bootstrap runtime, но предшествует convenience wrapper `bin/update_run.php`, readiness doctor и retention command из `1.0.2`. **Не** копируйте отдельные новые PHP-файлы updater внутрь live tree 1.0.1.

Поддерживаемый первый переход использует существующую границу **trusted external bootstrap**:

1. Получите официальный hosting ZIP 1.0.2, `update.json`, `update.sig` и опубликованный SHA-256 через release channel.
2. Проверьте checksum release ZIP до использования его как source bootstrap runner.
3. Распакуйте trusted bundle 1.0.2 во временный directory, **отдельный от live application tree 1.0.1**. Bundle содержит только public update trust root; private signing key отсутствует.
4. Сохраните signed ZIP/manifest/signature 1.0.2 как local files и запустите bootstrap из временного дерева 1.0.2 против точного live source:

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

Bootstrap проверяет exact installed source version, signed manifest/package, создаёт rollback artifacts, строит verified external candidate, транзакционно переключает code, запускает migrations/health checks и автоматически откатывает code + database, если post-switch verification завершается ошибкой. Directory runner никогда не должен пересекаться с live application tree.

После успешного перехода на 1.0.2 дальнейшие обновления используют установленный operator wrapper:

```bash
php bin/update_run.php --yes --json
```

Release CI содержит exact drill `v1.0.1` → synthetic signed `1.0.2` для success/rollback, закреплённый за опубликованным commit 1.0.1. Production release acceptance всё равно повторяет переход уже с финальными production-signed artifacts 1.0.2.

## Единая операторская команда

`1.0.2` добавляет operator wrapper поверх уже существующих transaction boundaries updater. Он не создаёт вторую реализацию updater и не ослабляет verification signature, backup, candidate или rollback.

Для обычного production path:

```bash
php bin/update_run.php --yes --json
```

Команда выполняет существующие phases по порядку:

```text
signed remote stage
  -> enter maintenance
  -> verified code + MySQL rollback backup
  -> verified external release candidate
  -> transactional live apply
  -> migrations + health/version/schema verification
  -> commit + maintenance release
```

Custom transaction id и external roots можно передать явно:

```bash
php bin/update_run.php \
  --transaction=update-2026-001 \
  --stage-root=/var/lib/notes/update-staging \
  --state-root=/var/lib/notes/update-state \
  --backup-root=/var/lib/notes/update-backups \
  --candidate-root=/var/lib/notes/update-releases \
  --yes --json
```

Wrapper автоматически снимает maintenance только если ошибка случилась **до** вызова live apply. После пересечения destructive boundary единственным владельцем rollback/recovery и снятия maintenance остаётся `UpdateApplyCommand`. Wrapper никогда принудительно не открывает writes после apply failure.

Recovery после crash/interruption использует тот же transaction journal:

```bash
php bin/update_run.php \
  --recover \
  --transaction=update-2026-001 \
  --yes --json
```

Существующие отдельные commands остаются поддерживаемыми для diagnostics и controlled manual operation.

## Retention recovery artifacts

Verified rollback backups и external release candidates намеренно сохраняются после достижения transaction terminal state. `1.0.2` добавляет отдельную retention command, чтобы большие artifacts не росли бесконечно:

```bash
php bin/update_retention.php --json
```

По умолчанию выполняется preview. Default policy рассматривает terminal artifacts старше 30 дней и всегда сохраняет две новейшие terminal transactions. При необходимости значения задаются явно:

```bash
php bin/update_retention.php --older-than-days=60 --keep=3 --json
```

Удаление требует оба destructive flags:

```bash
php bin/update_retention.php --apply --yes --older-than-days=30 --keep=2 --json
```

Контракт безопасности:

- eligible только journals в terminal states `committed` или `rollback_verified`;
- `rollback_failed` и любые incomplete/pre-mutation/live-mutation recovery states retention никогда не удаляет;
- transaction journals сохраняются как лёгкое historical evidence;
- signed staged packages сохраняются; retention удаляет только directories transaction rollback backup и external release candidates;
- candidate, на который всё ещё ссылается retained/non-terminal transaction, защищён;
- corrupt journals или unsafe/out-of-root artifact paths переводят destructive cleanup в fail-closed;
- cleanup отказывается работать destructively при активном updater maintenance;
- dry-run не требует `--yes` и ничего не изменяет.

Command использует тот же updater transaction lock на время planning/deletion journal-owned artifacts, поэтому journal state не может измениться во время retention decision.

## Maintenance mode updater

Maintenance updater хранится в file-backed state и намеренно не зависит от MySQL. Marker должен находиться вне application tree, чтобы оставаться доступным во время database migrations или code replacement.

Рекомендуемая production configuration:

```dotenv
UPDATE_STAGING_PATH=/var/lib/notes/update-staging
UPDATE_STATE_PATH=/var/lib/notes/update-state
UPDATE_BACKUP_PATH=/var/lib/notes/update-backups
UPDATE_RELEASE_PATH=/var/lib/notes/update-releases
UPDATE_FEED_URL=https://updates.example.com/workspace-organizer/stable/feed.json
UPDATE_CHANNEL=stable
```

Если явные updater state/storage paths не заданы, components updater используют безопасные subdirectories под `PRIVATE_STORAGE_PATH`, где это поддерживается.

Operator CLI:

```bash
php bin/maintenance.php --action=status
php bin/maintenance.php --action=enter --transaction=update-2026-001 --reason='Обновление приложения'
php bin/maintenance.php --action=leave --transaction=update-2026-001
```

Валидная transaction владеет marker. Concurrent/different transactions не могут заменить это ownership. Transitions `enter`/`leave` сериализуются filesystem lock.

Если marker повреждён, runtime работает fail-closed и считает maintenance активным. Recovery выполняется явно:

```bash
php bin/maintenance.php --action=leave --force
```

Пока maintenance активен:

- `index.php` возвращает HTTP `503 Service Unavailable` с `Retry-After` **до database/module bootstrap**;
- invalid/corrupt marker также возвращает 503 вместо тихого повторного открытия writes;
- уже открытые Messenger WebSocket connections не могут выполнять mutating actions, потому что общая runtime mutation policy повторно проверяет maintenance state;
- operator может выполнить recovery через CLI даже при недоступности HTTP или MySQL.

Ранний HTTP gate намеренный. Не переносите enforcement maintenance исключительно в обычный router middleware: при недоступной database во время update это будет слишком поздно.

## Transaction journal и проверенный rollback backup

До live-code switch или migrations updater transaction должна владеть maintenance mode и быть привязана к одному уже verified staged artifact. Journal хранится вне live application tree и MySQL под `UPDATE_STATE_PATH/transactions`.

Journal записывает immutable identity transaction:

- transaction id;
- installed и target version/version code;
- SHA-256 signed package;
- verified stage directory;
- transaction state/history;
- началась ли live mutation;
- hashes и locations rollback artifacts и verified release candidate.

Один transaction id нельзя незаметно перепривязать к другому package, stage, backup или candidate. Journal writes сериализуются и заменяются атомарно.

Создавайте rollback checkpoint только после того, как эта же transaction вошла в maintenance:

```bash
php bin/update_backup.php \
  --transaction=update-2026-001 \
  --stage-dir=/var/lib/notes/update-staging/<verified-stage>
```

Дополнительные `--state-root` и `--backup-root` переопределяют `UPDATE_STATE_PATH` и `UPDATE_BACKUP_PATH`.

`bin/update_backup.php` повторно проверяет staged manifest/signature, installed/target compatibility, SHA-256 package и ZIP structure до изменения backup state. Затем он создаёт два rollback artifacts во внешнем temporary directory и атомарно публикует их только после verification:

1. **Code snapshot.** Runtime/release files копируются с per-file SHA-256, size и mode metadata. Snapshot намеренно исключает `.env`, cache/compile, uploads, private storage и другие mutable paths, поэтому rollback не может перезаписать secrets или user-owned files.
2. **MySQL dump.** Dump создаётся через `mysqli` внутри `START TRANSACTION WITH CONSISTENT SNAPSHOT`, поэтому shared-hosting deployment не зависит от binary `mysqldump`. Каждая base table сохраняется вместе с data и triggers. Backup завершается fail-closed при unsupported views/routines/events или non-InnoDB tables, потому что такой snapshot не был бы полным/consistent rollback artifact.

Top-level `backup.json`, code manifest и SQL dump проходят hash verification. Повтор same backup transaction идемпотентен только пока существующие artifacts продолжают проверяться byte-for-byte.

Успешный backup checkpoint оставляет maintenance активным и journal state `backup_verified` с `live_mutation_started=false`.

## Проверенный внешний release candidate

Verified ZIP никогда не распаковывается в live application tree. После `backup_verified` создайте release candidate вне application root:

```bash
php bin/update_candidate.php \
  --transaction=update-2026-001 \
  --candidate-root=/var/lib/notes/update-releases
```

Command повторно проверяет ту же transaction/staged signed package и распаковывает её во внешний candidate directory. Каждый extracted file сверяется с ZIP CRC/size, unsupported filesystem entries отклоняются, target `core/Version.php` валидируется, затем записывается SHA-256 tree manifest `.workspace-release-tree.json`. Создание candidate не переключает live code и не запускает migrations.

## Транзакционное live apply и автоматический rollback

Применяйте ранее verified candidate только пока та же transaction владеет maintenance:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --candidate-dir=/var/lib/notes/update-releases/<candidate> \
  --apply
```

Непосредственно перед live mutation command:

1. повторно проверяет rollback backup и candidate tree;
2. подтверждает, что installed version всё ещё соответствует checkpoint;
3. запускает pre-update `bin/healthcheck.php --json`;
4. запускает candidate `bin/migrate.php --dry-run`, включая validation migration checksum/schema;
5. ещё раз проверяет backup/candidate;
6. фиксирует running state WebSocket;
7. записывает `preflight_verified`, затем надёжно `live_mutation_started=true`.

Live tree не перезаписывается file-by-file, updater никогда не распаковывает ZIP поверх него. Поскольку текущая installation не использует release-symlink layout, updater готовит release-owned top-level entries в private sibling directory на том же filesystem и активирует их controlled operations `rename()`. `.env` и configured mutable storage остаются на месте. Unsafe mutable paths, вложенные под release-owned top-level directory, приводят к отказу apply до mutation.

После code switch updater запускает migrations, live healthcheck, exact target-version verification и `bin/migrate.php --status`. Если native WebSocket работал до apply, он перезапускается и проверяется до достижения transaction состояния `committed`. Maintenance снимается только после verification committed installation.

Любая ошибка после `live_mutation_started`, но до `committed`, автоматически восстанавливает code из verified snapshot и MySQL из verified consistent dump, проверяет exact pre-update version, healthcheck и migration status, только затем записывает `rollback_verified` и снимает maintenance.

Если rollback не удаётся проверить, journal state по возможности становится `rollback_failed`, а maintenance остаётся активным. Recovery artifacts сохраняются.

Recovery после crash/process death явный и phase-aware:

```bash
php bin/update_apply.php \
  --transaction=update-2026-001 \
  --recover
```

Recovery продолжает работу по durable journal (`rollback_started`, `code_restored`, `database_restored`, `rollback_failed`, `rollback_verified` или `committed`) и не предполагает, что исходный PHP process выжил. Ошибка удаления maintenance marker после `committed` или `rollback_verified` никогда не превращает verified terminal state в destructive rollback.

Подробное руководство для operator и state machine находится в `docs/UPDATER_LIVE_APPLY.md`.

## Текущая граница updater

Signed-update stack сейчас предоставляет:

- verification signed update и разделение trust roots;
- validation compatibility/hash package;
- ZIP safety audit без распаковки;
- discovery public-HTTPS signed feed с read-only классификацией status;
- скачивание exact signed package с ограничениями DNS/TLS/HTTP framing;
- administrator read-only signed-feed check и superadmin immutable staging UI;
- external immutable staging для local или remote ingress;
- DB-independent maintenance ownership/recovery;
- внешний transaction journal;
- verified code + MySQL rollback checkpoint;
- verified external release-candidate extraction/tree manifest;
- pre-healthcheck и migration dry-run/checksum gate;
- controlled live code switch с сохранением installation/mutable state;
- выполнение migrations в той же transaction;
- exact-version/post-health/schema verification;
- verification restart WebSocket, когда updater владеет этим lifecycle;
- automatic code/MySQL rollback;
- crash-resumable recovery с fail-closed maintenance.

Он по-прежнему **не**:

- предоставляет destructive maintenance/backup/candidate/apply/recovery operations в administrator UI;
- хранит или распространяет production private keys лицензий/обновлений: их custody и использование остаются отдельной офлайн operator boundary;
- автоматически удаляет старые scratch/network-ingress temporary artifacts, не принадлежащие journal;
- заменяет собственную drain/restart policy внешнего process supervisor;
- отменяет необходимость финального real production-signed upgrade/rollback drill для выпуска.

Оставшиеся пункты относятся к release-delivery/operations. Они не должны ослаблять signed transaction и не могут вводить прямой shortcut «unzip over live».

## Ротация ключей

Используйте перекрывающиеся public trust roots:

1. release A доверяет старому key;
2. release B доверяет старому + новому keys;
3. следующие updates подписываются новым private key;
4. после поддерживаемого upgrade window поздний release может удалить старый public key.

Слишком раннее удаление старого public key может оставить installations, ещё не перешедшие через rotation release, без доступного upgrade path.
